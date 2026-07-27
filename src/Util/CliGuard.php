<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Garde « ligne de commande uniquement » pour les scripts de maintenance
 * (`tools/`, `bin/`, `run-cron.php`).
 *
 * Motif (audit 2026-07, S11) : le `.htaccess` racine ne bloque que
 * `vendor/ config/ src/ var/ templates/`, et le routeur ne prend la main que
 * sur les URL qui ne correspondent PAS a un fichier existant
 * (`RewriteCond %{REQUEST_FILENAME} !-f`). Quand le DocumentRoot pointe sur la
 * racine du depot — la configuration prevue par ce meme `.htaccess` — un
 * `GET /tools/xxx.php` est donc execute directement par Apache, sans passer par
 * Slim ni par la moindre authentification.
 *
 * La protection est doublee : filtre `.htaccess` (peut etre neutralise par un
 * `AllowOverride None` ou un passage a nginx) ET cette garde applicative, qui
 * suit le script quel que soit le serveur web.
 *
 * `isCli()` est volontairement injectable (SAPI + `$_SERVER`) pour rester
 * testable sans manipuler l'environnement global.
 */
final class CliGuard
{
    /** SAPI qui sont, par construction, hors contexte HTTP. */
    public const CLI_SAPIS = ['cli', 'phpdbg'];

    /**
     * Marqueurs positionnes par un serveur web (et jamais par un shell).
     *
     * Sert a distinguer `php-cgi` lance par une crontab d'hebergement mutualise
     * (accepte) de `cgi-fcgi` derriere PHP-FPM servant une requete (refuse).
     */
    private const HTTP_MARKERS = [
        'REQUEST_METHOD',
        'REQUEST_URI',
        'SERVER_PROTOCOL',
        'HTTP_HOST',
        'REMOTE_ADDR',
    ];

    /**
     * @param array<string, mixed>|null $server Defaut : `$_SERVER`.
     */
    public static function isCli(?string $sapi = null, ?array $server = null): bool
    {
        $sapi ??= PHP_SAPI;
        $server ??= $_SERVER;

        if (in_array($sapi, self::CLI_SAPIS, true)) {
            return true;
        }

        // Certains hebergements mutualises lancent le CRON via `php-cgi`. On
        // l'accepte, mais UNIQUEMENT en l'absence de contexte HTTP : sous
        // PHP-FPM le SAPI est `cgi-fcgi` et une simple correspondance sur
        // « cgi » laisserait passer les requetes web (le defaut historique de
        // la garde de `run-cron.php`).
        if (str_contains($sapi, 'cgi')) {
            return !self::hasHttpContext($server);
        }

        return false;
    }

    /**
     * Interrompt le script (403) s'il est atteint par une requete web.
     *
     * A appeler tout en haut du script, AVANT `vendor/autoload.php` et avant
     * tout effet de bord (connexion PDO, migration, ecriture de fichier).
     */
    public static function assertCli(?string $scriptName = null): void
    {
        if (self::isCli()) {
            return;
        }

        $name = $scriptName ?? basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'script'));
        error_log(sprintf(
            '[%s] [CliGuard] Refus execution web de %s (SAPI=%s, IP=%s)',
            date('Y-m-d H:i:s'),
            $name,
            PHP_SAPI,
            (string) ($_SERVER['REMOTE_ADDR'] ?? 'n/a')
        ));

        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }

        exit("Forbidden: ce script est reserve a la ligne de commande.\n");
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function hasHttpContext(array $server): bool
    {
        foreach (self::HTTP_MARKERS as $marker) {
            if (isset($server[$marker])) {
                return true;
            }
        }

        return false;
    }
}
