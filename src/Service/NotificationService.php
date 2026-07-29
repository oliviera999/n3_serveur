<?php

declare(strict_types=1);

namespace App\Service;

use App\Notification\AlertThrottler;
use App\Notification\DigestQueue;
use App\Notification\EmailRenderer;
use App\Notification\MailTransport;
use App\Notification\MailTransportFactory;
use App\Notification\NotificationCategory;
use App\Notification\NotificationDigest;
use App\Notification\NotificationPolicy;
use App\Notification\NotificationPolicyResolver;
use App\Notification\Severity;

/**
 * Service responsable de l'envoi de notifications par e-mail.
 *
 * Chaque notification porte une SÉVÉRITÉ (P1..P4) et une CATÉGORIE (domaine).
 * Avant l'envoi, plusieurs filtres s'appliquent :
 *   1. la POLITIQUE (NotificationPolicy) — mode de verbosité configurable
 *      (none/important/partial/full) + catégories coupées ;
 *   2. l'ANTI-SPAM (AlertThrottler) — cooldown par clé d'alerte, persisté en base ;
 *   3. le DIGEST (NotificationDigest) — les alertes de faible sévérité (P3/P4) issues
 *      de sendAlert() sont accumulées et envoyées groupées (flushDigest()), tandis que
 *      les P1/P2 partent immédiatement.
 *
 * Le transport est abstrait ({@see MailTransport}) : SMTP via symfony/mailer lorsqu'un
 * DSN est configuré (SMTP_DSN / SMTP_HOST…), sinon repli gracieux sur mail() (dev/CI).
 * Le corps est rendu via des gabarits Twig HTML + texte ({@see EmailRenderer}).
 *
 * Le sujet est préfixé « [FAMILLE][Pn] » pour permettre le tri côté boîte mail.
 */
class NotificationService
{
    /** Adresse e-mail du destinataire principal (configurable via .env). */
    private string $recipient;
    /** Adresse e-mail d'expéditeur (configurable via .env). */
    private string $from;
    private NotificationPolicyResolver $policyResolver;
    private AlertThrottler $throttler;
    private MailTransport $transport;
    private EmailRenderer $renderer;
    private DigestQueue $digest;

    /**
     * @param LogService                      $logger         Service de log pour tracer les notifications
     * @param NotificationPolicy|null         $policy         Politique globale fixe (tests) — ignoré si $policyResolver est fourni
     * @param AlertThrottler|null             $throttler      Anti-spam + historique (défaut : auto)
     * @param MailTransport|null              $transport      Transport e-mail (défaut : choisi selon l'env)
     * @param EmailRenderer|null              $renderer       Rendu Twig HTML/texte (défaut : auto)
     * @param DigestQueue|null                $digest         File de synthèse P3/P4 (défaut : NotificationDigest)
     * @param NotificationPolicyResolver|null $policyResolver Résolution par famille (prod)
     */
    public function __construct(
        private LogService $logger,
        ?NotificationPolicy $policy = null,
        ?AlertThrottler $throttler = null,
        ?MailTransport $transport = null,
        ?EmailRenderer $renderer = null,
        ?DigestQueue $digest = null,
        ?NotificationPolicyResolver $policyResolver = null
    ) {
        $this->recipient = $_ENV['NOTIF_EMAIL_RECIPIENT'] ?? 'user@example.com';
        $this->from = $_ENV['MAIL_FROM'] ?? 'Aquaponie <noreply@example.com>';
        if ($policyResolver !== null) {
            $this->policyResolver = $policyResolver;
        } elseif ($policy !== null) {
            $this->policyResolver = NotificationPolicyResolver::fixed($policy);
        } else {
            $this->policyResolver = NotificationPolicyResolver::fromEnv(
                new \App\Repository\NotificationPolicyRepository(\App\Config\Database::getConnection())
            );
        }
        $this->throttler = $throttler ?? new AlertThrottler($logger);
        $this->transport = $transport ?? MailTransportFactory::fromEnv($logger);
        $this->renderer = $renderer ?? new EmailRenderer();
        $this->digest = $digest ?? new NotificationDigest($logger);
    }

    // ------------------------------------------------------------------
    // Cœur : politique + anti-spam + digest + formatage + envoi
    // ------------------------------------------------------------------

