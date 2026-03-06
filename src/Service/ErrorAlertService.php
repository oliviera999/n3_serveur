<?php

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
        $this->ensureTableExists();
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
            $pdo = Database::getConnection();
            
            // Normaliser le message pour regrouper les erreurs similaires
            $normalizedMessage = $this->normalizeErrorMessage($message, $context);
            
            // Enregistrer l'erreur
            $stmt = $pdo->prepare("
                INSERT INTO `" . self::ERROR_LOG_TABLE . "` (message, normalized_message, context, created_at)
                VALUES (:message, :normalized, :context, NOW())
            ");
            
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
            $this->logger->error("ErrorAlertService indisponible", [
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
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM `" . self::ERROR_LOG_TABLE . "`
            WHERE normalized_message = :message
              AND created_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)
        ");
        
        $stmt->execute([
            ':message' => $normalizedMessage,
            ':window' => self::TIME_WINDOW_SECONDS,
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = (int)($result['count'] ?? 0);
        
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
        $stmt = $pdo->prepare("
            SELECT MAX(alerted_at) as last_alert
            FROM `" . self::ERROR_LOG_TABLE . "`
            WHERE normalized_message = :message
              AND alerted_at IS NOT NULL
        ");
        
        $stmt->execute([':message' => $normalizedMessage]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $lastAlert = $result['last_alert'] ?? null;
        
        // Si dernière alerte trop récente, ne pas renvoyer
        if ($lastAlert !== null) {
            $lastAlertTime = strtotime($lastAlert);
            $cooldownEnd = $lastAlertTime + self::ALERT_COOLDOWN_SECONDS;
            
            if (time() < $cooldownEnd) {
                $remaining = $cooldownEnd - time();
                $this->logger->info("Alerte erreur répétée en cooldown", [
                    'message' => $normalizedMessage,
                    'count' => $count,
                    'cooldown_remaining' => $remaining . 's'
                ]);
                return;
            }
        }
        
        // Envoyer l'alerte
        $this->sendAlert($normalizedMessage, $count);
        
        // Marquer toutes les occurrences récentes comme alertées
        $stmt = $pdo->prepare("
            UPDATE `" . self::ERROR_LOG_TABLE . "`
            SET alerted_at = NOW()
            WHERE normalized_message = :message
              AND created_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)
              AND alerted_at IS NULL
        ");
        
        $stmt->execute([
            ':message' => $normalizedMessage,
            ':window' => self::TIME_WINDOW_SECONDS,
        ]);
    }
    
    /**
     * Envoie une alerte par email
     */
    private function sendAlert(string $normalizedMessage, int $count): void
    {
        $subject = "🚨 Alerte: Erreur répétée détectée";
        
        $message = "Le système a détecté une erreur qui se répète de manière anormale.\n\n";
        $message .= "Erreur: {$normalizedMessage}\n";
        $message .= "Occurrences: {$count} fois dans les " . (self::TIME_WINDOW_SECONDS / 60) . " dernières minutes\n";
        $message .= "Seuil déclenchement: " . self::ERROR_THRESHOLD . " occurrences\n\n";
        $message .= "Veuillez vérifier les logs pour plus de détails.\n";
        $message .= "Fichier de log: " . ($_ENV['LOG_FILE_PATH'] ?? 'cronlog.txt');
        
        $this->notifier->sendCustomAlert($subject, $message);
        
        $this->logger->warning("Alerte erreur répétée envoyée", [
            'message' => $normalizedMessage,
            'count' => $count,
        ]);
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
        
        $stmt = $pdo->prepare("
            DELETE FROM `" . self::ERROR_LOG_TABLE . "`
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        $stmt->execute();
        
        $deleted = $stmt->rowCount();
        if ($deleted > 0) {
            $this->logger->info("Nettoyage erreurs anciennes", ['deleted' => $deleted]);
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
        $checkSql = "SHOW TABLES LIKE :table";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([':table' => self::ERROR_LOG_TABLE]);
        $tableExists = $checkStmt->fetch() !== false;
        
        if ($tableExists) {
            // Vérifier la structure de la table
            $this->verifyTableStructure($pdo);
            return;
        }
        
        $sql = "
            CREATE TABLE IF NOT EXISTS `" . self::ERROR_LOG_TABLE . "` (
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
        ";
        
        try {
            $pdo->exec($sql);
            $this->logger->info("Table error_alerts créée avec succès");
        } catch (\PDOException $e) {
            // Vérifier si l'erreur est "table existe déjà" (code 1050 en MySQL)
            if (strpos($e->getMessage(), 'already exists') !== false || 
                $e->getCode() === '42S01') {
                $this->logger->info("Table error_alerts existe déjà");
                return;
            }
            
            // Erreur critique - logger et propager
            $this->logger->error("Erreur critique lors de la création de la table error_alerts", [
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
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
            $stmt = $pdo->query("DESCRIBE `" . self::ERROR_LOG_TABLE . "`");
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            $missingColumns = array_diff($requiredColumns, $columns);
            if (!empty($missingColumns)) {
                $this->logger->warning("Table error_alerts: colonnes manquantes", [
                    'missing' => $missingColumns,
                    'existing' => $columns
                ]);
            }
        } catch (\PDOException $e) {
            $this->logger->warning("Impossible de vérifier la structure de la table error_alerts", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
