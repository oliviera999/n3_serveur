<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Transport e-mail de repli basé sur la fonction native PHP mail().
 *
 * Utilisé lorsqu'aucun serveur SMTP n'est configuré (développement, CI, hébergement
 * mutualisé avec MTA local). Envoie un message multipart/alternative (texte + HTML)
 * afin de conserver le rendu HTML tout en restant lisible sur les clients texte.
 *
 * NB : mail() est appelée de façon NON qualifiée pour permettre son interception en
 * test via une surcharge App\Notification\mail() (aucun MTA réel nécessaire).
 */
final class NativeMailTransport implements MailTransport
{
    public function send(string $to, string $from, string $subject, string $htmlBody, string $textBody): bool
    {
        $boundary = 'n3bnd_' . bin2hex(random_bytes(8));

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'From: ' . $from,
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ]);

        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $textBody . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n\r\n"
            . '--' . $boundary . "--\r\n";

        return mail($to, $subject, $body, $headers);
    }
}
