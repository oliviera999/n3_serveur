<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Concerns\HmacAuthTrait;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Base des contrôleurs POST firmware « legacy » (MSP1, N3PP) acceptant
 * l'authentification HMAC-SHA256 optionnelle avec repli sur la clé API.
 *
 * Factorise ce qui était dupliqué à l'identique dans Msp/N3pp :
 *   - validateAuth()           : délégation à HmacAuthTrait::validateHmacOrFallback()
 *   - sanitizeFirmwareEmail()  : validation/normalisation de l'email firmware
 *
 * Alignement contrat FFP3 (cf. App\Controller\Ffp3\PostDataController, qui garde
 * en revanche sa propre logique d'authentification).
 */
abstract class AbstractHmacPostDataController extends AbstractPostDataController
{
    use HmacAuthTrait;

    /**
     * Auth HMAC optionnelle (timestamp+signature) avec fallback api_key.
     *
     * @param array<string, mixed> $params
     */
    protected function validateAuth(array $params, Response $response): ?Response
    {
        return $this->validateHmacOrFallback($params, $response);
    }

    /**
     * Normalise l'email transmis par le firmware : null et chaîne vide sont
     * conservés tels quels ; un format invalide est journalisé puis ramené à ''
     * (non bloquant pour l'enregistrement de la mesure).
     *
     * @param array<string, mixed> $params
     */
    protected function sanitizeFirmwareEmail(array $params, \Closure $sanitize): ?string
    {
        $mail = $sanitize('mail');
        if ($mail !== null && $mail !== '' && filter_var($mail, FILTER_VALIDATE_EMAIL) === false) {
            $this->logger->warning($this->componentName() . ': email firmware invalide ignore (format)', [
                'sensor' => trim((string) ($params['sensor'] ?? '')),
                'version' => trim((string) ($params['version'] ?? '')),
                'mail_len' => strlen($mail),
            ]);
            $mail = '';
        }

        // Troncature 255 (comme FFP3) : une valeur trop longue faisait echouer
        // l'INSERT -> 500 et perte de TOUTE la ligne de mesure, pas juste du mail.
        if ($mail !== null && strlen($mail) > 255) {
            $mail = substr($mail, 0, 255);
        }

        return $mail;
    }

    /**
     * Normalise le champ `mailNotif` du firmware : tronque a 255 caracteres pour
     * eviter qu'une valeur trop longue ne fasse echouer l'INSERT (perte de mesure).
     *
     * @param array<string, mixed> $params
     */
    protected function sanitizeFirmwareMailNotif(array $params, \Closure $sanitize): ?string
    {
        $mailNotif = $sanitize('mailNotif');
        if ($mailNotif !== null && strlen($mailNotif) > 255) {
            $mailNotif = substr($mailNotif, 0, 255);
        }

        return $mailNotif;
    }
}
