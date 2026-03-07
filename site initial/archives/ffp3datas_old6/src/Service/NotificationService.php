<?php

namespace App\Service;

class NotificationService
{
    private string $recipient;
    private string $from;

    public function __construct(private LogService $logger)
    {
        $this->recipient = $_ENV['NOTIF_EMAIL_RECIPIENT'] ?? 'user@example.com';
        $this->from      = $_ENV['MAIL_FROM'] ?? 'Aquaponie <noreply@example.com>';
    }

    /**
     * Envoie une notification simple par e-mail.
     *
     * @param string $recipient
     * @param string $subject
     * @param string $message
     * @return bool
     */
    private function sendMail(string $recipient, string $subject, string $message): bool
    {
        $headers = [
            'MIME-Version' => '1.0',
            'Content-type' => 'text/html; charset=utf-8',
            'From' => $this->from,
        ];

        $isSuccess = mail($recipient, $subject, $message, $headers);

        if ($isSuccess) {
            $this->logger->info("E-mail envoyé à {$recipient} avec le sujet : {$subject}");
        } else {
            $this->logger->error("Échec de l'envoi de l'e-mail à {$recipient}");
        }

        return $isSuccess;
    }

    /**
     * Notification pour le problème de marées (déviation standard faible).
     */
    public function notifyMareesProblem(): void
    {
        $subject = "Alerte système : problème de marées";
        $message = "Le système a détecté une déviation standard anormalement faible sur les mesures de niveau d'eau de l'aquarium, " .
                   "suggérant un problème avec les marées. La pompe a été mise en pause puis redémarrée.";

        $this->sendMail($this->recipient, $subject, $message);
    }

    /**
     * Notification pour le risque d'inondation (niveau d'eau aquarium trop haut).
     */
    public function notifyFloodRisk(): void
    {
        $subject = "Alerte système : risque d'inondation";
        $message = "Le niveau d'eau dans l'aquarium est dangereusement haut. La pompe de la réserve a été coupée pour éviter un débordement.";

        $this->sendMail($this->recipient, $subject, $message);
    }
} 