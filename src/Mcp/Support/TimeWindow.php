<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Mcp\ToolException;

/**
 * Fenêtre temporelle d'une requête d'outil et choix de l'intervalle d'agrégation.
 *
 * Bornes acceptées : date ISO 8601 (`2026-11-01`, `2026-11-01T08:00`, avec ou sans
 * fuseau), `now`, ou relatif à maintenant (`-30m`, `-12h`, `-7d`). Les dates sans fuseau
 * sont lues dans le fuseau applicatif (APP_TIMEZONE, configuré par Env::load()).
 *
 * Garde-fous hébergement mutualisé : fenêtre ≤ {@see MAX_SPAN_DAYS} jours, au plus
 * {@see MAX_POINTS} points par série (intervalle `auto` choisi en conséquence).
 */
final class TimeWindow
{
    public const MAX_SPAN_DAYS = 180;
    public const MAX_POINTS = 500;

    /** @var array<string, int> */
    public const INTERVALS = [
        '5min' => 300,
        '15min' => 900,
        '1h' => 3600,
        '6h' => 21600,
        '1d' => 86400,
    ];

    private function __construct(
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $to,
    ) {
    }

    public static function parse(
        ?string $from,
        ?string $to,
        int $defaultSpanSeconds = 86400,
        int $maxSpanDays = self::MAX_SPAN_DAYS,
        ?\DateTimeImmutable $now = null,
    ): self {
        $now ??= new \DateTimeImmutable('now');
        $end = ($to === null || trim($to) === '') ? $now : self::parseBound($to, $now, 'to');
        $start = ($from === null || trim($from) === '')
            ? $end->modify('-' . $defaultSpanSeconds . ' seconds')
            : self::parseBound($from, $now, 'from');

        if ($start >= $end) {
            throw new ToolException('« from » doit être strictement antérieur à « to ».', 'invalid_window');
        }
        $span = $end->getTimestamp() - $start->getTimestamp();
        if ($span > $maxSpanDays * 86400) {
            throw new ToolException(sprintf(
                'Fenêtre trop large (%d jours) : maximum %d jours. Réduisez la période.',
                (int) ceil($span / 86400),
                $maxSpanDays
            ), 'window_too_large');
        }

        return new self($start, $end);
    }

    public function spanSeconds(): int
    {
        return $this->to->getTimestamp() - $this->from->getTimestamp();
    }

    public function fromSql(): string
    {
        return $this->from->format('Y-m-d H:i:s');
    }

    public function toSql(): string
    {
        return $this->to->format('Y-m-d H:i:s');
    }

    /**
     * @return array{from: string, to: string, timezone: string}
     */
    public function describe(): array
    {
        return [
            'from' => $this->fromSql(),
            'to' => $this->toSql(),
            'timezone' => date_default_timezone_get(),
        ];
    }

    /**
     * Intervalle d'agrégation (secondes) pour ne pas dépasser {@see MAX_POINTS}.
     *
     * @return array{name: string, seconds: int}
     */
    public function resolveInterval(string $interval): array
    {
        $span = $this->spanSeconds();
        if ($interval === 'auto') {
            foreach (self::INTERVALS as $name => $seconds) {
                if ((int) ceil($span / $seconds) <= self::MAX_POINTS) {
                    return ['name' => $name, 'seconds' => $seconds];
                }
            }

            return ['name' => '1d', 'seconds' => 86400];
        }
        if (!isset(self::INTERVALS[$interval])) {
            throw new ToolException('Intervalle inconnu : ' . $interval, 'invalid_interval');
        }
        $seconds = self::INTERVALS[$interval];
        if ((int) ceil($span / $seconds) > self::MAX_POINTS) {
            throw new ToolException(sprintf(
                'L\'intervalle %s produirait plus de %d points sur cette fenêtre : choisissez un intervalle plus large ou « auto ».',
                $interval,
                self::MAX_POINTS
            ), 'too_many_points');
        }

        return ['name' => $interval, 'seconds' => $seconds];
    }

    private static function parseBound(string $value, \DateTimeImmutable $now, string $label): \DateTimeImmutable
    {
        $value = trim($value);
        if ($value === 'now') {
            return $now;
        }
        if (preg_match('/^-(\d{1,4})(m|h|d)$/', $value, $m) === 1) {
            $unit = ['m' => 'minutes', 'h' => 'hours', 'd' => 'days'][$m[2]];

            return $now->modify('-' . (int) $m[1] . ' ' . $unit);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/', $value) !== 1) {
            throw new ToolException(sprintf(
                '« %s » invalide (%s) : attendu une date ISO 8601, « now » ou un relatif comme -12h / -7d.',
                $label,
                $value
            ), 'invalid_date');
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new ToolException(sprintf('« %s » invalide : %s', $label, $value), 'invalid_date');
        }

        // Exprime toujours la borne dans le fuseau applicatif (reading_time est stocké en heure locale).
        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
