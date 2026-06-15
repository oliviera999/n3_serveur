<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Couvre le limiteur de débit à fenêtre glissante (anti-brute-force).
 */
final class RateLimiterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'n3_rl_test_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    public function testHitIncrementsWithinWindow(): void
    {
        $limiter = new RateLimiter($this->dir);

        $this->assertSame(1, $limiter->hit('ip:1.2.3.4', 60));
        $this->assertSame(2, $limiter->hit('ip:1.2.3.4', 60));
        $this->assertSame(3, $limiter->hit('ip:1.2.3.4', 60));
    }

    public function testKeysAreIsolated(): void
    {
        $limiter = new RateLimiter($this->dir);

        $limiter->hit('ip:1.1.1.1', 60);
        $limiter->hit('ip:1.1.1.1', 60);

        $this->assertSame(1, $limiter->hit('ip:2.2.2.2', 60));
    }

    public function testAttemptsDoesNotRecord(): void
    {
        $limiter = new RateLimiter($this->dir);

        $limiter->hit('ip:9.9.9.9', 60);
        $this->assertSame(1, $limiter->attempts('ip:9.9.9.9', 60));
        // Lecture seule : pas d'incrément
        $this->assertSame(1, $limiter->attempts('ip:9.9.9.9', 60));
    }

    public function testAttemptsForUnknownKeyIsZero(): void
    {
        $limiter = new RateLimiter($this->dir);

        $this->assertSame(0, $limiter->attempts('ip:inconnue', 60));
    }

    public function testZeroWindowExpiresImmediately(): void
    {
        $limiter = new RateLimiter($this->dir);

        $limiter->hit('ip:5.5.5.5', 0);
        // Avec une fenêtre nulle, toute occurrence passée est hors fenêtre.
        $this->assertSame(0, $limiter->attempts('ip:5.5.5.5', 0));
    }

    public function testClearResetsCounter(): void
    {
        $limiter = new RateLimiter($this->dir);

        $limiter->hit('ip:7.7.7.7', 60);
        $limiter->hit('ip:7.7.7.7', 60);
        $limiter->clear('ip:7.7.7.7');

        $this->assertSame(0, $limiter->attempts('ip:7.7.7.7', 60));
        $this->assertSame(1, $limiter->hit('ip:7.7.7.7', 60));
    }

    public function testFailOpenWhenStorageUnavailable(): void
    {
        // Pointer le stockage sur un fichier existant : mkdir échoue → fail-open.
        $file = tempnam(sys_get_temp_dir(), 'n3_rl_file_');
        $this->assertIsString($file);

        try {
            $limiter = new RateLimiter($file);
            // Jamais d'enfermement : chaque hit retourne 1 (pas de persistance).
            $this->assertSame(1, $limiter->hit('ip:1.2.3.4', 60));
            $this->assertSame(1, $limiter->hit('ip:1.2.3.4', 60));
            $this->assertSame(0, $limiter->attempts('ip:1.2.3.4', 60));
        } finally {
            @unlink($file);
        }
    }
}
