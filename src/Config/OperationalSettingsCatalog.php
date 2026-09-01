<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Catalogue des réglages opérationnels pilotables depuis la supervision.
 *
 * Chaque entrée référence une variable `.env` ; la persistance BDD utilise la clé
 * `op_<ENV_KEY>` dans `serverSettings`.
 */
final class OperationalSettingsCatalog
{
    public const GROUP_ALERTS = 'alerts';

    public const GROUP_SECURITY = 'security';

    public const GROUP_NOTIFICATIONS = 'notifications';

    public const GROUP_MAINTENANCE = 'maintenance';

    public const GROUP_DATA_QUALITY = 'data_quality';

    public const GROUP_FLOOD = 'flood';

    public const TYPE_BOOL = 'bool';

    public const TYPE_INT = 'int';

    public const TYPE_FLOAT = 'float';

    public const TYPE_STRING = 'string';

    public const TYPE_ENUM = 'enum';

    public const TYPE_OPTIONAL_INT = 'optional_int';

    public const TYPE_OPTIONAL_FLOAT = 'optional_float';

    /**
     * @return array<string, array{
     *   env: string,
     *   type: string,
     *   group: string,
     *   label: string,
     *   hint: string,
     *   default: mixed,
     *   min?: float,
     *   max?: float,
     *   options?: list<string>,
     *   unit?: string
     * }>
     */
    public static function all(): array
    {
        return [
            'NOTIF_MODE' => [
                'env' => 'NOTIF_MODE',
                'type' => self::TYPE_ENUM,
                'group' => self::GROUP_NOTIFICATIONS,
                'label' => 'Mode notifications global',
                'hint' => 'none = aucun mail ; important = P1+P2 (recommandé) ; partial = +P3 ; full = tout.',
                'default' => 'important',
                'options' => ['none', 'important', 'partial', 'full'],
            ],
            'NOTIF_DISABLED_CATEGORIES' => [
                'env' => 'NOTIF_DISABLED_CATEGORIES',
                'type' => self::TYPE_STRING,
                'group' => self::GROUP_NOTIFICATIONS,
                'label' => 'Catégories mail désactivées',
                'hint' => 'Liste séparée par des virgules : hydraulic, energy, environment, feeding, availability, lifecycle, camera, system.',
                'default' => '',
            ],
            'FIRMWARE_FLAT_STATE_MODE' => [
                'env' => 'FIRMWARE_FLAT_STATE_MODE',
                'type' => self::TYPE_ENUM,
                'group' => self::GROUP_MAINTENANCE,
                'label' => 'Config distante n3pp/msp (clés plates)',
                'hint' => 'off = la flotte garde sa config locale (pas de clés plates ni d\'ack '
                    . 'one-shot sur /api/outputs/state ; recommandé tant que les modules ne '
                    . 'sont pas reflashés) ; safe = config appliquée mais nettoyée (état pompe '
                    . 'miroir retiré, valeurs hors plage ignorées) ; full = tout est renvoyé '
                    . 'tel quel.',
                'default' => 'off',
                'options' => ['off', 'safe', 'full'],
            ],
            'HEARTBEAT_OFFLINE_THRESHOLD_SECONDS' => [
                'env' => 'HEARTBEAT_OFFLINE_THRESHOLD_SECONDS',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_ALERTS,
                'label' => 'Délai appareil silencieux',
                'hint' => 'Secondes sans heartbeat avant alerte (toutes familles). Sert de '
                    . 'PLANCHER : le seuil dérivé de la veille ne peut que l\'allonger.',
                'default' => 3600,
                'min' => 60,
                'max' => 86400,
                'unit' => 's',
            ],
            'AQUA_LOW_LEVEL_THRESHOLD' => [
                'env' => 'AQUA_LOW_LEVEL_THRESHOLD',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_ALERTS,
                'label' => 'Seuil eau basse aquarium',
                'hint' => 'Distance capteur→surface en mm (valeur élevée = eau basse).',
                'default' => 180,
                'min' => 40,
                'max' => 1000,
                'unit' => 'mm',
            ],
            'RESERVE_LOW_LEVEL_THRESHOLD' => [
                'env' => 'RESERVE_LOW_LEVEL_THRESHOLD',
                'type' => self::TYPE_OPTIONAL_FLOAT,
                'group' => self::GROUP_ALERTS,
                'label' => 'Seuil réserve basse',
                'hint' => 'Vide = désactivé. Distance mm ; complète le GPIO 130 si non configuré en BDD contrôle.',
                'default' => null,
                'min' => 1,
                'max' => 2000,
                'unit' => 'mm',
            ],
            'MSP_FROST_ALERT_THRESHOLD_C' => [
                'env' => 'MSP_FROST_ALERT_THRESHOLD_C',
                'type' => self::TYPE_OPTIONAL_FLOAT,
                'group' => self::GROUP_ALERTS,
                'label' => 'Alerte gel (MSP1)',
                'hint' => 'Vide = désactivé. Température extérieure en °C.',
                'default' => null,
                'min' => -30,
                'max' => 15,
                'unit' => '°C',
            ],
            'MSP_HEAT_ALERT_THRESHOLD_C' => [
                'env' => 'MSP_HEAT_ALERT_THRESHOLD_C',
                'type' => self::TYPE_OPTIONAL_FLOAT,
                'group' => self::GROUP_ALERTS,
                'label' => 'Alerte canicule (MSP1)',
                'hint' => 'Vide = désactivé. Température extérieure en °C.',
                'default' => null,
                'min' => 25,
                'max' => 55,
                'unit' => '°C',
            ],
            'MSP_RAIN_WET_THRESHOLD' => [
                'env' => 'MSP_RAIN_WET_THRESHOLD',
                'type' => self::TYPE_OPTIONAL_INT,
                'group' => self::GROUP_ALERTS,
                'label' => 'Seuil pluie (MSP1)',
                'hint' => 'Vide = désactivé. Capteur analogique (4095 = sec).',
                'default' => null,
                'min' => 0,
                'max' => 4095,
            ],
            'TIDE_STDDEV_THRESHOLD' => [
                'env' => 'TIDE_STDDEV_THRESHOLD',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_ALERTS,
                'label' => 'Sensibilité marées',
                'hint' => 'Écart-type minimum pour l’analyse des marées.',
                'default' => 1.0,
                'min' => 0.1,
                'max' => 10.0,
            ],
            'CRON_HOURLY_INTERVAL_SECONDS' => [
                'env' => 'CRON_HOURLY_INTERVAL_SECONDS',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_ALERTS,
                'label' => 'Intervalle tâches horaires CRON',
                'hint' => 'Cadence des tâches online / réservoir / digest.',
                'default' => 3600,
                'min' => 300,
                'max' => 86400,
                'unit' => 's',
            ],
            'OTA_REQUIRE_AUTH' => [
                'env' => 'OTA_REQUIRE_AUTH',
                'type' => self::TYPE_BOOL,
                'group' => self::GROUP_SECURITY,
                'label' => 'Auth obligatoire OTA',
                'hint' => 'Exiger une clé API pour /ota/* (comme le mode strict HMAC).',
                'default' => false,
            ],
            'FIRMWARE_RATE_LIMIT_MAX' => [
                'env' => 'FIRMWARE_RATE_LIMIT_MAX',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_SECURITY,
                'label' => 'Rate limit firmware (max)',
                'hint' => '0 = désactivé. Nombre max de POST par IP et par fenêtre.',
                'default' => 0,
                'min' => 0,
                'max' => 5000,
            ],
            'FIRMWARE_RATE_LIMIT_WINDOW' => [
                'env' => 'FIRMWARE_RATE_LIMIT_WINDOW',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_SECURITY,
                'label' => 'Rate limit firmware (fenêtre)',
                'hint' => 'Fenêtre en secondes pour le rate limit POST firmware.',
                'default' => 60,
                'min' => 10,
                'max' => 3600,
                'unit' => 's',
            ],
            'GALLERY_UPLOAD_RATE_LIMIT_SECONDS' => [
                'env' => 'GALLERY_UPLOAD_RATE_LIMIT_SECONDS',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_SECURITY,
                'label' => 'Intervalle upload galerie',
                'hint' => 'Délai minimum entre deux uploads photo par IP. 0 = désactivé.',
                'default' => 10,
                'min' => 0,
                'max' => 3600,
                'unit' => 's',
            ],
            'GALLERY_SYNC_ONLINE_WINDOW_SECONDS' => [
                'env' => 'GALLERY_SYNC_ONLINE_WINDOW_SECONDS',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_SECURITY,
                'label' => 'Fenêtre sync galerie « en ligne »',
                'hint' => 'Durée pendant laquelle une caméra est considérée en ligne après upload.',
                'default' => 1500,
                'min' => 60,
                'max' => 7200,
                'unit' => 's',
            ],
            'FIRMWARE_STATE_REQUIRE_KEY' => [
                'env' => 'FIRMWARE_STATE_REQUIRE_KEY',
                'type' => self::TYPE_BOOL,
                'group' => self::GROUP_SECURITY,
                'label' => 'Clé API sur GET état sorties',
                'hint' => 'Exiger api_key sur les lectures d’état firmware.',
                'default' => false,
            ],
            'STATS_EXCLUDE_DHT_FALLBACK' => [
                'env' => 'STATS_EXCLUDE_DHT_FALLBACK',
                'type' => self::TYPE_BOOL,
                'group' => self::GROUP_DATA_QUALITY,
                'label' => 'Masquer fallback DHT 20°C/50%',
                'hint' => 'Exclut les valeurs de repli DHT des graphiques (données conservées en BDD).',
                'default' => false,
            ],
            'LOG_LEVEL' => [
                'env' => 'LOG_LEVEL',
                'type' => self::TYPE_ENUM,
                'group' => self::GROUP_MAINTENANCE,
                'label' => 'Niveau de log',
                'hint' => 'Pris en compte au prochain processus PHP (requête ou CRON).',
                'default' => 'DEBUG',
                'options' => ['DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL'],
            ],
            'LOG_MASK_IP' => [
                'env' => 'LOG_MASK_IP',
                'type' => self::TYPE_BOOL,
                'group' => self::GROUP_MAINTENANCE,
                'label' => 'Masquer IP dans les logs',
                'hint' => 'RGPD : masque le dernier octet IPv4 dans cronlog et journal HMAC.',
                'default' => true,
            ],
            'LOG_ROTATE_DAYS' => [
                'env' => 'LOG_ROTATE_DAYS',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_MAINTENANCE,
                'label' => 'Rotation cronlog (jours)',
                'hint' => '0 = pas de rotation. Pris en compte au prochain processus.',
                'default' => 14,
                'min' => 0,
                'max' => 90,
                'unit' => 'j',
            ],
            'HMAC_AUDIT_ROTATE_DAYS' => [
                'env' => 'HMAC_AUDIT_ROTATE_DAYS',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_MAINTENANCE,
                'label' => 'Rotation journal HMAC (jours)',
                'hint' => '0 = pas de rotation. Repli sur LOG_ROTATE_DAYS si absent du .env.',
                'default' => 14,
                'min' => 0,
                'max' => 90,
                'unit' => 'j',
            ],
            'SIG_VALID_WINDOW' => [
                'env' => 'SIG_VALID_WINDOW',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_SECURITY,
                'label' => 'Fenêtre validité HMAC',
                'hint' => 'Tolérance horloge NTP firmware↔serveur. Trop court = 401 en masse.',
                'default' => 300,
                'min' => 60,
                'max' => 3600,
                'unit' => 's',
            ],
            'FLOOD_DEBOUNCE_SEC' => [
                'env' => 'FLOOD_DEBOUNCE_SEC',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_FLOOD,
                'label' => 'Inondation — anti-rebond',
                'hint' => 'Délai avant confirmation alerte crue FFP3.',
                'default' => 300,
                'min' => 0,
                'max' => 3600,
                'unit' => 's',
            ],
            'FLOOD_COOLDOWN_SEC' => [
                'env' => 'FLOOD_COOLDOWN_SEC',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_FLOOD,
                'label' => 'Inondation — cooldown mail',
                'hint' => 'Délai minimum entre deux mails d’alerte crue.',
                'default' => 3600,
                'min' => 0,
                'max' => 86400,
                'unit' => 's',
            ],
            'FLOOD_HYST_CM' => [
                'env' => 'FLOOD_HYST_CM',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_FLOOD,
                'label' => 'Inondation — hystérésis',
                'hint' => 'Marge en cm pour réarmer l’alerte.',
                'default' => 2.0,
                'min' => 0,
                'max' => 50,
                'unit' => 'cm',
            ],
            'FLOOD_RESET_STABLE_SEC' => [
                'env' => 'FLOOD_RESET_STABLE_SEC',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_FLOOD,
                'label' => 'Inondation — stabilité reset',
                'hint' => 'Durée de niveau stable avant reset de l’alerte.',
                'default' => 900,
                'min' => 0,
                'max' => 86400,
                'unit' => 's',
            ],
            'FLOOD_MAX_READING_AGE_SEC' => [
                'env' => 'FLOOD_MAX_READING_AGE_SEC',
                'type' => self::TYPE_INT,
                'group' => self::GROUP_FLOOD,
                'label' => 'Inondation — âge max mesure',
                'hint' => 'Ignorer les mesures plus anciennes pour l’alerte crue.',
                'default' => 600,
                'min' => 60,
                'max' => 86400,
                'unit' => 's',
            ],
            'CLEAN_MIN_TEMP_EAU' => [
                'env' => 'CLEAN_MIN_TEMP_EAU',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_DATA_QUALITY,
                'label' => 'Nettoyage — TempEau min',
                'hint' => 'Valeurs sous ce seuil sont mises à NULL (nettoyage CRON).',
                'default' => 3.0,
                'min' => -10,
                'max' => 40,
                'unit' => '°C',
            ],
            'CLEAN_MAX_TEMP_EAU' => [
                'env' => 'CLEAN_MAX_TEMP_EAU',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_DATA_QUALITY,
                'label' => 'Nettoyage — TempEau max',
                'hint' => 'Valeurs au-dessus sont mises à NULL.',
                'default' => 50.0,
                'min' => 10,
                'max' => 80,
                'unit' => '°C',
            ],
            'CLEAN_MIN_TEMP_AIR' => [
                'env' => 'CLEAN_MIN_TEMP_AIR',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_DATA_QUALITY,
                'label' => 'Nettoyage — TempAir min',
                'hint' => 'Température air minimale plausible ; en dessous → NULL.',
                'default' => 3.0,
                'min' => -30,
                'max' => 40,
                'unit' => '°C',
            ],
            'CLEAN_MIN_HUMIDITE' => [
                'env' => 'CLEAN_MIN_HUMIDITE',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_DATA_QUALITY,
                'label' => 'Nettoyage — Humidité min',
                'hint' => 'Humidité minimale plausible ; en dessous → NULL.',
                'default' => 3.0,
                'min' => 0,
                'max' => 100,
                'unit' => '%',
            ],
            'CLEAN_MIN_EAU_AQUARIUM' => [
                'env' => 'CLEAN_MIN_EAU_AQUARIUM',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_DATA_QUALITY,
                'label' => 'Nettoyage — EauAquarium min',
                'hint' => 'Niveau aquarium (mm) trop bas → NULL.',
                'default' => 40.0,
                'min' => 0,
                'max' => 500,
                'unit' => 'mm',
            ],
            'CLEAN_MAX_EAU_AQUARIUM' => [
                'env' => 'CLEAN_MAX_EAU_AQUARIUM',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_DATA_QUALITY,
                'label' => 'Nettoyage — EauAquarium max',
                'hint' => 'Niveau aquarium (mm) trop haut → NULL.',
                'default' => 700.0,
                'min' => 100,
                'max' => 2000,
                'unit' => 'mm',
            ],
            'CLEAN_MIN_EAU_RESERVE' => [
                'env' => 'CLEAN_MIN_EAU_RESERVE',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_DATA_QUALITY,
                'label' => 'Nettoyage — EauReserve min',
                'hint' => 'Niveau réserve (mm) trop bas → NULL.',
                'default' => 15.0,
                'min' => 0,
                'max' => 500,
                'unit' => 'mm',
            ],
            'CLEAN_MAX_EAU_RESERVE' => [
                'env' => 'CLEAN_MAX_EAU_RESERVE',
                'type' => self::TYPE_FLOAT,
                'group' => self::GROUP_DATA_QUALITY,
                'label' => 'Nettoyage — EauReserve max',
                'hint' => 'Niveau réserve (mm) trop haut → NULL.',
                'default' => 1000.0,
                'min' => 100,
                'max' => 3000,
                'unit' => 'mm',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function groupLabels(): array
    {
        return [
            self::GROUP_ALERTS => 'Alertes & supervision',
            self::GROUP_SECURITY => 'Sécurité firmware',
            self::GROUP_NOTIFICATIONS => 'Notifications globales',
            self::GROUP_MAINTENANCE => 'Maintenance & logs',
            self::GROUP_DATA_QUALITY => 'Qualité des données',
            self::GROUP_FLOOD => 'Alerte inondation (FFP3)',
        ];
    }

    public static function dbKey(string $envKey): string
    {
        return 'op_' . $envKey;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $envKey): ?array
    {
        return self::all()[$envKey] ?? null;
    }
}
