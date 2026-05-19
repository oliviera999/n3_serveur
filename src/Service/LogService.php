<?php

declare(strict_types=1);

namespace App\Service;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Formatter\LineFormatter;

/**
 * Service centralisé de gestion des logs applicatifs.
 * Utilise Monolog pour écrire des événements, tâches et erreurs dans un fichier texte.
 *
 * Variables d'environnement :
 *   - LOG_FILE_PATH      : chemin (défaut `cronlog.txt`)
 *   - LOG_LEVEL          : DEBUG|INFO|WARNING|ERROR|CRITICAL (défaut DEBUG)
 *   - LOG_ROTATE_DAYS    : rotation quotidienne (défaut 14 ; 0 = pas de rotation)
 *   - LOG_MASK_IP        : true (défaut) — masque le dernier octet IPv4 / 80 bits IPv6
 *                          pour limiter le risque RGPD si le cronlog est exposé publiquement.
 */
class LogService
{
    private const DEFAULT_LOG_FILE = 'cronlog.txt';

    private string $logFile;

    private Logger $logger;

    private bool $maskIp;

    public function __construct()
    {
        $this->logFile = (string) ($_ENV['LOG_FILE_PATH'] ?? self::DEFAULT_LOG_FILE);
        $this->maskIp = filter_var($_ENV['LOG_MASK_IP'] ?? 'true', FILTER_VALIDATE_BOOLEAN);

        $this->logger = new Logger('n3-iot');

        // Format : `[YYYY-MM-DD HH:MM:SS] [LEVEL] message` puis context JSON uniquement
        // s'il est non vide (extraction conditionnelle dans log()). LineFormatter
        // n'affiche `%context%` que si non vide grace au mode "ignore empty context".
        $lineFormatter = new LineFormatter("[%datetime%] [%level_name%] %message%%context%\n", 'Y-m-d H:i:s', true, true);
        $lineFormatter->ignoreEmptyContextAndExtra(true);

        $logLevel = $this->parseLevel((string) ($_ENV['LOG_LEVEL'] ?? 'DEBUG'));
        $rotateDays = (int) ($_ENV['LOG_ROTATE_DAYS'] ?? 14);

        $handler = $rotateDays > 0
            ? new RotatingFileHandler($this->logFile, $rotateDays, $logLevel)
            : new StreamHandler($this->logFile, $logLevel);
        $handler->setFormatter($lineFormatter);

        $this->logger->pushHandler($handler);
    }

    private function parseLevel(string $level): int
    {
        return match (strtoupper($level)) {
            'DEBUG' => Logger::DEBUG,
            'INFO' => Logger::INFO,
            'WARNING', 'WARN' => Logger::WARNING,
            'ERROR' => Logger::ERROR,
            'CRITICAL', 'FATAL' => Logger::CRITICAL,
            default => Logger::DEBUG,
        };
    }

    /**
     * Masque l'IP pour eviter de loguer des PII complets (rgpd cronlog public).
     * IPv4 a.b.c.d -> a.b.c.0   |   IPv6 abcd:ef01:... -> abcd:ef01:::/80
     */
    private function maskIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || $ip === 'n/a') {
            return 'n/a';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $segments = explode(':', $ip);
            $kept = array_slice($segments, 0, 3);
            return implode(':', $kept) . '::/80';
        }
        return 'invalid';
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
        // Masquer les IP du contexte si LOG_MASK_IP est actif
        if ($this->maskIp) {
            foreach (['ip', 'client_ip', 'remote_addr'] as $key) {
                if (isset($context[$key]) && is_string($context[$key])) {
                    $context[$key] = $this->maskIp($context[$key]);
                }
            }
        }

        // Remplacement manuel des placeholders {key} par leur valeur
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $message = str_replace('{' . $key . '}', (string) $value, $message);
            }
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
        // Utiliser Monolog pour la cohérence
        $this->logger->debug($event);
    }

    // Méthodes niveau PSR-3 – garde API existante
    public function info(string $message, array $context = []): void     { $this->log(Logger::INFO, $message, $context); }
    public function warning(string $message, array $context = []): void  { $this->log(Logger::WARNING, $message, $context); }
    public function error(string $message, array $context = []): void    { $this->log(Logger::ERROR, $message, $context); }
    public function critical(string $message, array $context = []): void { $this->log(Logger::CRITICAL, $message, $context); }
}