    /**
     * Applique la politique, l'anti-spam et le routage digest, formate, envoie et journalise.
     *
     * @param string      $subject     Objet de l'alerte (sert de titre et de corps texte source)
     * @param string      $message     Corps de l'alerte (texte brut, sans HTML)
     * @param string|null $throttleKey Clé d'anti-spam (null = pas de cooldown)
     * @param string|null $family      Famille d'appareils (FFP3, N3PP, MSP1…) pour le préfixe
     * @param int|null    $cooldown    Cooldown en secondes (défaut : selon la sévérité)
     * @param bool        $digestable  Si vrai, une P3/P4 est mise en file de synthèse au lieu d'un envoi immédiat
     *
     * @return bool Vrai si remis au transport (ou mis en file digest) ; faux si filtré/cooldown/échec
     */
    private function dispatch(
        Severity $severity,
        NotificationCategory $category,
        string $subject,
        string $message,
        ?string $throttleKey = null,
        ?string $family = null,
        ?int $cooldown = null,
        bool $digestable = false
    ): bool {
        $policy = $this->policyResolver->resolve($family);
        if (!$policy->shouldSend($severity, $category)) {
            $this->logger->info('Notification filtrée par la politique de notification', [
                'mode' => $policy->mode->value,
                'family' => $family,
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

        // Faible sévérité (P3/P4) et flux digestable : on accumule au lieu d'envoyer.
        if ($digestable && $severity->priority() >= Severity::Info->priority()) {
            $queued = $this->digest->queue($severity, $category, $family, $subject, $message);
            if ($queued) {
                $this->logger->info('Notification mise en file de synthèse (digest)', [
                    'severity' => $severity->value,
                    'subject' => $subject,
                ]);
                if ($throttleKey !== null) {
                    $this->throttler->record($throttleKey, $severity, $category, $this->recipient, $subject);
                }

                return true;
            }
            // Si la mise en file échoue (base indisponible), on bascule sur un envoi direct.
        }

        $formattedSubject = $this->formatSubject($severity, $subject, $family);
        $html = $this->renderer->renderAlertHtml($severity, $category, $family, $subject, $message);
        $text = $this->renderer->renderAlertText($severity, $category, $family, $subject, $message);
        $isSuccess = $this->sendMail($this->recipient, $formattedSubject, $html, $text);

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
     * Envoie un e-mail multipart (HTML + texte) via le transport configuré et journalise.
     */
    private function sendMail(string $recipient, string $subject, string $htmlBody, string $textBody): bool
    {
        $isSuccess = $this->transport->send($recipient, $this->from, $subject, $htmlBody, $textBody);

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
     *
     * Les alertes de faible sévérité (P3 Info / P4 Diagnostic) sont mises en file de
     * synthèse (digest) et regroupées lors du prochain flush ; les P1/P2 partent
     * immédiatement.
     */
    public function sendAlert(
        Severity $severity,
        NotificationCategory $category,
        string $family,
        string $subject,
        string $message,
        ?string $throttleKey = null
    ): bool {
        return $this->dispatch(
            $severity,
            $category,
            $subject,
            $message,
            $throttleKey,
            $family,
            null,
            true
        );
    }

    /**
     * Comme {@see self::sendAlert()}, mais JAMAIS mise en file de synthèse : l'e-mail part
     * immédiatement, y compris en P3/P4.
     *
     * Destiné aux notifications dont le sens dépend de l'instant où elles arrivent — au
     * premier chef les bascules de disponibilité pilotées par
     * {@see \App\Service\Availability\AvailabilityNotifier} (appareil perdu / retrouvé) :
     * regrouper un « de nouveau en ligne » dans un digest horaire le viderait de son intérêt.
     *
     * La politique de notification et l'anti-spam restent appliqués.
     */
    public function sendImmediateAlert(
        Severity $severity,
        NotificationCategory $category,
        string $family,
        string $subject,
        string $message,
        ?string $throttleKey = null
    ): bool {
        return $this->dispatch(
            $severity,
            $category,
            $subject,
            $message,
            $throttleKey,
            $family,
            null,
            false
        );
    }

    /**
     * Alerte personnalisée (rétro-compatibilité). Sévérité P2/Alerte, catégorie Système,
     * sans anti-spam dédié : l'appelant gère son propre throttling (ex. ErrorAlertService).
     */
    public function sendCustomAlert(string $subject, string $message): bool
    {
        return $this->dispatch(Severity::Alert, NotificationCategory::System, $subject, $message);
    }

    /** Adresse e-mail du destinataire principal (NOTIF_EMAIL_RECIPIENT). */
    public function recipient(): string
    {
        return $this->recipient;
    }

    /**
     * Envoie un e-mail de TEST au destinataire configuré.
     *
     * CONTOURNE volontairement la politique, l'anti-spam et le digest : le but est de
     * vérifier de bout en bout la configuration d'envoi (SMTP ou repli mail()), quel que
     * soit le mode de notification courant. Utilisé par le bouton « Tester l'envoi » des
     * pages de supervision.
     *
     * @param string|null $family Famille d'appareils (pour le préfixe de sujet)
     *
     * @return bool Vrai si l'e-mail a été remis au transport
     */
    public function sendTestMail(?string $family = null): bool
    {
        $subject = 'Test de configuration e-mail';
        $now = (new \DateTimeImmutable('now'))->format('d/m/Y H:i:s');
        $message = 'Ceci est un e-mail de test envoyé depuis la page de supervision '
            . '(FFP3 Datas v' . \App\Config\Version::get() . ') le ' . $now . ".\n\n"
            . "Si vous recevez ce message, l'envoi d'e-mails du serveur est fonctionnel.";

        $formattedSubject = $this->formatSubject(Severity::Info, $subject, $family);
        $html = $this->renderer->renderAlertHtml(Severity::Info, NotificationCategory::System, $family, $subject, $message);
        $text = $this->renderer->renderAlertText(Severity::Info, NotificationCategory::System, $family, $subject, $message);

        return $this->sendMail($this->recipient, $formattedSubject, $html, $text);
    }

    /**
     * Vide la file de synthèse : envoie un unique e-mail regroupant les alertes P3/P4
     * accumulées. À appeler sur un tick CRON ou en fin de run. No-op si la file est vide.
     *
     * @return bool Vrai si un e-mail de synthèse a été envoyé ; faux si rien à envoyer ou échec
     */
    public function flushDigest(): bool
    {
        $items = $this->digest->flush();
        if ($items === []) {
            return false;
        }

        $count = count($items);
        $subject = $this->formatDigestSubject($count);
        $html = $this->renderer->renderDigestHtml($items);
        $text = $this->renderer->renderDigestText($items);

        $isSuccess = $this->sendMail($this->recipient, $subject, $html, $text);
        if ($isSuccess) {
            // Ne purger la file QU'APRÈS un envoi réussi : si SMTP est KO, les alertes
            // P3/P4 restent en attente et seront réémises au prochain flush (aucune perte).
            // La suppression est portée par NotificationDigest (flush non destructif +
            // confirmFlush) ; les doubles de test (FakeDigestQueue) consomment dès flush().
            if ($this->digest instanceof NotificationDigest) {
                $this->digest->confirmFlush();
            }
            $this->logger->info('E-mail de synthèse (digest) envoyé', ['groupes' => $count]);
        }

        return $isSuccess;
    }

    /** Sujet de l'e-mail de synthèse : « [SYNTHÈSE] N notification(s) ». */
    private function formatDigestSubject(int $count): string
    {
        return sprintf('[SYNTHÈSE] %d notification(s) de faible sévérité', $count);
    }

    /**
     * Rapport de fin de transfert d'une galerie photo (uploadphotosserver).
     *
     * Envoi IMMEDIAT (jamais mis en file de synthèse, contrairement à sendAlert pour les P3) :
     * le résumé d'une session de sync est attendu dès la clôture du transfert. Catégorie Camera.
     * La sévérité reflète le résultat : P3/Info si tout est passé, P2/Alerte s'il y a eu des échecs.
     *
     * Reste soumis à la POLITIQUE de notification de la famille (GALLERY_*) et à l'anti-spam
     * (clé par session → un seul mail par session, les rejeux de clôture sont dédupliqués).
     *
     * @param string $family Famille de notification (GALLERY_MSP1 / GALLERY_N3PP / GALLERY_FFP3)
     *
     * @return bool Vrai si remis au transport ; faux si filtré/cooldown/échec
     */
    public function sendGalleryTransferReport(
        Severity $severity,
        string $family,
        string $subject,
        string $message,
        ?string $throttleKey = null
    ): bool {
        return $this->dispatch(
            $severity,
            NotificationCategory::Camera,
            $subject,
            $message,
            $throttleKey,
            $family,
            null,
            false
        );
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
