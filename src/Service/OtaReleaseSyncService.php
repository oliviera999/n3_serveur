<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OtaReleaseRepository;

/**
 * Détecte les PUBLICATIONS OTA en relisant les manifestes `ota/<cible>/metadata.json` et alimente
 * `ota_releases` (cible, canal, carte, version, empreinte, taille, horodatage, auteur).
 *
 * Appelé par le CRON (chaque passage : lecture de quelques fichiers, insertion seulement si une
 * combinaison version/empreinte est nouvelle) — donc la détection suit le déploiement
 * (`git pull` chaque minute) à la minute près. L'horodatage retenu est la date de modification
 * du manifeste ; l'auteur, si `git` est disponible sur l'hébergement, est celui du dernier commit
 * qui a touché le manifeste (sinon null — on ne devine rien).
 *
 * Deux topologies de manifeste :
 *  - ffp5cs (`ota/metadata.json`) : `{version, channels: {prod: {esp32-wroom: {...}, esp32-s3: {...}}, test: {...}}}`
 *    → cible `ffp5`, canal `prod` / `test`, carte = clé ;
 *  - n3pp / msp / cam (`ota/<cible>/metadata.json`) : `{version, sha256, url}` ou, pour cam,
 *    `{msp1: {...}, n3pp: {...}, ffp3: {...}}` → cible = dossier, canal = dossier, carte = clé ou `-`.
 */
class OtaReleaseSyncService
{
    /** Cibles « n3ota » à sous-dossier propre (manifeste + binaire dans `ota/<cible>/`). */
    private const SUBDIR_TARGETS = ['n3pp', 'n3pp-test', 'msp', 'msp-test', 'cam', 'n3pp-universal', 'n3pp-universal-test', 'msp-universal', 'msp-universal-test'];

    public function __construct(
        private OtaReleaseRepository $repo,
        private LogService $logger,
        private string $otaDir,
        private bool $useGit = true,
    ) {
    }

