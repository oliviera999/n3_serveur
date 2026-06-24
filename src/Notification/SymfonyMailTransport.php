<?php

declare(strict_types=1);

namespace App\Notification;

use App\Service\LogService;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Transport e-mail SMTP basé sur symfony/mailer, piloté par un DSN.
 *
 * Le DSN provient de l'environnement (SMTP_DSN, ou reconstruit depuis
 * SMTP_HOST/SMTP_PORT/SMTP_USER/SMTP_PASS/SMTP_ENCRYPTION — cf. .env.example).
 * Construit un message multipart (HTML + texte) et le remet au MTA SMTP.
 *
 * En cas d'échec d'envoi, l'exception est capturée, journalisée et false est
 * retourné : la robustesse de NotificationService (anti-spam, digest) est ainsi
 * préservée, et un repli éventuel reste possible côté appelant.
 */
final class SymfonyMailTransport implements MailTransport
{
    public function __construct(
        private readonly string $dsn,
        private readonly LogService $logger
    ) {
    }

    public function send(string $to, string $from, string $subject, string $htmlBody, string $textBody): bool
    {
        try {
            $transport = Transport::fromDsn($this->dsn);
            $mailer = new Mailer($transport);

            $email = (new Email())
                ->from(self::parseAddress($from))
                ->to(self::parseAddress($to))
                ->subject($subject)
                ->text($textBody)
                ->html($htmlBody);

            $mailer->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Échec de l\'envoi SMTP (symfony/mailer)', [
                'error' => $e->getMessage(),
                'to' => $to,
            ]);

            return false;
        }
    }

    /**
     * Convertit « Nom <adresse@exemple.fr> » ou « adresse@exemple.fr » en Address Symfony.
     */
    private static function parseAddress(string $value): Address
    {
        return Address::create(trim($value));
    }
}
