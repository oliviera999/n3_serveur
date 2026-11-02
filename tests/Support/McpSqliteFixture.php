<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Mcp\McpConfig;
use PDO;

/**
 * Base SQLite en mémoire pour les tests unitaires du connecteur MCP : tables OAuth
 * (équivalent SQLite de migrations/2026_11_mcp_oauth.sql), `n3_users`, données et
 * sorties FFP3 (prod + test), données N3PP / MSP1 / énergie minimales.
 */
final class McpSqliteFixture
{
    public const PASSWORD = 'motdepasse-test';

    public static function pdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        foreach (self::schema() as $sql) {
            $pdo->exec($sql);
        }
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $insert = $pdo->prepare('INSERT INTO n3_users (id, username, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([1, 'lecteur', $hash, 'reader', 1]);
        $insert->execute([2, 'operateur', $hash, 'operator', 1]);
        $insert->execute([3, 'admin', $hash, 'admin', 1]);
        $insert->execute([4, 'ancien', $hash, 'operator', 0]);

        return $pdo;
    }

    /**
     * @param array<string, scalar> $overrides
     */
    public static function config(array $overrides = []): McpConfig
    {
        return new McpConfig(array_merge([
            'MCP_ENABLED' => 'true',
            'MCP_PUBLIC_BASE_URL' => 'https://iot.example.test',
        ], $overrides));
    }

    public static function seedFfp3(PDO $pdo, string $table = 'ffp3Data', int $rows = 12): void
    {
        $stmt = $pdo->prepare("INSERT INTO {$table} (sensor, version, TempAir, Humidite, TempEau, EauAquarium, EauReserve, EauPotager, diffMaree, etatPompeAqua, mail, reading_time) VALUES ('ffp3', '15.31', ?, 60, ?, ?, 400, 300, ?, 1, 'secret@example.org', ?)");
        for ($i = $rows - 1; $i >= 0; $i--) {
            $stmt->execute([24 + $i * 0.1, 25 - $i * 0.05, 180 + ($i % 4) * 10, ($i % 2 === 0) ? 3 : -3, date('Y-m-d H:i:s', time() - $i * 600)]);
        }
    }

    public static function seedOutputs(PDO $pdo, string $table): void
    {
        $stmt = $pdo->prepare("INSERT INTO {$table} (board, gpio, name, state, requestTime, lastModifiedBy) VALUES ('1', ?, ?, ?, ?, 'esp32')");
        foreach ([
            [2, 'Radiateur', '0'], [15, 'Lumiere', '0'], [16, 'Pompe aquarium', '1'], [18, 'Pompe reserve', '0'],
            [100, 'mail', 'secret@example.org'], [101, 'mailNotif', 'checked'], [104, 'chauffageThreshold', '24'],
            [108, 'bouffePetits', '3'], [109, 'bouffeGros', '0'], [110, 'resetMode', '0'], [117, 'ForcePompeAquarium', '0'],
        ] as [$gpio, $name, $state]) {
            $stmt->execute([$gpio, $name, $state, date('Y-m-d H:i:s', time() - 3600)]);
        }
    }

