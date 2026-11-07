<?php

declare(strict_types=1);

namespace App\Mcp\Timeline;

/**
 * Classification PROBABILISTE de la cause d'une perte de contact, à partir de preuves horodatées
 * rassemblées par {@see ContactTimelineBuilder}. Logique PURE (aucune BDD, aucune horloge
 * implicite) : chaque cause reçoit un score construit par des indices « pour » et « contre »
 * explicites, puis les scores sont normalisés en probabilités. Le résultat liste aussi ce que
 * les preuves ne permettent pas de trancher à distance.
 *
 * Causes : `ota` (téléchargement, flash, boot raté, rollback), `crash_reboot_loop`,
 * `wifi_network`, `clock_signature`, `power`, `server`.
 *
 * Les heuristiques sont volontairement lisibles et commentées plutôt qu'apprises : un
 * exploitant doit pouvoir contester chaque point. Pondérations issues de l'incident du
 * 06/11/2026 (docs/DIAGNOSTIC_FFP3_PERTE_CONTACT_2026-11-06.md).
 */
final class ContactLossClassifier
{
    public const CAUSES = ['ota', 'crash_reboot_loop', 'wifi_network', 'clock_signature', 'power', 'server'];

    /** @var array<string, string> */
    public const LABELS = [
        'ota' => 'Mise à jour OTA (téléchargement, flash, boot raté, rollback)',
        'crash_reboot_loop' => 'Crash ou boucle de redémarrage',
        'wifi_network' => 'Wi-Fi ou réseau du site',
        'clock_signature' => 'Horloge / signature HMAC',
        'power' => "Coupure d'alimentation",
        'server' => 'Défaut serveur',
    ];

