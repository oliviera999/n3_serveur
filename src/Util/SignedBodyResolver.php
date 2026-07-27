<?php

declare(strict_types=1);

namespace App\Util;

use App\Middleware\RawPostBodyMiddleware;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Resout le corps brut signe par le firmware (contrat `X-Sig-*`) ET dit d'ou
 * il vient.
 *
 * Le « d'ou il vient » est le point : depuis le 5.1.12, le serveur suppose que
 * `php://input` est vide sous mod_php + `x-www-form-urlencoded` et reconstitue
 * le corps signe (`App\Security\Ffp3HmacPostBody`, repli souple de S1). Cette
 * supposition n'a jamais ete verifiee sur l'hebergement reel — elle a ete
 * deduite d'une serie de 401. Or la documentation PHP ne prevoit ce vidage que
 * pour `multipart/form-data` : depuis PHP 5.6, `php://input` est relisible pour
 * `x-www-form-urlencoded`, et slim/psr7 1.8.0 met de surcroit le flux en cache
 * (`php://temp`) precisement pour qu'il soit relisible
 * (`ServerRequestFactory::createFromGlobals()`).
 *
 * Journaliser la source a chaque POST signe tranche la question depuis les logs
 * de production, sans exposer le moindre endpoint de diagnostic :
 *
 *   - `raw_middleware` / `raw_stream` avec une signature VALIDE
 *     -> `php://input` fonctionne sur cet hebergement. La machinerie de
 *        reconstitution (`Ffp3HmacPostBody`, repli S1) devient inutile et peut
 *        etre supprimee, ainsi que F2 (parite de formatage firmware/serveur)
 *        cote n3_firmwires.
 *   - `empty` -> la supposition de 5.1.12 est confirmee, il faut garder le repli
 *     et arbitrer entre les options 1/2/3 de F2 (vecteurs temoins, condensat
 *     signe, ou JSON).
 *
 * Voir `docs/AUDIT_BUGS_2026-07.md` (S1) et `docs/AUTHENTICATION.md`.
 */
final class SignedBodyResolver
{
    /** Corps capture par RawPostBodyMiddleware avant toute consommation. */
    public const SOURCE_MIDDLEWARE = 'raw_middleware';

    /** Corps relu directement sur le flux de la requete (middleware sans effet). */
    public const SOURCE_STREAM = 'raw_stream';

    /** Corps introuvable : c'est le cas suppose par le correctif 5.1.12. */
    public const SOURCE_EMPTY = 'empty';

    /**
     * @return array{body: string, source: string}
     */
    public static function resolve(ServerRequestInterface $request): array
    {
        $captured = $request->getAttribute(RawPostBodyMiddleware::ATTRIBUTE);
        if (is_string($captured) && $captured !== '') {
            return ['body' => $captured, 'source' => self::SOURCE_MIDDLEWARE];
        }

        // Repli : l'attribut peut etre absent (middleware non monte sur ce
        // chemin) ou vide. Relire le flux ne peut que rapprocher du corps
        // reellement signe — jamais l'inverse.
        $stream = (string) $request->getBody();
        if ($stream !== '') {
            return ['body' => $stream, 'source' => self::SOURCE_STREAM];
        }

        return ['body' => '', 'source' => self::SOURCE_EMPTY];
    }
}
