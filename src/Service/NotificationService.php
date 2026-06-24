<?php

declare(strict_types=1);

namespace App\Service;

use App\Notification\AlertThrottler;
use App\Notification\NotificationCategory;
use App\Notification\NotificationPolicy;
use App\Notification\Severity;

/**
 * Service responsable de l'envoi de notifications par e-mail.
 *
 * Chaque notification porte une SÉVÉRITÉ (P1..P4) et une CATÉGORIE (domaine).
 * Avant l'envoi, deux filtres s'appliquent :
 *   1. la POLITIQUE (NotificationPolicy) — mode de verbosité configurable
 *      (none/important/partial/full) + catégories coupées ;
 *   2. l'ANTI-SPAM (AlertThrottler) — cooldown par clé d'alerte, persisté en base.
 *
 * Le sujet est préfixé « [FAMILLE][Pn] » pour permettre le tri côté boîte mail.
 * Le transport reste mail() (cf. docs : migration SMTP prévue en phase ultérieure).
 */
class NotificationService
{
    /** Adresse e-mail du destinataire principal (configurable via .env). */
    private string $recipient;
    /** Adresse e-mail d'expéditeur (configurable via .env). */
    private string $from;
    private NotificationPolicy $policy;
    private AlertThrottler $throttler;

    /**
     * @param LogService              $logger    Service de log pour tracer les notifications
     * @param NotificationPolicy|null $policy    Politique de verbosité (défaut : depuis l'env)
     * @param AlertThrottler|null     $throttler Anti-spam + historique (défaut : auto)
     */
    public function __construct(
        private LogService $logger,
        ?NotificationPolicy $policy = null,
        ?AlertThrottler $throttler = null
    ) {
        $this->recipient = $_ENV['NOTIF_EMAIL_RECIPIENT'] ?? 'user@example.com';
        $this->from = $_ENV['MAIL_FROM'] ?? 'Aquaponie <noreply@example.com>';
        $this->policy = $policy ?? NotificationPolicy::fromEnv();
        $this->throttler = $throttler ?? new AlertThrottler($logger);
    }

    // ------------------------------------------------------------------
    // Cœur : politique + anti-spam + formatage + envoi
    // ------------------------------------------------------------------

    /**
     * Applique la politique de verbosité et l'anti-spam, formate le sujet, envoie et journalise.
     *
     * @param string      $message     Corps HTML déjà finalisé
     * @param string|null $throttleKey Clé d'anti-spam (null = pas de cooldown)
     * @param string|null $family      Famille d'appareils (FFP3, N3PP, MSP1…) pour le préfixe de sujet
     * @param int|null    $cooldown    Cooldown en secondes (défaut : selon la sévérité)
     *
     * @return bool Vrai si l'e-mail est remis au MTA ; faux si filtré, en cooldown ou échec
     */
    private function dispatch(
        Severity $severity,
        NotificationCategory $category,
        string $subject,
        string $message,
        ?string $throttleKey = null,
        ?string $family = null,
        ?int $cooldown = null
    ): bool {
        if (!$this->policy->shouldSend($severity, $category)) {
            $this->logger->info('Notification filtrée par la politique de notification', [
                'mode' => $this->policy->mode->value,
                'severity' => $severity->value,
                'category' => $category->value,
                'subject' => $subject,
            ]);

            return false;
        }

        if ($throttleKey !== null) {
            $cooldownSeconds = $cooldown ?? $severity->defaultCooldownSeconds();
            if (!$this->throttler->allow($throttleKey, $cooldownSeconds)) {
                $this->logger->info('Notification en cooldown anti-spam', [
                    'key' => $throttleKey,
                    'cooldown' => $cooldownSeconds,
                    'subject' => $subject,
                ]);

                return false;
            }
        }

        $formattedSubject = $this->formatSubject($severity, $subject, $family);
        $isSuccess = $this->sendMail($this->recipient, $formattedSubject, $message);

        if ($isSuccess && $throttleKey !== null) {
            $this->throttler->record($throttleKey, $severity, $category, $this->recipient, $formattedSubject);
        }

        return $isSuccess;
    }

