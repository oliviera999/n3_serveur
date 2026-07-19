<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Système de rollback OTA côté serveur (non bloquant).
 *
 * Le serveur ne fait que servir statiquement les artefacts OTA (cf.
 * {@see \App\Controller\Ffp3\OtaFileController}). Ce service ajoute une capacité
 * de **retour arrière contrôlé** : capturer l'état servi d'une cible (snapshot),
 * lister les versions disponibles, et restaurer une version antérieure connue
 * comme saine — indispensable AVANT d'activer l'enforcement de signature OTA
 * (`N3_OTA_REQUIRE_SIGNATURE`) sur la flotte, pour pouvoir récupérer sans re-flash
 * physique si une publication se révèle mauvaise.
 *
 * Portée : cibles « n3ota » à sous-dossier propre (n3pp, n3pp-test, msp, msp-test,
 * cam), où `metadata.json` + `firmware.bin` cohabitent dans le même dossier. La
 * cible ffp5cs (métadonnée `ota/metadata.json` racine + binaires dans des dossiers
 * frères `esp32-wroom*`/`esp32-s3*`) a une topologie différente et se gère via la
 * procédure Git documentée (voir `docs/OTA_ROLLBACK.md`) — volontairement hors de
 * l'automatisation ici pour rester simple et robuste.
 *
 * Défensif : aucune exception non typée ne remonte d'une entrée opérateur ;
 * labels assainis (anti path-traversal) ; écritures atomiques (tmp + rename) ;
 * snapshot automatique de l'état courant avant toute restauration.
 *
 * Inspiration : mécanisme de rollback natif ESP-IDF côté firmware
 * (esp_ota_mark_app_invalid_rollback_and_reboot, projet espressif/esp-idf,
 * Apache-2.0) — ce service en est le pendant serveur (repointer la métadonnée
 * vers un binaire antérieur), adapté aux conventions du dépôt.
 */
final class OtaRollbackService
{
    private const HISTORY_DIRNAME = 'history';
    private const METADATA_FILE = 'metadata.json';

    /**
     * Cibles gérées → sous-dossier relatif à la racine OTA.
     *
     * @var array<string, string>
     */
    private const TARGETS = [
        'n3pp' => 'n3pp',
        'n3pp-test' => 'n3pp-test',
        'msp' => 'msp',
        'msp-test' => 'msp-test',
        'cam' => 'cam',
    ];

    /** @param string $otaRoot Racine des artefacts OTA (ex. `serveur/ota`). */
    public function __construct(private string $otaRoot)
    {
    }

    /** @return list<string> Noms de cibles gérées. */
    public function targets(): array
    {
        return array_keys(self::TARGETS);
    }

