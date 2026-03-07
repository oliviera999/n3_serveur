<?php

namespace App\Service;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;

/**
 * Service centralisé de gestion des logs applicatifs.
 * Utilise Monolog pour écrire des événements, tâches et erreurs dans un fichier texte.
 * Permet de tracer l'activité du système pour le debug, l'audit ou la supervision.
 */
class LogService
{
    /**
     * Nom du fichier de log par défaut (modifiable via .env)
     */
    private const DEFAULT_LOG_FILE = 'cronlog.txt';
    /**
     * Chemin du fichier de log utilisé
     */
    private string $logFile;
    /**
     * Instance Monolog configurée
     */
    private Logger $logger;

    /**
     * Initialise le logger avec le format souhaité et le fichier cible.
     * Le format est : [date] [LEVEL] message
     */
    public function __construct()
    {
        $this->logFile = $_ENV['LOG_FILE_PATH'] ?? self::DEFAULT_LOG_FILE;

        $this->logger = new Logger('ffp3');

        // Format de sortie proche de l'ancien : [date] [LEVEL] message
        $lineFormatter = new LineFormatter("[%datetime%] [%level_name%] %message%\n", 'Y-m-d H:i:s', true, true);

        $streamHandler = new StreamHandler($this->logFile, Logger::DEBUG);
        $streamHandler->setFormatter($lineFormatter);

        $this->logger->pushHandler($streamHandler);
    }

    /**
     * Ajoute une entrée de log à un niveau donné (privé, utilisé par les méthodes publiques).
     *
     * @param int|string $level   Niveau Monolog (constante ou nom, ex : Logger::INFO)
     * @param string     $message Message à enregistrer (peut contenir des {placeholders})
     * @param array      $context Variables à injecter dans le message
     */
    private function log(int|string $level, string $message, array $context = []): void
    {
        // Remplacement manuel des placeholders {key} par leur valeur
        foreach ($context as $key => $value) {
            $message = str_replace('{' . $key . '}', (string)$value, $message);
        }

        $this->logger->log($level, $message, $context);
    }

    /**
     * Ajoute un événement horodaté au fichier de log (niveau INFO).
     *
     * @param string $event Description de l'événement
     */
    public function addEvent(string $event): void
    {
        $this->logger->info($event);
    }
    
    /**
     * Ajoute une tâche (sans horodatage explicite) au fichier de log (niveau INFO).
     *
     * @param string $event Description de la tâche
     */
    public function addTask(string $event): void
    {
        $this->logger->info($event);
    }
    
    /**
     * Ajoute un nom (sans horodatage et sans saut de ligne) au fichier de log
     */
    public function addName(string $event): void
    {
        // Pas de saut de ligne pour rester compatible, mais journalisons également côté Monolog
        file_put_contents($this->logFile, $event, FILE_APPEND);
        $this->logger->debug($event);
    }
    
    /**
     * Envoie un email d'alerte en cas de problème critique
     */
    public function sendAlertEmail(string $subject, string $message, array $params = []): bool
    {
        $to = $_ENV['ALERT_EMAIL'] ?? 'admin@olution.info';
        $headers = [
            'From: ffp3@olution.info',
            'Content-Type: text/plain; charset=utf-8'
        ];
        
        // Paramètres à inclure dans l'email
        if (!empty($params)) {
            $message .= "\n\nParamètres:\n";
            foreach ($params as $key => $value) {
                $message .= "- $key: $value\n";
            }
        }
        
        $this->logger->notice("Envoi d'alerte par email: $subject");
        return mail($to, $subject, $message, implode("\r\n", $headers));
    }

    // Méthodes niveau PSR-3 – garde API existante
    public function info(string $message, array $context = []): void     { $this->log(Logger::INFO, $message, $context); }
    public function warning(string $message, array $context = []): void  { $this->log(Logger::WARNING, $message, $context); }
    public function error(string $message, array $context = []): void    { $this->log(Logger::ERROR, $message, $context); }
    public function critical(string $message, array $context = []): void { $this->log(Logger::CRITICAL, $message, $context); }
} 