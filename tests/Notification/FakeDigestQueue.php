<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\DigestQueue;
use App\Notification\NotificationCategory;
use App\Notification\Severity;

/**
 * File de synthèse factice (tests) : accumule les entrées en mémoire, sans base.
 * Permet de vérifier le routage P3/P4 -> digest et le flush groupé.
 */
final class FakeDigestQueue implements DigestQueue
{
    /** @var list<array{severity: Severity, category: ?NotificationCategory, family: ?string, subject: string, message: string}> */
    public array $queued = [];

    /** @var list<array{severity_code: string, severity_label: string, severity_emoji: string, family: ?string, subject: string, message: string, count: int, last_seen: string}> */
    private array $pendingFlush;

    /**
     * @param bool $queueReturn Valeur de retour de queue() (false simule une base indisponible)
     */
    public function __construct(private bool $queueReturn = true)
    {
        $this->pendingFlush = [];
    }

    public function queue(
        Severity $severity,
        ?NotificationCategory $category,
        ?string $family,
        string $subject,
        string $message
    ): bool {
        $this->queued[] = compact('severity', 'category', 'family', 'subject', 'message');

        return $this->queueReturn;
    }

    public function pendingCount(): int
    {
        return count($this->queued);
    }

    /**
     * @param list<array{severity_code: string, severity_label: string, severity_emoji: string, family: ?string, subject: string, message: string, count: int, last_seen: string}> $items
     */
    public function primeFlush(array $items): void
    {
        $this->pendingFlush = $items;
    }

    public function flush(): array
    {
        $items = $this->pendingFlush;
        $this->pendingFlush = [];

        return $items;
    }
}