    /**
     * @return list<string>
     */
    private static function schema(): array
    {
        $ffp3Data = static fn (string $t): string => "CREATE TABLE {$t} (id INTEGER PRIMARY KEY AUTOINCREMENT, sensor TEXT, version TEXT,"
            . ' TempAir REAL, Humidite REAL, TempEau REAL, EauPotager REAL, EauAquarium REAL, EauReserve REAL, diffMaree REAL,'
            . ' tideEvent TEXT, tideTrend INTEGER, tideNoiseMm INTEGER, tideWindowMs INTEGER, tideExtremeMm INTEGER, Luminosite REAL,'
            . ' etatPompeAqua INTEGER, etatPompeTank INTEGER, etatHeat INTEGER, etatUV INTEGER, bouffeMatin INTEGER, bouffeMidi INTEGER,'
            . ' bouffePetits INTEGER, bouffeGros INTEGER, aqThreshold INTEGER, tankThreshold INTEGER, chauffageThreshold REAL,'
            . ' mail TEXT, mailNotif TEXT, resetMode INTEGER, bouffeSoir INTEGER, tempsGros INTEGER, tempsPetits INTEGER,'
            . ' tempsRemplissageSec INTEGER, limFlood INTEGER, WakeUp INTEGER, FreqWakeUp INTEGER, configSynced INTEGER,'
            . ' Pression REAL, post_id TEXT, reading_time TEXT NOT NULL)';
        $outputs = static fn (string $t): string => "CREATE TABLE {$t} (id INTEGER PRIMARY KEY AUTOINCREMENT, board TEXT NOT NULL,"
            . ' gpio INTEGER NOT NULL, name TEXT, state TEXT, description TEXT, requestTime TEXT, lastModifiedBy TEXT)';
        $sensorOutputs = static fn (string $t): string => "CREATE TABLE {$t} (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT,"
            . ' board INTEGER NOT NULL, gpio INTEGER NOT NULL, state TEXT, requestTime TEXT)';
        $heartbeat = static fn (string $t): string => "CREATE TABLE {$t} (id INTEGER PRIMARY KEY AUTOINCREMENT, uptime INTEGER,"
            . ' freeHeap INTEGER, minHeap INTEGER, reboots INTEGER, rssi INTEGER, reading_time TEXT NOT NULL)';

        $sql = [
            $ffp3Data('ffp3Data'), $ffp3Data('ffp3Data2'),
            $outputs('ffp3Outputs'), $outputs('ffp3Outputs2'),
            $heartbeat('ffp3Heartbeat'), $heartbeat('ffp3Heartbeat2'),
            'CREATE TABLE n3ppData (id INTEGER PRIMARY KEY AUTOINCREMENT, sensor TEXT, version TEXT, TempAir REAL, Humidite REAL,'
                . ' Pression REAL, Luminosite REAL, Humid1 REAL, Humid2 REAL, Humid3 REAL, Humid4 REAL, HumidMoy REAL, PontDiv INTEGER,'
                . ' WakeUp INTEGER, ArrosageManu INTEGER, SeuilSec INTEGER, FreqWakeUp INTEGER, SeuilPontDiv INTEGER, mail TEXT,'
                . ' mailNotif TEXT, HeureArrosage INTEGER, resetMode INTEGER, etatPompe INTEGER, tempsArrosage INTEGER, bootCount INTEGER,'
                . ' reading_time TEXT NOT NULL)',
            $sensorOutputs('n3ppOutputs'), $sensorOutputs('n3ppOutputsTest'), $sensorOutputs('msp1Outputs'), $sensorOutputs('msp1OutputsTest'),
            'CREATE TABLE Boards (board TEXT PRIMARY KEY, last_request TEXT)',
            'CREATE TABLE n3_users (id INTEGER PRIMARY KEY, username TEXT UNIQUE, email TEXT, display_name TEXT, password_hash TEXT,'
                . " role TEXT, is_active INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT, last_login_at TEXT)",
            'CREATE TABLE notification_log (id INTEGER PRIMARY KEY AUTOINCREMENT, alert_key TEXT, severity TEXT, category TEXT,'
                . ' recipient TEXT, subject TEXT, sent_at TEXT)',
            'CREATE TABLE error_alerts (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT, normalized_message TEXT, context TEXT,'
                . ' created_at TEXT, alerted_at TEXT)',
            'CREATE TABLE mcp_oauth_clients (client_id TEXT PRIMARY KEY, client_name TEXT, redirect_uris TEXT NOT NULL,'
                . ' grant_types TEXT, token_endpoint_auth_method TEXT, software_id TEXT, created_at TEXT, last_used_at TEXT)',
            'CREATE TABLE mcp_oauth_cimd_cache (url_hash TEXT PRIMARY KEY, client_id TEXT, document TEXT, fetched_at TEXT, expires_at TEXT)',
            'CREATE TABLE mcp_oauth_codes (code_hash TEXT PRIMARY KEY, client_id TEXT, user_id INTEGER, redirect_uri TEXT, scope TEXT,'
                . ' resource TEXT, code_challenge TEXT, expires_at TEXT, used_at TEXT, created_at TEXT)',
            'CREATE TABLE mcp_oauth_tokens (token_hash TEXT PRIMARY KEY, token_type TEXT, family_id TEXT, client_id TEXT, user_id INTEGER,'
                . ' scope TEXT, resource TEXT, expires_at TEXT, revoked_at TEXT, created_at TEXT, last_used_at TEXT)',
            'CREATE TABLE mcp_control_plans (plan_id TEXT PRIMARY KEY, user_id INTEGER, client_id TEXT, env TEXT, family TEXT, gpio INTEGER,'
                . ' action TEXT, before_state TEXT, target_state TEXT, payload TEXT, expires_at TEXT, applied_at TEXT, created_at TEXT)',
        ];
        $sql[] = EnergieSqliteSchema::createTableSql('energieData');

        return $sql;
    }
}
