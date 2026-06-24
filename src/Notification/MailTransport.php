<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Abstraction de transport e-mail.
 *
 * Découple NotificationService du mécanisme d'envoi concret. Deux implémentations
 * existent :
 *   - {@see SymfonyMailTransport} : SMTP via symfony/mailer (DSN issu de l'env) ;
 *   - {@see NativeMailTransport}  : repli sur la fonction PHP mail() (dev / CI / hôtes
 *     mutualisés sans serveur SMTP dédié).
 *
 * Le choix est centralisé par {@see MailTransportFactory::fromEnv()}.
 */
interface MailTransport
{
    /**
     * Envoie un e-mail multipart (HTML + texte brut).
     *
     * @param string $to       Destinataire (adresse, éventuellement « Nom <a@b.c> »)
     * @param string $from      Expéditeur (adresse, éventuellement « Nom <a@b.c> »)
     * @param string $subject   Sujet déjà formaté
     * @param string $htmlBody  Corps HTML
     * @param string $textBody  Corps en texte brut (repli pour les clients sans HTML)
     *
     * @return bool Vrai si l'e-mail a été remis au transport ; faux en cas d'échec
     */
    public function send(string $to, string $from, string $subject, string $htmlBody, string $textBody): bool;
}