    /**
     * @param array<string, mixed> $e Preuves (voir {@see ContactTimelineBuilder::evidence()}) :
     *   now, last_contact, silence_seconds, period, missed_wakes, offline (bool),
     *   requests_after_loss (int), rejected_after_loss (int),
     *   last_burst: {requests, rejected, signature_invalid, max_abs_offset},
     *   ota: {release_before_loss_seconds, release_version, metadata_fetch_before_loss_seconds,
     *         bin_download_before_loss_seconds, bin_complete, firmware_events (list<string>), last_ota_state},
     *   heartbeat: {last_reset_reason, crash_reasons (list<string>), reboots_delta, safe_mode, last_uptime},
     *   camera: {had_uploads_before, uploads_after_loss, last_upload_seconds_after_loss},
     *   server: {errors_5xx_after_loss, cron_late, other_devices_contacting},
     *   wifi: {fail_events, last_rssi},
     *   firmware: {seen_version, published_version}
     * @return array{
     *   offline: bool, verdict: string, causes: list<array{cause: string, label: string, probability: float, score: float, for: list<string>, against: list<string>}>,
     *   unverifiable: list<string>
     * }
     */
    public function classify(array $e): array
    {
        $offline = (bool) ($e['offline'] ?? false);
        $period = max(60, (int) ($e['period'] ?? 600));
        $scores = [];
        foreach (self::CAUSES as $cause) {
            $scores[$cause] = ['score' => 0.0, 'for' => [], 'against' => []];
        }
        $add = static function (string $cause, float $delta, string $reason) use (&$scores): void {
            $scores[$cause]['score'] += $delta;
            $scores[$cause][$delta >= 0 ? 'for' : 'against'][] = $reason;
        };

        $silence = (int) ($e['silence_seconds'] ?? 0);
        $after = (int) ($e['requests_after_loss'] ?? 0);
        $rejectedAfter = (int) ($e['rejected_after_loss'] ?? 0);
        $ota = (array) ($e['ota'] ?? []);
        $hb = (array) ($e['heartbeat'] ?? []);
        $cam = (array) ($e['camera'] ?? []);
        $srv = (array) ($e['server'] ?? []);
        $wifi = (array) ($e['wifi'] ?? []);
        $fw = (array) ($e['firmware'] ?? []);
        $burst = (array) ($e['last_burst'] ?? []);

        // ── OTA ─────────────────────────────────────────────────────────────────────
        $releaseAge = $ota['release_before_loss_seconds'] ?? null;
        if (is_int($releaseAge) && $releaseAge >= 0 && $releaseAge <= 24 * 3600) {
            $add('ota', 0.35, sprintf('version %s publiée %s avant le dernier contact', (string) ($ota['release_version'] ?? '?'), self::dur($releaseAge)));
            if (isset($fw['seen_version'], $ota['release_version']) && $fw['seen_version'] !== '' && version_compare((string) $fw['seen_version'], (string) $ota['release_version'], '<')) {
                $add('ota', 0.15, sprintf('le module tournait en %s : il devait se mettre à jour', (string) $fw['seen_version']));
            }
        } elseif ($releaseAge === null) {
            $add('ota', -0.25, 'aucune publication OTA dans les 24 h précédant la perte (ou publications non journalisées)');
        }
        $dl = $ota['bin_download_before_loss_seconds'] ?? null;
        if (is_int($dl) && $dl >= -300 && $dl <= 2 * $period) {
            $add('ota', (bool) ($ota['bin_complete'] ?? false) ? 0.4 : 0.25, sprintf('binaire %s %s avant le dernier contact', (bool) ($ota['bin_complete'] ?? false) ? 'téléchargé en entier' : 'partiellement téléchargé', self::dur(max(0, $dl))));
        } elseif ($dl === null && isset($ota['events_journaled']) && $ota['events_journaled'] === true) {
            $add('ota', -0.3, 'aucun téléchargement de binaire journalisé avant la perte');
        }
        $meta = $ota['metadata_fetch_before_loss_seconds'] ?? null;
        if (is_int($meta) && $meta >= 0 && $meta <= 600) {
            $add('ota', 0.1, sprintf('manifeste OTA consulté %s avant le dernier contact', self::dur($meta)));
        }
        $fwEvents = array_map('strval', (array) ($ota['firmware_events'] ?? []));
        if (in_array('start', $fwEvents, true) || in_array('done', $fwEvents, true)) {
            $add('ota', 0.3, 'le firmware a rapporté le début d\'une mise à jour (' . implode(', ', array_unique($fwEvents)) . ')');
            if (!in_array('marked_valid', $fwEvents, true)) {
                $add('ota', 0.15, 'aucun « marked_valid » reçu : la nouvelle image n\'a jamais été validée');
            }
        }
        if (in_array('rollback', $fwEvents, true) || in_array('fail', $fwEvents, true)) {
            $add('ota', 0.2, 'échec ou rollback OTA rapporté par le firmware');
        }
        if (in_array('marked_valid', $fwEvents, true) && !in_array('fail', $fwEvents, true)) {
            $add('ota', -0.3, 'la dernière image OTA a été validée par le firmware');
        }
        if (($hb['last_ota_state'] ?? null) === 'pending') {
            $add('ota', 0.1, 'dernier heartbeat : image OTA en attente de validation');
        }

        // ── Crash / boucle de reboot ────────────────────────────────────────────────
        $crashes = array_map('strval', (array) ($hb['crash_reasons'] ?? []));
        if ($crashes !== []) {
            $add('crash_reboot_loop', 0.4, 'redémarrage(s) sur crash rapporté(s) : ' . implode(', ', array_unique($crashes)));
        }
        $rebootsDelta = $hb['reboots_delta'] ?? null;
        if (is_int($rebootsDelta) && $rebootsDelta >= 2) {
            $add('crash_reboot_loop', 0.3, sprintf('%d redémarrages comptés sur la fenêtre', $rebootsDelta));
        } elseif (is_int($rebootsDelta) && $rebootsDelta === 0 && isset($hb['last_uptime']) && (int) $hb['last_uptime'] > 86400) {
            $add('crash_reboot_loop', -0.2, sprintf('uptime %s au dernier heartbeat, aucun reboot sur la fenêtre', self::dur((int) $hb['last_uptime'])));
        }
        if (($hb['safe_mode'] ?? false) === true) {
            $add('crash_reboot_loop', 0.2, 'le module s\'est annoncé en mode sans échec (boots ratés consécutifs)');
        }
        if (($hb['last_reset_reason'] ?? null) === 'POWERON') {
            $add('crash_reboot_loop', -0.15, 'dernier démarrage sur mise sous tension, pas sur crash');
        }
        if ($crashes === [] && $rebootsDelta === null) {
            $scores['crash_reboot_loop']['against'][] = 'aucune raison de boot rapportée (firmware antérieur à 15.34 ou aucun heartbeat depuis)';
        }

        // ── Wi-Fi / réseau ──────────────────────────────────────────────────────────
        $hadCam = (bool) ($cam['had_uploads_before'] ?? false);
        $camAfter = (int) ($cam['uploads_after_loss'] ?? 0);
        if ($hadCam && $camAfter === 0 && $silence > $period) {
            $add('wifi_network', 0.4, 'la caméra du même site a cessé d\'envoyer en même temps');
            $add('power', 0.3, 'la caméra du même site s\'est tue aussi (alimentation commune ?)');
        } elseif ($hadCam && $camAfter > 0) {
            $add('wifi_network', -0.4, sprintf('la caméra du même site a continué d\'envoyer (%d photo(s) après la perte) : réseau du site sain', $camAfter));
            $add('power', -0.15, 'la caméra du même site reste alimentée (alimentation séparée possible)');
            $add('server', -0.3, 'le serveur a continué de recevoir les photos du site');
        }
        $wifiFails = (int) ($wifi['fail_events'] ?? 0);
        if ($wifiFails >= 3) {
            $add('wifi_network', 0.2, sprintf('%d échecs Wi-Fi rapportés par le firmware sur la fenêtre', $wifiFails));
        }
        if (isset($wifi['last_rssi']) && is_int($wifi['last_rssi']) && $wifi['last_rssi'] <= -80) {
            $add('wifi_network', 0.1, sprintf('signal faible au dernier heartbeat (%d dBm)', $wifi['last_rssi']));
        }

        // ── Horloge / signature ─────────────────────────────────────────────────────
        $sigInvalid = (int) ($burst['signature_invalid'] ?? 0);
        $requests = (int) ($burst['requests'] ?? 0);
        $maxOffset = (int) ($burst['max_abs_offset'] ?? 0);
        if ($sigInvalid > 0) {
            $add('clock_signature', 0.3, sprintf('%d requête(s) de la dernière rafale rejetée(s) « signature invalide »%s', $sigInvalid, $maxOffset > 0 ? sprintf(', horloge décalée de %s', self::dur($maxOffset)) : ''));
        }
        if ($rejectedAfter > 0) {
            $add('clock_signature', 0.4, sprintf('%d requête(s) REÇUE(S) mais rejetée(s) depuis la perte : le module émet, le serveur refuse', $rejectedAfter));
            $add('server', 0.2, 'des requêtes arrivent encore mais sont refusées (configuration / fenêtre de signature ?)');
            $add('wifi_network', -0.5, 'le module joint encore le serveur');
            $add('power', -0.5, 'le module est alimenté et émet');
        } elseif ($after === 0 && $silence > $period) {
            $add('clock_signature', -0.35, 'plus AUCUNE requête, même rejetée, depuis la perte : un rejet de signature ne produit pas un silence total');
        }
        if ($requests > 0 && $sigInvalid === 0 && $maxOffset <= 120) {
            $add('clock_signature', -0.2, 'dernière rafale acceptée avec une horloge correcte');
        }

        // ── Alimentation ────────────────────────────────────────────────────────────
        if (($hb['last_reset_reason'] ?? null) === 'BROWNOUT') {
            $add('power', 0.3, 'dernier démarrage sur brown-out (tension insuffisante)');
        }
        if ($after === 0 && $silence > 2 * $period && $crashes === [] && !is_int($dl)) {
            $add('power', 0.15, 'silence total sans crash ni OTA journalisé : coupure possible');
        }

        // ── Serveur ─────────────────────────────────────────────────────────────────
        $errors = (int) ($srv['errors_5xx_after_loss'] ?? 0);
        if ($errors > 0) {
            $add('server', 0.5, sprintf('%d erreur(s) serveur 5xx sur les routes du module depuis la perte', $errors));
        }
        if (($srv['cron_late'] ?? false) === true) {
            $add('server', 0.2, 'le CRON serveur est en retard');
        }
        if (($srv['other_devices_contacting'] ?? false) === true) {
            $add('server', -0.3, 'd\'autres modules continuent d\'être reçus par le serveur');
        }
        if ($after === 0 && $errors === 0) {
            $add('server', -0.2, 'aucune requête de ce module n\'est arrivée : le serveur n\'a rien pu refuser');
        }

        // ── Normalisation ───────────────────────────────────────────────────────────
        $positives = [];
        foreach ($scores as $cause => $s) {
            $positives[$cause] = max(0.02, $s['score']);
        }
        $total = array_sum($positives);
        $causes = [];
        foreach ($scores as $cause => $s) {
            $causes[] = [
                'cause' => $cause,
                'label' => self::LABELS[$cause],
                'probability' => round($positives[$cause] / $total, 2),
                'score' => round($s['score'], 2),
                'for' => $s['for'],
                'against' => $s['against'],
            ];
        }
        usort($causes, static fn (array $a, array $b): int => $b['probability'] <=> $a['probability']);

        $unverifiable = [
            'État réel de l\'alimentation du module (tension, coupure brève) : aucune mesure à distance sans compteur ou watchdog externe.',
            'Association Wi-Fi côté site (le module voit-il le réseau ?) : seule la caméra du même site sert de témoin indirect.',
            'Console série / état du bootloader (rollback effectué ou non) : nécessite un accès physique ou un rapport OTA reçu au boot suivant.',
        ];
        if (!(bool) ($ota['events_journaled'] ?? false)) {
            $unverifiable[] = 'Téléchargements OTA antérieurs à l\'activation du journal ota_events : non reconstituables.';
        }
        if (($hb['last_reset_reason'] ?? null) === null) {
            $unverifiable[] = 'Raison du dernier redémarrage : non rapportée (firmware antérieur à 15.34 / 4.76 / 2.79 ou aucun heartbeat depuis).';
        }

        $top = $causes[0];
        $verdict = !$offline
            ? 'Module en ligne : aucune perte de contact à expliquer.'
            : sprintf(
                'Cause la plus probable : %s (%d %%)%s.',
                $top['label'],
                (int) round($top['probability'] * 100),
                isset($causes[1]) && $causes[1]['probability'] >= 0.25 ? sprintf(', puis %s (%d %%)', $causes[1]['label'], (int) round($causes[1]['probability'] * 100)) : ''
            );

        return ['offline' => $offline, 'verdict' => $verdict, 'causes' => $causes, 'unverifiable' => $unverifiable];
    }

    private static function dur(int $seconds): string
    {
        $seconds = abs($seconds);
        if ($seconds < 90) {
            return $seconds . ' s';
        }
        if ($seconds < 5400) {
            return (int) round($seconds / 60) . ' min';
        }

        return sprintf('%.1f h', $seconds / 3600);
    }
}
