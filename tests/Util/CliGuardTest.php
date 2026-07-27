<?php

declare(strict_types=1);

namespace Tests\Util;

use App\Util\CliGuard;
use PHPUnit\Framework\TestCase;

/**
 * Table de decision de la garde CLI (audit 2026-07, S11).
 *
 * Le point sensible est `cgi-fcgi` : c'est a la fois le SAPI de `php-cgi`
 * lance par une crontab d'hebergement mutualise (a autoriser) et celui de
 * PHP-FPM servant une requete HTTP (a refuser). Seule la presence de marqueurs
 * `$_SERVER` positionnes par le serveur web permet de trancher.
 */
final class CliGuardTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string, mixed>, bool}>
     */
    public static function sapiProvider(): array
    {
        return [
            'shell CLI' => ['cli', [], true],
            'phpdbg' => ['phpdbg', [], true],
            'CLI avec un $_SERVER pollue' => ['cli', ['REQUEST_METHOD' => 'GET'], true],

            'mod_php (apache2handler)' => ['apache2handler', ['REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '1.2.3.4'], false],
            'serveur PHP integre' => ['cli-server', ['REQUEST_METHOD' => 'GET'], false],
            'PHP-FPM requete web' => ['cgi-fcgi', ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'iot.example'], false],
            'PHP-FPM requete web (REQUEST_URI seul)' => ['cgi-fcgi', ['REQUEST_URI' => '/run-cron.php'], false],
            'fpm-fcgi requete web' => ['fpm-fcgi', ['SERVER_PROTOCOL' => 'HTTP/1.1'], false],
            'CGI requete web' => ['cgi', ['REMOTE_ADDR' => '203.0.113.4'], false],

            'crontab php-cgi (aucun marqueur HTTP)' => ['cgi-fcgi', [], true],
            'crontab php-cgi nu' => ['cgi', [], true],

            // Fail-closed : un SAPI web inconnu est refuse meme sans marqueur.
            'litespeed sans marqueur' => ['litespeed', [], false],
            'SAPI inconnu' => ['embed', [], false],
        ];
    }

    /**
     * @dataProvider sapiProvider
     * @param array<string, mixed> $server
     */
    public function testIsCli(string $sapi, array $server, bool $expected): void
    {
        $this->assertSame($expected, CliGuard::isCli($sapi, $server));
    }

    public function testEveryHttpMarkerAloneIsEnoughToRefuseCgi(): void
    {
        foreach (['REQUEST_METHOD', 'REQUEST_URI', 'SERVER_PROTOCOL', 'HTTP_HOST', 'REMOTE_ADDR'] as $marker) {
            $this->assertFalse(
                CliGuard::isCli('cgi-fcgi', [$marker => 'x']),
                "Le marqueur {$marker} doit suffire a refuser une execution CGI."
            );
        }
    }

    public function testDefaultsToCurrentProcess(): void
    {
        // La suite tourne en CLI : l'appel sans argument doit l'accepter.
        $this->assertTrue(CliGuard::isCli());
    }
}
