<?php

declare(strict_types=1);

namespace App\Mcp\Health;

/**
 * Quota disque du COMPTE (6.63.0) — distinct du volume partagé que mesure `disk_free_space`
 * (2,16 To sur l'hébergement mutualisé, ~10 % libres le 18/11/2026 : sans rapport avec la place
 * réellement disponible pour le compte).
 *
 * Sources, dans l'ordre :
 *  1. commande `quota -w` (blocs de 1 Kio : utilisé, quota souple, limite dure) si `exec` est
 *     autorisé et que la commande renvoie un quota non nul ;
 *  2. variable `DISK_QUOTA_BYTES` (quota du compte, lu dans cPanel) ; l'usage est alors mesuré par
 *     `du -sk` sur `DISK_QUOTA_PATH` (défaut : dossier personnel du compte, `HOME`), borné par
 *     `timeout` quand il existe ;
 *  3. sinon `source: inconnu` — aucune alerte.
 *
 * Aucune donnée personnelle ni chemin du compte dans le résultat.
 */
class DiskQuotaProbe
{
    private const DU_TIMEOUT_SECONDS = 10;

    /** @var \Closure(string): (list<string>|null) */
    private \Closure $run;

    /** @var array<string, mixed> */
    private array $env;

    /**
     * @param (\Closure(string): (list<string>|null))|null $run Exécuteur de commande (tests) : lignes de sortie, null si échec
     * @param array<string, mixed>|null $env Variables (défaut : $_ENV)
     */
    public function __construct(?\Closure $run = null, ?array $env = null)
    {
        $this->run = $run ?? static function (string $command): ?array {
            if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
                return null;
            }
            $output = [];
            $code = 1;
            @exec($command . ' 2>/dev/null', $output, $code);

            return $code === 0 ? array_values(array_map('strval', $output)) : null;
        };
        $this->env = $env ?? $_ENV;
    }

    /**
     * @return array{source: string, used_bytes: int|null, limit_bytes: int|null, free_bytes: int|null, free_percent: float|null, note: string}
     */
    public function probe(): array
    {
        $fromCommand = $this->fromQuotaCommand();
        if ($fromCommand !== null) {
            return self::result('quota', $fromCommand[0], $fromCommand[1], 'Commande « quota » du système.');
        }

        $limit = isset($this->env['DISK_QUOTA_BYTES']) && is_numeric($this->env['DISK_QUOTA_BYTES'])
            ? (int) $this->env['DISK_QUOTA_BYTES']
            : 0;
        if ($limit > 0) {
            $used = $this->usedByDu();

            return self::result(
                'env',
                $used,
                $limit,
                $used === null
                    ? 'Quota lu dans DISK_QUOTA_BYTES ; usage non mesurable (du indisponible) : pas d\'alerte.'
                    : 'Quota lu dans DISK_QUOTA_BYTES, usage mesuré par du.'
            );
        }

        return [
            'source' => 'inconnu',
            'used_bytes' => null,
            'limit_bytes' => null,
            'free_bytes' => null,
            'free_percent' => null,
            'note' => 'Quota du compte inconnu (commande quota indisponible, DISK_QUOTA_BYTES non renseigné) : pas d\'alerte. '
                . 'Le lire dans cPanel et le reporter dans DISK_QUOTA_BYTES.',
        ];
    }

    /**
     * Ligne de données de `quota -w` : `/dev/x  blocs[*]  quota  limite  [délai]  fichiers …`.
     *
     * @return array{0: int, 1: int}|null [utilisé, limite] en octets
     */
    private function fromQuotaCommand(): ?array
    {
        $lines = ($this->run)('quota -w');
        if ($lines === null) {
            return null;
        }
        foreach ($lines as $line) {
            if (preg_match('#^\s*\S+\s+(\d+)\*?\s+(\d+)\s+(\d+)#', $line, $m) !== 1) {
                continue;
            }
            $limitKb = (int) $m[2] > 0 ? (int) $m[2] : (int) $m[3];
            if ($limitKb > 0) {
                return [(int) $m[1] * 1024, $limitKb * 1024];
            }
        }

        return null;
    }

    private function usedByDu(): ?int
    {
        $path = trim((string) ($this->env['DISK_QUOTA_PATH'] ?? getenv('HOME') ?: ''));
        if ($path === '' || !is_dir($path)) {
            return null;
        }
        $timeout = ($this->run)('command -v timeout') !== null ? 'timeout ' . self::DU_TIMEOUT_SECONDS . ' ' : '';
        $lines = ($this->run)($timeout . 'du -sk ' . escapeshellarg($path));
        if ($lines === null || $lines === [] || preg_match('/^(\d+)/', $lines[0], $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 1024;
    }

    /**
     * @return array{source: string, used_bytes: int|null, limit_bytes: int|null, free_bytes: int|null, free_percent: float|null, note: string}
     */
    private static function result(string $source, ?int $used, int $limit, string $note): array
    {
        $free = $used !== null ? max(0, $limit - $used) : null;

        return [
            'source' => $source,
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'free_bytes' => $free,
            'free_percent' => $free !== null ? round($free / $limit * 100, 1) : null,
            'note' => $note,
        ];
    }
}
