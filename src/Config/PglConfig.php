<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuration module Poissonglouton (supervision en ligne).
 */
final class PglConfig
{
    public const DEFAULT_BOARD_ID = 'poissonglouton';

    /** Active le calcul online/offline côté serveur et API health. */
    public const ONLINE_CHECK_ENABLED = true;

    /** Affiche le bandeau LIVE sur /pgl et la carte accueil (si health activé). */
    public const SHOW_ONLINE_STATUS_ON_PAGE = true;

    /** Seuil sans activité (heartbeat ou événement) avant statut hors ligne. */
    public const ONLINE_THRESHOLD_SECONDS = 300;
}
