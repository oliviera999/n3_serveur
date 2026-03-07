<?php

namespace Tests\Service;

use App\Service\LogService;
use App\Service\SensorDataService;
use PDO;
use PHPUnit\Framework\TestCase;

class SensorDataServiceTest extends TestCase
{
    private PDO $pdo;
    private SensorDataService $service;

    protected function setUp(): void
    {
        // Rediriger les logs vers un flux mémoire pour les tests
        putenv('LOG_FILE_PATH=php://memory');

        // Config par défaut pour ne pas dépendre de l'environnement réel
        putenv('CLEAN_MIN_TEMP_EAU=3');
        putenv('CLEAN_MAX_TEMP_EAU=25');

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE ffp3Data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            TempEau REAL,
            TempAir REAL,
            Humidite REAL,
            EauAquarium REAL,
            EauReserve REAL
        )');

        // Valeur sous le seuil mini, valeur normale, valeur au-dessus du max
        $this->pdo->exec("INSERT INTO ffp3Data (TempEau) VALUES (2.0)");
        $this->pdo->exec("INSERT INTO ffp3Data (TempEau) VALUES (4.0)");
        $this->pdo->exec("INSERT INTO ffp3Data (TempEau) VALUES (30.0)");

        $logger = new LogService();
        $this->service = new SensorDataService($this->pdo, $logger);
    }

    public function testCleanAllSensorData(): void
    {
        $stats = $this->service->cleanAllSensorData();

        $stmt   = $this->pdo->query('SELECT TempEau FROM ffp3Data ORDER BY id');
        $values = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // La première et la troisième valeur doivent être NULL après nettoyage
        $this->assertNull($values[0]);
        $this->assertSame(4.0, (float) $values[1]);
        $this->assertNull($values[2]);

        // Vérifie que le comptage des valeurs nettoyées est correct
        $this->assertSame(1, $stats['TempEau_low']);
        $this->assertSame(1, $stats['TempEau_high']);
    }
} 