    /** Construit le sujet préfixé « [FAMILLE][Pn] objet ». */
    private function formatSubject(Severity $severity, string $subject, ?string $family): string
    {
        $prefix = '';
        if ($family !== null && $family !== '') {
            $prefix .= '[' . strtoupper($family) . ']';
        }
        $prefix .= '[' . $severity->code() . ']';

        return trim($prefix . ' ' . $subject);
    }

    /**
     * Envoie une notification simple par e-mail. Utilise la fonction mail() de PHP
     * et logge le résultat (succès ou échec).
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

        $isSuccess = mail($recipient, $subject, $message, $headersString);

        if ($isSuccess) {
            $this->logger->info("E-mail envoyé à {$recipient} avec le sujet : {$subject}");
        } else {
            $this->logger->error("Échec de l'envoi de l'e-mail à {$recipient}");
        }

        return $isSuccess;
    }

    // ------------------------------------------------------------------
    // API de haut niveau (sévérité + catégorie + anti-spam)
    // ------------------------------------------------------------------

    /**
     * Envoie une alerte typée. Entrée privilégiée pour les nouveaux appels.
     * Le corps est passé dans nl2br() (texte simple -> HTML).
     */
    public function sendAlert(
        Severity $severity,
        NotificationCategory $category,
        string $family,
        string $subject,
        string $message,
        ?string $throttleKey = null
    ): bool {
        return $this->dispatch($severity, $category, $subject, nl2br($message), $throttleKey, $family);
    }

    /**
     * Alerte personnalisée (rétro-compatibilité). Sévérité P2/Alerte, catégorie Système,
     * sans anti-spam dédié : l'appelant gère son propre throttling (ex. ErrorAlertService).
     */
    public function sendCustomAlert(string $subject, string $message): bool
    {
        return $this->dispatch(Severity::Alert, NotificationCategory::System, $subject, nl2br($message));
    }

    /**
     * Notification pour le problème de marées (écart-type faible sur les mesures).
     * Appelée automatiquement par le système de surveillance.
     */
    public function notifyMareesProblem(): void
    {
        $message = "Le système a détecté une déviation standard anormalement faible sur les mesures de niveau d'eau de l'aquarium, " .
                   'suggérant un problème avec les marées. La pompe a été mise en pause puis redémarrée.';

        $this->dispatch(
            Severity::Critical,
            NotificationCategory::Hydraulic,
            'Problème de marées',
            $message,
            'ffp3:tide-problem',
            'FFP3'
        );
    }

    /**
     * Notification pour le risque d'inondation (niveau d'eau aquarium trop haut).
     * Appelée automatiquement en cas de dépassement de seuil.
     */
    public function notifyFloodRisk(): void
    {
        $message = "Le niveau d'eau dans l'aquarium est dangereusement haut. La pompe de la réserve a été coupée pour éviter un débordement.";

        $this->dispatch(
            Severity::Critical,
            NotificationCategory::Hydraulic,
            "Risque d'inondation",
            $message,
            'ffp3:flood-risk',
            'FFP3'
        );
    }

    /**
     * Notification pour absence prolongée de nouvelles données capteurs.
     */
    public function notifyNoSensorData(): void
    {
        $message = "Le système n'a enregistré aucune nouvelle donnée de capteur récemment. Veuillez vérifier la connexion ou le capteur.";

        $this->dispatch(
            Severity::Alert,
            NotificationCategory::Availability,
            'Aucune donnée capteur disponible',
            $message,
            'ffp3:no-sensor-data',
            'FFP3'
        );
    }

    /**
     * Notification pour système hors ligne (aucune donnée depuis un certain temps).
     */
    public function notifySystemOffline(): void
    {
        $message = 'Le système ne semble plus transmettre de données depuis la période définie. Veuillez intervenir.';

        $this->dispatch(
            Severity::Critical,
            NotificationCategory::Availability,
            'Système hors ligne',
            $message,
            'ffp3:offline',
            'FFP3'
        );
    }
}
