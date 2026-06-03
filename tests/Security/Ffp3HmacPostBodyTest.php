<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Security\Ffp3HmacPostBody;
use App\Security\SignatureValidator;
use PHPUnit\Framework\TestCase;

class Ffp3HmacPostBodyTest extends TestCase
{
    public function testFullUpdateBodyRoundTripAndHmac(): void
    {
        $referenceBody = 'api_key=testkey&sensor=esp32-wroom&version=13.89&TempAir=25.0&Humidite=60.0'
            . '&Pression=1013.0&TempEau=22.0&EauPotager=100&EauAquarium=200&EauReserve=150'
            . '&diffMaree=-1&Luminosite=300&etatPompeAqua=1&etatPompeTank=0&etatHeat=0&etatUV=1'
            . '&bouffeMatin=8&bouffeMidi=12&bouffeSoir=20&tempsGros=6&tempsPetits=6'
            . '&aqThreshold=1&tankThreshold=2005&chauffageThreshold=18.0'
            . '&tempsRemplissageSec=6&limFlood=6&WakeUp=0&FreqWakeUp=600'
            . '&bouffePetits=0&bouffeGros=0&mail=test@test.com&mailNotif=checked'
            . '&resetMode=0&tideEvent=none&tideTrend=0&tideNoiseMm=5&tideWindowMs=60000'
            . '&tideExtremeMm=10&configSynced=1&post_id=esp32-wroom-123-1';

        parse_str($referenceBody, $params);
        $this->assertIsArray($params);

        $rebuilt = Ffp3HmacPostBody::buildFromParams($params);
        $this->assertSame($referenceBody, $rebuilt);

        $secret = 'test-hmac-secret';
        $timestamp = time();
        $nonce = 'a1b2c3d4e5f67890';
        $sig = SignatureValidator::createSignatureForBody($timestamp, $nonce, $rebuilt, $secret);

        $this->assertTrue(SignatureValidator::isValidForBody(
            (string) $timestamp,
            $nonce,
            $rebuilt,
            $sig,
            $secret,
            300
        ));
    }

    public function testMeasurementBodyRoundTrip(): void
    {
        $referenceBody = 'api_key=k&sensor=esp32-wroom&version=13.89&TempAir=22.5&Humidite=55.0'
            . '&TempEau=21.0&EauPotager=10&EauAquarium=20&EauReserve=30&diffMaree=0'
            . '&Luminosite=100&etatPompeAqua=1&etatPompeTank=0&etatHeat=0&etatUV=0&resetMode=0';

        parse_str($referenceBody, $params);
        $rebuilt = Ffp3HmacPostBody::buildFromParams($params);
        $this->assertSame($referenceBody, $rebuilt);
    }

    public function testEmptyParamsReturnsEmptyString(): void
    {
        $this->assertSame('', Ffp3HmacPostBody::buildFromParams([]));
    }

    /** Extras GPIO 108/109 (nourrissage) : ordre alphabétique avant suffixes, comme FFP5CS v13.90. */
    public function testFullUpdateWithExtraKeys108109Sorted(): void
    {
        $referenceBody = 'api_key=testkey&sensor=esp32-wroom&version=13.90&TempAir=25.0&Humidite=60.0'
            . '&Pression=1013.0&TempEau=22.0&EauPotager=100&EauAquarium=200&EauReserve=150'
            . '&diffMaree=-1&Luminosite=300&etatPompeAqua=1&etatPompeTank=0&etatHeat=0&etatUV=1'
            . '&bouffeMatin=8&bouffeMidi=12&bouffeSoir=20&tempsGros=6&tempsPetits=6'
            . '&aqThreshold=1&tankThreshold=2005&chauffageThreshold=18.0'
            . '&tempsRemplissageSec=6&limFlood=6&WakeUp=0&FreqWakeUp=600'
            . '&bouffePetits=1&bouffeGros=1&mail=test@test.com&mailNotif=checked'
            . '&108=1&109=1'
            . '&resetMode=0&tideEvent=none&tideTrend=0&tideNoiseMm=5&tideWindowMs=60000'
            . '&tideExtremeMm=10&configSynced=1&post_id=esp32-wroom-456-2';

        parse_str($referenceBody, $params);
        $rebuilt = Ffp3HmacPostBody::buildFromParams($params);
        $this->assertSame($referenceBody, $rebuilt);
    }

    /** Override pompe réservoir : params parsés (une seule valeur) — le firmware ne doit plus dupliquer les clés sur le fil. */
    public function testParsedParamsOverrideEtatPompeTank(): void
    {
        $params = [
            'api_key' => 'testkey',
            'sensor' => 'esp32-wroom',
            'version' => '13.90',
            'TempAir' => '25.0',
            'Humidite' => '60.0',
            'Pression' => '1013.0',
            'TempEau' => '22.0',
            'EauPotager' => '100',
            'EauAquarium' => '200',
            'EauReserve' => '150',
            'diffMaree' => '-1',
            'Luminosite' => '300',
            'etatPompeAqua' => '1',
            'etatPompeTank' => '0',
            'etatHeat' => '0',
            'etatUV' => '1',
            'bouffeMatin' => '8',
            'bouffeMidi' => '12',
            'bouffeSoir' => '20',
            'tempsGros' => '6',
            'tempsPetits' => '6',
            'aqThreshold' => '1',
            'tankThreshold' => '2005',
            'chauffageThreshold' => '18.0',
            'tempsRemplissageSec' => '6',
            'limFlood' => '6',
            'WakeUp' => '0',
            'FreqWakeUp' => '600',
            'bouffePetits' => '0',
            'bouffeGros' => '0',
            'mail' => 'test@test.com',
            'mailNotif' => 'checked',
            'resetMode' => '0',
            'tideEvent' => 'none',
            'tideTrend' => '0',
            'tideNoiseMm' => '5',
            'tideWindowMs' => '60000',
            'tideExtremeMm' => '10',
            'configSynced' => '1',
            'post_id' => 'esp32-wroom-789-3',
        ];

        $rebuilt = Ffp3HmacPostBody::buildFromParams($params);
        $this->assertStringContainsString('etatPompeTank=0', $rebuilt);
        $this->assertSame(1, substr_count($rebuilt, 'etatPompeTank='));
    }
}
