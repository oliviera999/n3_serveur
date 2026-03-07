<?php

namespace App\Service;

/**
 * Service responsable de l'envoi de notifications par e-mail.
 * Permet d'alerter l'utilisateur ou l'administrateur en cas d'événements critiques (inondation, panne, etc.).
 * Utilise la fonction mail() de PHP et logge chaque tentative d'envoi.
 */
class NotificationService
{
    /**
     * Adresse e-mail du destinataire principal (configurable via .env)
     */
    private string $recipient;
    /**
     * Adresse e-mail d'expéditeur (configurable via .env)
     */
    private string $from;

    /**
     * @param LogService $logger Service de log pour tracer les notifications
     */
    public function __construct(private LogService $logger)
    {
        // Chargement des paramètres d'envoi depuis l'environnement
        $this->recipient = $_ENV['NOTIF_EMAIL_RECIPIENT'] ?? 'user@example.com';
        $this->from      = $_ENV['MAIL_FROM'] ?? 'Aquaponie <noreply@example.com>';
    }

    /**
     * Envoie une notification simple par e-mail (privé, utilisé par les méthodes publiques).
     *
     * @param string $recipient Destinataire
     * @param string $subject   Sujet du mail
     * @param string $message   Corps du message (HTML)
     * @return bool             Succès de l'envoi
     *
     * Utilise la fonction mail() de PHP. Logge le résultat (succès ou échec).
     */
    private function sendMail(string $recipient, string $subject, string $message): bool
    {
        // Construction de l'en-tête sous forme de chaîne car mail() n'accepte pas un tableau
        $headersArray = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . $this->from,
        ];

        $headersString = implode("\r\n", $headersArray);

        // Envoi du mail (attention : la fonction mail() peut être désactivée sur certains serveurs)
        $isSuccess = mail($recipient, $subject, $message, $headersString);

        // Log du résultat
        if ($isSuccess) {
            $this->logger->info("E-mail envoyé à {$recipient} avec le sujet : {$subject}");
        } else {
            $this->logger->error("Échec de l'envoi de l'e-mail à {$recipient}");
        }

        return $isSuccess;
    }

    /**
     * Notification pour le problème de marées (écart-type faible sur les mesures).
     * Appelée automatiquement par le système de surveillance.
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
     * Appelée automatiquement en cas de dépassement de seuil.
     */
    public function notifyFloodRisk(): void
    {
        $subject = "Alerte système : risque d'inondation";
        $message = "Le niveau d'eau dans l'aquarium est dangereusement haut. La pompe de la réserve a été coupée pour éviter un débordement.";

        $this->sendMail($this->recipient, $subject, $message);
    }

    /**
     * Notification pour absence prolongée de nouvelles données capteurs.
     */
    public function notifyNoSensorData(): void
    {
        $subject = "Alerte système : aucune donnée capteur disponible";
        $message = "Le système n'a enregistré aucune nouvelle donnée de capteur récemment. Veuillez vérifier la connexion ou le capteur.";

        $this->sendMail($this->recipient, $subject, $message);
    }

    /**
     * Notification pour système hors ligne (aucune donnée depuis un certain temps).
     */
    public function notifySystemOffline(): void
    {
        $subject = "Alerte système : système hors ligne";
        $message = "Le système ne semble plus transmettre de données depuis la période définie. Veuillez intervenir.";

        $this->sendMail($this->recipient, $subject, $message);
    }
}