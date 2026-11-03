<?php

declare(strict_types=1);

namespace App\Mcp\Support;

/**
 * Détection et masquage des secrets dans du texte libre, pour le connecteur MCP.
 *
 * Deux usages :
 *  - {@see containsSecret()} : refuser une note de mémoire (`iot_remember`) qui ressemble
 *    à une clé API, un jeton, un mot de passe ou une clé privée ;
 *  - {@see mask()} / {@see maskEnvLine()} : masquer les secrets reconnaissables dans les
 *    fichiers lus par `iot_read_file` / `iot_grep_files` (journaux, configuration) et
 *    n'exposer que les NOMS des variables d'un fichier `.env`.
 *
 * Les motifs sont volontairement larges (faux positifs acceptés : un masquage de trop
 * coûte moins qu'une fuite). Les adresses e-mail sont masquées dans les fichiers (donnée
 * personnelle) mais ne sont pas considérées comme des secrets pour la mémoire.
 *
 * Motifs de préfixes de jetons connus inspirés des règles publiques de détection de
 * secrets de gitleaks (https://github.com/gitleaks/gitleaks, `config/gitleaks.toml`,
 * licence MIT) — réécrits et réduits, aucun code repris.
 */
final class SecretScanner
{
    public const MASK = '****';

    /**
     * Nom de variable dont la valeur est un secret : le nom SE TERMINE par un mot sensible
     * (`DB_PASS`, `API_SIG_SECRET`, `MCP_HEALTH_TOKEN`, `api_key`, `csrf_token`…). Un nom qui
     * le contient seulement au milieu (`SIG_VALID_WINDOW`, `MCP_ACCESS_TOKEN_TTL`) n'est pas visé.
     */
    private const SECRET_KEY = '(?<![A-Za-z0-9_.-])(?:[A-Za-z0-9_.-]*?(?:pass(?:word|wd)?(?:[_-]?hash)?|pwd|secret|token|api[_-]?key|apikey|private[_-]?key|access[_-]?key|signature|sig|hmac|cookie|session[_-]?id|credentials?))(?![A-Za-z0-9_.-])';

    /** Valeur « réelle » : ni vide, ni déjà masquée, ni gabarit (`<jeton>`, `…`, `xxx`). */
    private const REAL_VALUE = '(?!\*{3}|<|…|\.\.\.|x{3,}\b|\[)';

    /**
     * Motifs « secret » : utilisés à la fois pour la détection (mémoire) et le masquage.
     *
     * @return list<array{0: string, 1: string}> [regex, remplacement]
     */
    private static function secretPatterns(): array
    {
        return [
            // Clé privée PEM (bloc entier).
            ['/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?(?:-----END [A-Z0-9 ]*PRIVATE KEY-----|$)/s', '[clé privée masquée]'],
            // En-têtes d'authentification HTTP.
            ['/\\b(Authorization|Proxy-Authorization)(\\s*[:=]\\s*)(?:(?:Bearer|Basic|Digest|Token)\\s+)?' . self::REAL_VALUE . '(?!(?:Bearer|Basic|Digest|Token)\\b)[^\\s"\',;]+/i', '$1$2' . self::MASK],
            ['/\\b(Bearer|Basic)(\\s+)(?=[A-Za-z0-9._~+\\/=-]*[0-9])[A-Za-z0-9._~+\\/=-]{8,}/', '$1$2' . self::MASK],
            ['/\\b(X-(?:Api|Health|Auth)-(?:Key|Token))(\\s*[:=]\\s*)' . self::REAL_VALUE . '[^\\s"\',;]+/i', '$1$2' . self::MASK],
            // Identifiants dans une URL : scheme://utilisateur:motdepasse@hôte
            ['#\\b([a-z][a-z0-9+.-]*://[^\\s:/@"\']+:)(?!\\*{4}@)[^\\s@/"\']+@#i', '$1' . self::MASK . '@'],
            // JSON / YAML / PHP : "password": "x", 'api_key' => 'x'
            ['/(["\'])(' . self::SECRET_KEY . ')(["\']\\s*(?:=>|:|=)\\s*)' . self::REAL_VALUE . '("[^"]+"|\'[^\']+\'|[^\\s,;}\\]]+)/i', '$1$2$3"' . self::MASK . '"'],
            // Paramètres d'URL / journaux / .ini : password=x, api_key: x, token=x, sig=x
            ['/(' . self::SECRET_KEY . ')(\\s*[=:]\\s*)' . self::REAL_VALUE . '("[^"]+"|\'[^\']+\'|[^\\s&"\',;]+)/i', '$1$2' . self::MASK],
            // JWT (header.payload.signature).
            ['/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', '[jeton JWT masqué]'],
            // Préfixes de jetons connus (GitHub, Slack, Stripe, OpenAI/Anthropic, GitLab, AWS, Google).
            ['/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{16,}|xox[abprs]-[A-Za-z0-9-]{10,}|(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{12,}|sk-[A-Za-z0-9_-]{20,}|AKIA[0-9A-Z]{16}|AIza[0-9A-Za-z_-]{30,})\b/', '[jeton masqué]'],
            // Hachés bcrypt / argon.
            ['/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/', '[haché masqué]'],
            ['/\$argon2id?\$[^\s"\']+/', '[haché masqué]'],
            // Valeur aléatoire longue (≥ 32 caractères mêlant minuscules, majuscules et chiffres) :
            // jetons OAuth opaques, clés API générées. Les identifiants de code (sans chiffre)
            // et les condensats hexadécimaux en minuscules ne sont pas concernés.
            ['/(?<![A-Za-z0-9_+\/-])(?=[A-Za-z0-9_+\/=-]*[a-z])(?=[A-Za-z0-9_+\/=-]*[A-Z])(?=[A-Za-z0-9_+\/=-]*[0-9])[A-Za-z0-9_+\/-]{32,}={0,2}(?![A-Za-z0-9_+\/-])/', '[valeur longue masquée]'],
        ];
    }

