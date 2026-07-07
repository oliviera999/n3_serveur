<?php

declare(strict_types=1);

namespace App\Service\DerivedAlert;

/**
 * Décision d'une évaluation « valeur trop basse » (batterie, humidité du sol).
 */
enum LowValueDecision
{
    /** Rien à faire (état inchangé). */
    case None;
    /** Passage sous le seuil : lever l'alerte (une seule fois, latch). */
    case Raise;
    /** Retour au-dessus du seuil + hystérésis : ré-armer (et notifier si applicable). */
    case Clear;
}