    /**
     * Relit tous les manifestes et enregistre les publications nouvelles.
     *
     * @return list<array<string, mixed>> Publications AJOUTÉES lors de ce passage
     */
    public function sync(string $source = 'cron'): array
    {
        $added = [];
        foreach ($this->discover() as $release) {
            $release['source'] = $source;
            try {
                if ($this->repo->recordIfNew($release)) {
                    $added[] = $release;
                    $this->logger->info('[ota] publication détectée', [
                        'target' => $release['target'],
                        'channel' => $release['channel'],
                        'board' => $release['board'],
                        'version' => $release['version'],
                        'published_at' => $release['published_at'],
                        'author' => $release['author'],
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('[ota] enregistrement de publication impossible', ['error' => $e->getMessage()]);
            }
        }

        return $added;
    }

    /**
     * Publications décrites par les manifestes présents sur le disque (sans écriture).
     *
     * @return list<array{target: string, channel: string, board: string, version: string, hash: ?string, size: ?int, bin_url: ?string, published_at: string, author: ?string}>
     */
    public function discover(): array
    {
        $out = [];
        $root = rtrim($this->otaDir, '/\\');

        $rootManifest = $root . '/metadata.json';
        if (is_file($rootManifest)) {
            $out = array_merge($out, $this->parseFfp5Manifest($rootManifest));
        }
        foreach (self::SUBDIR_TARGETS as $target) {
            $manifest = $root . '/' . $target . '/metadata.json';
            if (is_file($manifest)) {
                $out = array_merge($out, $this->parseTargetManifest($target, $manifest));
            }
        }

        return $out;
    }

    /**
     * @return list<array{target: string, channel: string, board: string, version: string, hash: ?string, size: ?int, bin_url: ?string, published_at: string, author: ?string}>
     */
    private function parseFfp5Manifest(string $file): array
    {
        $doc = $this->readJson($file);
        if ($doc === null) {
            return [];
        }
        $meta = $this->fileMeta($file);
        $out = [];
        $channels = isset($doc['channels']) && is_array($doc['channels']) ? $doc['channels'] : [];
        foreach ($channels as $channel => $boards) {
            if (!is_array($boards)) {
                continue;
            }
            foreach ($boards as $board => $entry) {
                if (!is_array($entry) || !isset($entry['version'])) {
                    continue;
                }
                $out[] = [
                    'target' => 'ffp5',
                    'channel' => (string) $channel,
                    'board' => (string) $board,
                    'version' => (string) $entry['version'],
                    'hash' => isset($entry['sha256']) ? (string) $entry['sha256'] : (isset($entry['md5']) ? (string) $entry['md5'] : null),
                    'size' => isset($entry['size']) && is_numeric($entry['size']) ? (int) $entry['size'] : null,
                    'bin_url' => isset($entry['bin_url']) ? (string) $entry['bin_url'] : null,
                ] + $meta;
            }
        }
        // Manifeste sans bloc channels (ancien format) : une seule entrée.
        if ($channels === [] && isset($doc['version'])) {
            $out[] = [
                'target' => 'ffp5',
                'channel' => 'prod',
                'board' => 'esp32-wroom',
                'version' => (string) $doc['version'],
                'hash' => isset($doc['md5']) ? (string) $doc['md5'] : null,
                'size' => isset($doc['size']) && is_numeric($doc['size']) ? (int) $doc['size'] : null,
                'bin_url' => isset($doc['bin_url']) ? (string) $doc['bin_url'] : null,
            ] + $meta;
        }

        return $out;
    }

    /**
     * @return list<array{target: string, channel: string, board: string, version: string, hash: ?string, size: ?int, bin_url: ?string, published_at: string, author: ?string}>
     */
    private function parseTargetManifest(string $target, string $file): array
    {
        $doc = $this->readJson($file);
        if ($doc === null) {
            return [];
        }
        $meta = $this->fileMeta($file);
        if (isset($doc['version'])) {
            return [[
                'target' => $target,
                'channel' => $target,
                'board' => '-',
                'version' => (string) $doc['version'],
                'hash' => isset($doc['sha256']) ? (string) $doc['sha256'] : (isset($doc['md5']) ? (string) $doc['md5'] : null),
                'size' => isset($doc['size']) && is_numeric($doc['size']) ? (int) $doc['size'] : $this->binSize(dirname($file) . '/firmware.bin'),
                'bin_url' => isset($doc['url']) ? (string) $doc['url'] : null,
            ] + $meta];
        }
        // Manifeste multi-cartes (cam : msp1 / n3pp / ffp3).
        $out = [];
        foreach ($doc as $board => $entry) {
            if (!is_array($entry) || !isset($entry['version'])) {
                continue;
            }
            $out[] = [
                'target' => $target,
                'channel' => $target,
                'board' => (string) $board,
                'version' => (string) $entry['version'],
                'hash' => isset($entry['sha256']) ? (string) $entry['sha256'] : (isset($entry['md5']) ? (string) $entry['md5'] : null),
                'size' => isset($entry['size']) && is_numeric($entry['size']) ? (int) $entry['size'] : $this->binSize(dirname($file) . '/' . $board . '/firmware.bin'),
                'bin_url' => isset($entry['url']) ? (string) $entry['url'] : null,
            ] + $meta;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $doc = json_decode($raw, true);

        return is_array($doc) ? $doc : null;
    }

    /**
     * @return array{published_at: string, author: ?string}
     */
    private function fileMeta(string $file): array
    {
        $mtime = @filemtime($file);

        return [
            'published_at' => date('Y-m-d H:i:s', $mtime === false ? time() : $mtime),
            'author' => $this->gitAuthor($file),
        ];
    }

    private function binSize(string $file): ?int
    {
        if (!is_file($file)) {
            return null;
        }
        $size = @filesize($file);

        return $size === false ? null : $size;
    }

    /**
     * Auteur du dernier commit ayant touché le manifeste, si `git` est utilisable ici ; null sinon
     * (hébergement sans git, dépôt absent, délai dépassé). Jamais d'exception.
     */
    private function gitAuthor(string $file): ?string
    {
        if (!$this->useGit || !function_exists('proc_open')) {
            return null;
        }
        $dir = dirname($file);
        $cmd = ['git', '-C', $dir, 'log', '-1', '--format=%an <%ae>', '--', basename($file)];
        try {
            $process = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) {
                return null;
            }
            $out = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($process);
            if ($code !== 0 || !is_string($out)) {
                return null;
            }
            $out = trim($out);

            return $out === '' ? null : $out;
        } catch (\Throwable) {
            return null;
        }
    }
}
