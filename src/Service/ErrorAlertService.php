<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Database;
use PDO;

/**
 * Service de détection et d'alerte sur erreurs répétées
 *
 * Analyse les erreurs loggées et envoie des alertes automatiques
 * quand une erreur se répète trop souvent dans une fenêtre de temps.
 *
 * Fonctionnalités:
 * - Détection erreurs répétées (même message)
 * - Comptage occurrences dans fenêtre temporelle
 * - Alertes automatiques via NotificationService
 * - Cooldown pour éviter spam d'alertes
 */
class ErrorAlertService
{
    /**
     * Seuil d'occurrences pour déclencher une alerte
     */
    private const ERROR_THRESHOLD = 5;

    /**
     * Fenêtre de temps pour compter les erreurs (en secondes)
     */
    private const TIME_WINDOW_SECONDS = 300; // 5 minutes

    /**
     * Cooldown entre alertes pour la même erreur (en secondes)
     */
    private const ALERT_COOLDOWN_SECONDS = 3600; // 1 heure

    /**
     * Nom de la table pour stocker les erreurs
     */
    private const ERROR_LOG_TABLE = 'error_alerts';

    public function __construct(
        private LogService $logger,
        private NotificationService $notifier
    ) {
    }

    /**
     * Enregistre une erreur et vérifie si une alerte doit être envoyée
     *
     * @param string $message Message d'erreur
     * @param array $context Contexte additionnel (optionnel)
     */
    public function recordError(string $message, array $context = []): void
    {
        try {
            $this->ensureTableExists();
            $pdo = Database::getConnection();

            // Normaliser le message pour regrouper les erreurs similaires
            $normalizedMessage = $this->normalizeErrorMessage($message, $context);

            // Enregistrer l'erreur
            $stmt = $pdo->prepare('
                INSERT INTO `' . self::ERROR_LOG_TABLE . '` (message, normalized_message, context, created_at)
                VALUES (:message, :normalized, :context, NOW())
            ');

            $stmt->execute([
                ':message' => $message,
                ':normalized' => $normalizedMessage,
                ':context' => json_encode($context),
            ]);

            // Vérifier si une alerte doit être envoyée
            $this->checkAndAlert($normalizedMessage);

            // Nettoyer les anciennes entrées (plus de 24h)
            $this->cleanupOldEntries();
        } catch (\Throwable $e) {
            $this->logger->error('ErrorAlertService indisponible', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
        }
    }

    /**
     * Normalise un message d'erreur pour regrouper les erreurs similaires
     *
     * Exemple: "Erreur insertion données: Connection refused"
     *          → "Erreur insertion données: *"
     */
    private function normalizeErrorMessage(string $message, array $context): string
    {
        // Extraire le type d'erreur principal (avant le ":")
        if (strpos($message, ':') !== false) {
            $parts = explode(':', $message, 2);
            $baseMessage = trim($parts[0]);

            // Si contexte contient 'error', utiliser le message de base + type
            if (isset($context['error'])) {
                $errorType = $this->extractErrorType($context['error']);
                return $baseMessage . ': ' . $errorType;
            }

            return $baseMessage . ': *';
        }

        return $message;
    }

    /**
     * Extrait le type d'erreur d'un message d'erreur détaillé
     */
    private function extractErrorType(string $error): string
    {
        // Patterns communs d'erreurs
        $patterns = [
            '/Connection refused/i' => 'Connection refused',
            '/Connection timed out/i' => 'Connection timeout',
            '/SQLSTATE\[(\d+)\]/' => 'SQL Error',
            '/Unknown column/i' => 'Unknown column',
            '/Table.*doesn\'t exist/i' => 'Table not found',
            '/Duplicate entry/i' => 'Duplicate entry',
            '/Access denied/i' => 'Access denied',
        ];

        foreach ($patterns as $pattern => $type) {
            if (preg_match($pattern, $error)) {
                return $type;
            }
        }

        return '*';
    }

    /**
     * Vérifie si une alerte doit être envoyée pour une erreur normalisée
     */
    private function checkAndAlert(string $normalizedMessage): void
    {
        $pdo = Database::getConnection();

        // Compter les occurrences dans la fenêtre de temps
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as count
            FROM `' . self::ERROR_LOG_TABLE . '`
            WHERE normalized_message = :message
              AND created_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)
        ');

        $stmt->execute([
            ':message' => $normalizedMessage,
            ':window' => self::TIME_WINDOW_SECONDS,
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = (int) ($result['count'] ?? 0);

        // Si seuil atteint, vérifier cooldown et envoyer alerte
        if ($count >= self::ERROR_THRESHOLD) {
            $this->sendAlertIfNeeded($normalizedMessage, $count);
        }
    }

    /**
     * Envoie une alerte si le cooldown est respecté
     */
    private function sendAlertIfNeeded(string $normalizedMessage, int $count): void
    {
        $pdo = Database::getConnection();

        // Vérifier si une alerte a déjà été envoyée récemment
        $stmt = $pdo->prepare('
            SELECT MAX(alerted_at) as last_alert
            FROM `' . self::ERROR_LOG_TABLE . '`
            WHERE normalized_message = :message
              AND alerted_at IS NOT NULL
        ');

        $stmt->execute([':message' => $normalizedMessage]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $lastAlert = $result['last_alert'] ?? null;

        // Si dernière alerte trop récente, ne pas renvoyer
        if ($lastAlert !== null) {
            $lastAlertTime = strtotime($lastAlert);
            $cooldownEnd = $lastAlertTime + self::ALERT_COOLDOWN_SECONDS;

            if (time() < $cooldownEnd) {
                $remaining = $cooldownEnd - time();
                $this->logger->info('Alerte erreur répétée en cooldown', [
                    'message' => $normalizedMessage,
                    'count' => $count,
                    'cooldown_remaining' => $remaining . 's',
                ]);
                return;
            }
        }

        // Envoyer l'alerte
        $this->sendAlert($normalizedMessage, $count);

        // Marquer toutes les occurrences récentes comme alertées
        $stmt = $pdo->prepare('
            UPDATE `' . self::ERROR_LOG_TABLE . '`
            SET alerted_at = NOW()
            WHERE normalized_message = :message
              AND created_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)
              AND alerted_at IS NULL
        ');

        $stmt->execute([
            ':message' => $normalizedMessage,
            ':window' => self::TIME_WINDOW_SECONDS,
        ]);
    }

    /**
     * Envoie une alerte par email
     *
     * Le corps contient les dernières occurrences réelles (message d'exception, page
     * appelée, fichier et ligne) : avant 6.64.0, il ne donnait que le message normalisé
     * (« Exception non gérée ») et renvoyait vers le journal.
     */
    private function sendAlert(string $normalizedMessage, int $count): void
    {
        $subject = '🚨 Alerte: Erreur répétée détectée — ' . self::truncate($normalizedMessage, 80);

        $message = self::buildAlertBody(
            $normalizedMessage,
            $count,
            $this->fetchRecentOccurrences($normalizedMessage),
            (string) ($_ENV['LOG_FILE_PATH'] ?? 'cronlog.txt'),
        );

        $this->notifier->sendCustomAlert($subject, $message);

        $this->logger->warning('Alerte erreur répétée envoyée', [
            'message' => $normalizedMessage,
            'count' => $count,
        ]);
    }

    /**
     * Dernières occurrences de l'erreur dans la fenêtre (message brut + contexte décodé).
     *
     * @return list<array{created_at: string, message: string, context: array<string, mixed>}>
     */
    private function fetchRecentOccurrences(string $normalizedMessage): array
    {
        try {
            $stmt = Database::getConnection()->prepare('
                SELECT message, context, created_at
                FROM `' . self::ERROR_LOG_TABLE . '`
                WHERE normalized_message = :message
                  AND created_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)
                ORDER BY id DESC
                LIMIT 50
            ');
            $stmt->execute([':message' => $normalizedMessage, ':window' => self::TIME_WINDOW_SECONDS]);

            $rows = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $context = json_decode((string) ($row['context'] ?? ''), true);
                $rows[] = [
                    'created_at' => (string) ($row['created_at'] ?? ''),
                    'message' => (string) ($row['message'] ?? ''),
                    'context' => is_array($context) ? $context : [],
                ];
            }

            return $rows;
        } catch (\Throwable $e) {
            $this->logger->warning('ErrorAlertService : occurrences illisibles pour le mail', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Corps du mail d'alerte : résumé, puis chaque erreur distincte (message réel, page
     * sans query string, fichier:ligne relatif au dépôt) avec son nombre d'occurrences.
     *
     * @param list<array{created_at: string, message: string, context: array<string, mixed>}> $occurrences
     *        les plus récentes d'abord
     */
    public static function buildAlertBody(string $normalizedMessage, int $count, array $occurrences, string $logFile): string
    {
        $body = "Le système a détecté une erreur qui se répète de manière anormale.\n\n";
        $body .= "Erreur: {$normalizedMessage}\n";
        $body .= "Occurrences: {$count} fois dans les " . (self::TIME_WINDOW_SECONDS / 60) . " dernières minutes\n";
        $body .= 'Seuil déclenchement: ' . self::ERROR_THRESHOLD . " occurrences\n";

        /** @var array<string, array{n: int, last: string, error: string, page: string, where: string}> $groups */
        $groups = [];
        foreach ($occurrences as $occ) {
            $ctx = $occ['context'];
            $error = self::truncate(trim((string) ($ctx['error'] ?? $occ['message'])), 500);
            $page = trim(((string) ($ctx['method'] ?? '')) . ' ' . self::stripQuery((string) ($ctx['url'] ?? '')));
            $where = '';
            if (isset($ctx['file']) && (string) $ctx['file'] !== '') {
                $where = self::relativePath((string) $ctx['file']) . (isset($ctx['line']) ? ':' . (int) $ctx['line'] : '');
            }
            $key = $error . '|' . $page . '|' . $where;
            if (!isset($groups[$key])) {
                $groups[$key] = ['n' => 0, 'last' => $occ['created_at'], 'error' => $error, 'page' => $page, 'where' => $where];
            }
            $groups[$key]['n']++;
        }

        if ($groups === []) {
            $body .= "\nDétail indisponible : voir le journal.\n";
        } else {
            $body .= "\nDernières occurrences (de la plus récente à la plus ancienne) :\n";
            $i = 0;
            foreach ($groups as $g) {
                if (++$i > 5) {
                    $body .= '… et ' . (count($groups) - 5) . " autre(s) erreur(s) distincte(s).\n";
                    break;
                }
                $body .= "\n" . $i . '. ' . $g['error'] . "\n";
                if ($g['page'] !== '') {
                    $body .= '   Page : ' . $g['page'] . "\n";
                }
                if ($g['where'] !== '') {
                    $body .= '   Code : ' . $g['where'] . "\n";
                }
                $body .= '   ' . $g['n'] . ' fois, dernière le ' . $g['last'] . "\n";
            }
        }

        $body .= "\nJournal complet : " . basename($logFile) . ' (lignes « Exception non gérée [id] »).';

        return $body;
    }

    /** URL sans query string ni fragment (un jeton ou une clé API ne part pas par mail). */
    private static function stripQuery(string $url): string
    {
        return (string) preg_replace('/[?#].*$/s', '', $url);
    }

    /** Chemin relatif au dépôt (…/src/X.php) : le chemin absolu de l'hébergeur ne sort pas. */
    private static function relativePath(string $file): string
    {
        if (preg_match('#/((?:src|templates|config|public|tools|bin|vendor)/.+)$#', $file, $m) === 1) {
            return $m[1];
        }

        return basename($file);
    }

    private static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    /**
     * Nettoie les anciennes entrées (plus de 24h)
     */
    private function cleanupOldEntries(): void
    {
        $pdo = Database::getConnection();

        // Nettoyer seulement 1 fois par heure pour éviter surcharge
        static $lastCleanup = 0;
        $now = time();

        if ($now - $lastCleanup < 3600) {
            return;
        }

        $lastCleanup = $now;

        $stmt = $pdo->prepare('
            DELETE FROM `' . self::ERROR_LOG_TABLE . '`
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ');

        $stmt->execute();

        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            $this->logger->info('Nettoyage erreurs anciennes', ['deleted' => $deleted]);
        }
    }

    /**
     * Crée la table si elle n'existe pas
     *
     * @throws \RuntimeException Si la table ne peut pas être créée et n'existe pas déjà
     */
    private function ensureTableExists(): void
    {
        $pdo = Database::getConnection();

        // Vérifier d'abord si la table existe déjà
        $checkSql = 'SHOW TABLES LIKE :table';
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([':table' => self::ERROR_LOG_TABLE]);
        $tableExists = $checkStmt->fetch() !== false;

        if ($tableExists) {
            // Vérifier la structure de la table
            $this->verifyTableStructure($pdo);
            return;
        }

        $sql = '
            CREATE TABLE IF NOT EXISTS `' . self::ERROR_LOG_TABLE . '` (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                normalized_message VARCHAR(255) NOT NULL,
                context TEXT,
                created_at DATETIME NOT NULL,
                alerted_at DATETIME NULL,
                INDEX idx_normalized (normalized_message),
                INDEX idx_created (created_at),
                INDEX idx_alerted (alerted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ';

        try {
            $pdo->exec($sql);
            $this->logger->info('Table error_alerts créée avec succès');
        } catch (\PDOException $e) {
            // Vérifier si l'erreur est "table existe déjà" (code 1050 en MySQL)
            if (strpos($e->getMessage(), 'already exists') !== false ||
                $e->getCode() === '42S01') {
                $this->logger->info('Table error_alerts existe déjà');
                return;
            }

            // Erreur critique - logger et propager
            $this->logger->error('Erreur critique lors de la création de la table error_alerts', [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Ne pas bloquer l'application mais logger l'erreur
            // Le service continuera à fonctionner mais les erreurs ne seront pas enregistrées
        }
    }

    /**
     * Vérifie que la structure de la table correspond aux attentes
     *
     * @param \PDO $pdo Connexion PDO
     */
    private function verifyTableStructure(\PDO $pdo): void
    {
        $requiredColumns = ['id', 'message', 'normalized_message', 'context', 'created_at', 'alerted_at'];

        try {
            $stmt = $pdo->query('DESCRIBE `' . self::ERROR_LOG_TABLE . '`');
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $missingColumns = array_diff($requiredColumns, $columns);
            if (!empty($missingColumns)) {
                $this->logger->warning('Table error_alerts: colonnes manquantes', [
                    'missing' => $missingColumns,
                    'existing' => $columns,
                ]);
            }
        } catch (\PDOException $e) {
            $this->logger->warning('Impossible de vérifier la structure de la table error_alerts', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