    /** Index des motifs « cle=valeur » de {@see secretPatterns()} (masquage large, détection stricte à part). */
    private const ASSIGNMENT_PATTERN_INDEXES = [5, 6];

    /** Motifs masqués dans les fichiers mais non considérés comme des secrets (données personnelles). */
    private const PERSONAL_PATTERNS = [
        '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i' => '[e-mail masqué]',
    ];

    /**
     * Vrai si le texte contient vraisemblablement un secret (clé, jeton, mot de passe, clé privée).
     */
    public static function containsSecret(string $text): bool
    {
        // Affectation « cle=valeur » : pour la détection, la valeur doit RESSEMBLER à un secret
        // (≥ 6 caractères sans espace, avec un chiffre ou un symbole) — une note qui écrit
        // « API_SIG_SECRET : clé HMAC des ESP32 » ne doit pas être refusée.
        // La clé doit être un identifiant (avec _ - . ou guillemets) ou collée au signe : « HMAC : x »
        // en prose n'est pas une affectation.
        $assignment = '/(?:(?=[A-Za-z0-9.-]*[_.-])' . self::SECRET_KEY . '["\']?\s*|["\']' . self::SECRET_KEY . '["\']\s*|' . self::SECRET_KEY . ')(?:=>|=|:)\s*["\']?' . self::REAL_VALUE
            . '(?=[^\s"\',;&]*[0-9!@#$%^*+\/=_-])[^\s"\',;&]{6,}/i';
        if (preg_match($assignment, $text) === 1) {
            return true;
        }
        foreach (self::secretPatterns() as $index => [$pattern]) {
            if (in_array($index, self::ASSIGNMENT_PATTERN_INDEXES, true)) {
                continue;
            }
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Masque les secrets (et les e-mails sauf si `$keepEmails`) d'un texte libre.
     */
    public static function mask(string $text, bool $keepEmails = false): string
    {
        $out = $text;
        foreach (self::secretPatterns() as [$pattern, $replacement]) {
            $replaced = preg_replace($pattern, $replacement, $out);
            if (is_string($replaced)) {
                $out = $replaced;
            }
        }
        if (!$keepEmails) {
            foreach (self::PERSONAL_PATTERNS as $pattern => $replacement) {
                $replaced = preg_replace($pattern, $replacement, $out);
                if (is_string($replaced)) {
                    $out = $replaced;
                }
            }
        }

        return $out;
    }

    /**
     * Ligne d'un fichier `.env` : seul le NOM de la variable reste visible.
     *
     *  - `CLE=valeur`        → `CLE=****`
     *  - `CLE=` (vide)       → `CLE=` (l'absence de valeur n'est pas un secret, utile au diagnostic)
     *  - `export CLE="x"`    → `export CLE=****`
     *  - commentaire / autre → secrets reconnaissables masqués ({@see mask()})
     */
    public static function maskEnvLine(string $line): string
    {
        if (preg_match('/^(\s*(?:export\s+)?[A-Za-z_][A-Za-z0-9_.-]*\s*=)(.*)$/', $line, $m) === 1) {
            $value = trim($m[2]);
            $empty = $value === '' || $value === '""' || $value === "''";

            return $m[1] . ($empty ? '' : self::MASK);
        }

        return self::mask($line);
    }

    /**
     * Vrai si le nom de fichier désigne un fichier d'environnement (valeurs masquées).
     */
    public static function isEnvFile(string $basename): bool
    {
        return preg_match('/^\.env(\..+)?$|\.env$/i', $basename) === 1;
    }

    /**
     * Vrai si le fichier est un conteneur de secrets à ne JAMAIS servir (clé privée, magasin
     * de certificats, mots de passe Apache, clés SSH).
     */
    public static function isSecretStore(string $basename): bool
    {
        return preg_match('/\.(pem|key|p12|pfx|jks|keystore|kdbx|gpg|asc)$|^\.htpasswd$|^id_(rsa|dsa|ecdsa|ed25519)(\.pub)?$|^\.netrc$|^\.pgpass$|^auth\.json$/i', $basename) === 1;
    }
}
