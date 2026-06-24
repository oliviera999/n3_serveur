<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Contrat de file de synthèse (digest) des notifications de faible sévérité.
 *
 * Permet de découpler NotificationService de l'implémentation persistée
 * ({@see NotificationDigest}) et de substituer un double en test.
 */
interface DigestQueue
{
    /**
     * Empile une notification de faible sévérité en attente de synthèse.
     *
     * @return bool Vrai si la mise en file a réussi ; faux si la persistance est indisponible
     */
    public function queue(
        Severity $severity,
        ?NotificationCategory $category,
        ?string $family,
        string $subject,
        string $message
    ): bool;

    /**
     * Indique combien d'entrées sont en attente de synthèse.
     */
    public function pendingCount(): int;

    /**
     * Récupère et CONSOMME les entrées en attente, regroupées par (sévérité, famille, sujet).
     *
     * @return list<array{severity_code: string, severity_label: string, severity_emoji: string, family: ?string, subject: string, message: string, count: int, last_seen: string}>
     */
    public function flush(): array;
}