    /**
     * Version actuellement servie (champ `version` de la métadonnée), si lisible.
     */
    public function currentLabel(string $target): ?string
    {
        $meta = $this->targetDir($target) . '/' . self::METADATA_FILE;
        if (!is_file($meta)) {
            return null;
        }
        $raw = file_get_contents($meta);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['version']) && is_string($decoded['version']) && $decoded['version'] !== '') {
            return $decoded['version'];
        }

        return null;
    }

    /**
     * Capture l'état servi d'une cible dans `history/<label>/`.
     *
     * @param string|null $label Étiquette de snapshot (défaut : version courante).
     * @return string Étiquette effectivement utilisée.
     */
    public function snapshot(string $target, ?string $label = null): string
    {
        $dir = $this->targetDir($target);
        if (!is_dir($dir)) {
            throw new \RuntimeException("Répertoire cible absent : {$dir}");
        }
        $label = $this->normalizeLabel($label ?? $this->currentLabel($target));
        $dest = $dir . '/' . self::HISTORY_DIRNAME . '/' . $label;
        foreach ($this->servableFilesIn($dir) as $rel) {
            $this->atomicCopy($dir . '/' . $rel, $dest . '/' . $rel);
        }

        return $label;
    }

    /**
     * Étiquettes de snapshots disponibles pour une cible (triées).
     *
     * @return list<string>
     */
    public function listSnapshots(string $target): array
    {
        $histDir = $this->targetDir($target) . '/' . self::HISTORY_DIRNAME;
        if (!is_dir($histDir)) {
            return [];
        }
        $entries = scandir($histDir);
        if ($entries === false) {
            return [];
        }
        $labels = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $candidate = $histDir . '/' . $entry;
            if (is_dir($candidate) && is_file($candidate . '/' . self::METADATA_FILE)) {
                $labels[] = $entry;
            }
        }
        sort($labels);

        return $labels;
    }

    /**
     * Restaure un snapshot vers l'état servi. Sauvegarde d'abord l'état courant
     * (auto-backup), puis copie atomiquement les fichiers du snapshot.
     *
     * @return list<string> Fichiers restaurés (chemins relatifs à la cible).
     */
    public function restore(string $target, string $label, bool $dryRun = false): array
    {
        $dir = $this->targetDir($target);
        $src = $dir . '/' . self::HISTORY_DIRNAME . '/' . $this->normalizeLabel($label);
        if (!is_dir($src) || !is_file($src . '/' . self::METADATA_FILE)) {
            throw new \RuntimeException("Snapshot introuvable : {$target}/{$label}");
        }
        $files = $this->servableFilesIn($src);
        if ($dryRun) {
            return $files;
        }
        // Auto-backup de l'état courant avant écrasement (récupérable si erreur).
        $current = $this->currentLabel($target);
        if ($current !== null && $this->normalizeLabel($current) !== $this->normalizeLabel($label)) {
            try {
                $this->snapshot($target, $current);
            } catch (\Throwable) {
                // Best-effort : ne bloque pas la restauration.
            }
        }
        foreach ($files as $rel) {
            $this->atomicCopy($src . '/' . $rel, $dir . '/' . $rel);
        }

        return $files;
    }

    private function targetDir(string $target): string
    {
        if (!isset(self::TARGETS[$target])) {
            throw new \InvalidArgumentException("Cible OTA inconnue : {$target}");
        }

        return rtrim($this->otaRoot, '/') . '/' . self::TARGETS[$target];
    }

    /**
     * Fichiers servables (metadata.json + *.bin, récursif) sous $dir, en excluant
     * le sous-dossier `history/`. Retourne des chemins relatifs à $dir.
     *
     * @return list<string>
     */
    private function servableFilesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        $prefixLen = strlen($dir) + 1;
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }
            $full = $fileInfo->getPathname();
            $rel = substr($full, $prefixLen);
            // Exclure l'arborescence d'historique elle-même.
            if (str_starts_with($rel, self::HISTORY_DIRNAME . '/') || str_starts_with($rel, self::HISTORY_DIRNAME . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if ($ext === 'bin' || $ext === 'json') {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
            }
        }
        sort($files);

        return $files;
    }

    /** Copie atomique : écrit dans un fichier temporaire puis rename(). */
    private function atomicCopy(string $from, string $to): void
    {
        $dir = dirname($to);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Création de répertoire impossible : {$dir}");
        }
        $tmp = $to . '.tmp-' . bin2hex(random_bytes(4));
        if (!copy($from, $tmp)) {
            throw new \RuntimeException("Copie impossible : {$from} → {$tmp}");
        }
        if (!rename($tmp, $to)) {
            @unlink($tmp);
            throw new \RuntimeException("Rename atomique impossible : {$tmp} → {$to}");
        }
    }

    /**
     * Assainit une étiquette (anti path-traversal) : caractères sûrs uniquement.
     */
    private function normalizeLabel(?string $label): string
    {
        $label = trim((string) $label);
        $label = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $label);
        // Aucun « .. » résiduel (versions type 4.55 gardent leur point unique).
        $label = (string) preg_replace('/\.{2,}/', '_', $label);
        $label = trim($label, '._-');
        if ($label === '') {
            throw new \InvalidArgumentException('Étiquette de snapshot vide ou invalide.');
        }

        return $label;
    }
}
