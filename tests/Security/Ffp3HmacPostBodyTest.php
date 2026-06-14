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

    /** Corps full update sans EauAquarium (capteur invalide côté firmware). */
    public function testFullUpdateBodyOmitsMissingEauAquarium(): void
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
            'EauReserve' => '150',
            'diffMaree' => '0',
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
            'post_id' => 'esp32-wroom-no-aqua-1',
        ];

        $rebuilt = Ffp3HmacPostBody::buildFromParams($params);
        $this->assertStringNotContainsString('EauAquarium=', $rebuilt);
        $this->assertStringContainsString('EauReserve=150', $rebuilt);
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

    /**
     * Contrat HMAC : les champs flottants (TempAir/Humidite/Pression/TempEau/
     * chauffageThreshold) sont normalisés à 1 décimale (sprintf %.1f). Le firmware
     * signe avec la même normalisation ; toute divergence casserait la vérification
     * HMAC (401). Caractérise le comportement actuel pour éviter une dérive silencieuse.
     */
    public function testFloatFieldsNormalizedToOneDecimal(): void
    {
        parse_str(
            'api_key=k&sensor=esp32-wroom&version=14.0&TempAir=25.07&Humidite=60.4'
            . '&Pression=1013.25&TempEau=22.0&EauPotager=1&EauAquarium=2&EauReserve=3'
            . '&diffMaree=0&Luminosite=1&etatPompeAqua=1&etatPompeTank=0&etatHeat=0&etatUV=0'
            . '&chauffageThreshold=18.12&resetMode=0',
            $params
        );
        $rebuilt = Ffp3HmacPostBody::buildFromParams($params);

        $this->assertStringContainsString('TempAir=25.1', $rebuilt);
        $this->assertStringContainsString('Humidite=60.4', $rebuilt);
        // round-half-to-even (IEEE 754) : 1013.25 -> 1013.2 (et NON 1013.3). Le firmware
        // utilise le même snprintf("%.1f"), donc l'HMAC reste cohérent — on verrouille ce fait.
        $this->assertStringContainsString('Pression=1013.2', $rebuilt);
        $this->assertStringContainsString('chauffageThreshold=18.1', $rebuilt);
        $this->assertStringNotContainsString('chauffageThreshold=18.12', $rebuilt);
    }

    /**
     * Un champ rempli uniquement d'espaces est omis (comme un champ absent), car le
     * firmware ne l'inclut pas dans le corps signé. Évite un faux 401 par divergence.
     */
    public function testWhitespaceOnlyFieldIsOmitted(): void
    {
        parse_str(
            'api_key=k&sensor=esp32-wroom&version=14.0&TempAir=25.0&Humidite=60.0'
            . '&Pression=1013.0&TempEau=22.0&EauPotager=100&EauReserve=150'
            . '&diffMaree=0&Luminosite=300&etatPompeAqua=1&etatPompeTank=0&etatHeat=0&etatUV=1'
            . '&resetMode=0',
            $params
        );
        $params['EauAquarium'] = '   '; // espaces uniquement

        $rebuilt = Ffp3HmacPostBody::buildFromParams($params);

        $this->assertStringNotContainsString('EauAquarium=', $rebuilt);
        $this->assertStringContainsString('EauPotager=100', $rebuilt);
    }

    /** La reconstruction est déterministe : mêmes paramètres -> même corps (HMAC stable). */
    public function testBuildFromParamsIsDeterministic(): void
    {
        parse_str(
            'api_key=k&sensor=esp32-wroom&version=14.0&Pression=1013.0&bouffeMatin=8'
            . '&zeb=1&alpha=2&gamma=3&resetMode=0&post_id=x',
            $params
        );
        $a = Ffp3HmacPostBody::buildFromParams($params);
        $b = Ffp3HmacPostBody::buildFromParams($params);
        $this->assertSame($a, $b);
        // Les extras inconnus sont triés alphabétiquement entre champs principaux et suffixes.
        $this->assertStringContainsString('alpha=2&gamma=3&zeb=1', $a);
    }

    /**
     * Un extra à valeur vide (ou espaces) est OMIS du corps reconstruit, comme un champ
     * principal vide. Verrouille la symétrie avec le firmware Ffp3PostBody (qui omet désormais
     * aussi les extras vides) — évite un 401 HMAC si un extra vide transite.
     */
    public function testEmptyExtraValueIsOmitted(): void
    {
        $params = [
            'api_key' => 'testkey',
            'sensor' => 'esp32-wroom',
            'version' => '14.0',
            'Pression' => '1013.0',
            'bouffeMatin' => '8',
            'resetMode' => '0',
            'post_id' => 'test-xyz',
            'empty_extra' => '',        // extra vide
            'another_empty' => '   ',   // espaces uniquement (vide après trim)
            'valid_extra' => 'value',   // extra non vide
        ];

        $rebuilt = Ffp3HmacPostBody::buildFromParams($params);

        $this->assertStringNotContainsString('empty_extra=', $rebuilt);
        $this->assertStringNotContainsString('another_empty=', $rebuilt);
        $this->assertStringContainsString('valid_extra=value', $rebuilt);
        // Ordre : champs principaux -> extras triés -> suffixes.
        $this->assertStringContainsString('bouffeMatin=8&valid_extra=value&resetMode=0', $rebuilt);
    }
}
