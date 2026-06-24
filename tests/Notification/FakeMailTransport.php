<?php

declare(strict_types=1);

namespace Tests\Notification;

use App\Notification\MailTransport;

/**
 * Transport e-mail factice pour les tests : capture chaque envoi en mémoire (aucun
 * MTA ni SMTP réel). La valeur de retour de send() est pilotable pour simuler un échec.
 */
final class FakeMailTransport implements MailTransport
{
    /** @var list<array{to: string, from: string, subject: string, html: string, text: string}> */
    public array $sent = [];

    public function __construct(private bool $returnValue = true)
    {
    }

    public function failNext(): void
    {
        $this->returnValue = false;
    }

    public function send(string $to, string $from, string $subject, string $htmlBody, string $textBody): bool
    {
        $this->sent[] = [
            'to' => $to,
            'from' => $from,
            'subject' => $subject,
            'html' => $htmlBody,
            'text' => $textBody,
        ];

        return $this->returnValue;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    /** @return array{to: string, from: string, subject: string, html: string, text: string}|null */
    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }
}
