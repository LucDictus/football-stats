<?php

declare(strict_types=1);

namespace App;

final class View
{
    public static string $timezone = 'Europe/Amsterdam';

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /** GROUP_STAGE -> "Group stage", GROUP_A -> "Group A" */
    public static function label(?string $enum): string
    {
        if ($enum === null || $enum === '') {
            return '';
        }
        $text = ucfirst(strtolower(str_replace('_', ' ', $enum)));

        return preg_replace_callback('/\b([a-z])$/', static fn (array $m) => strtoupper($m[1]), $text);
    }

    public static function kickoff(string $utcDate): string
    {
        return (new \DateTimeImmutable($utcDate))
            ->setTimezone(new \DateTimeZone(self::$timezone))
            ->format('D j M, H:i');
    }

    public static function teamName(?array $team): string
    {
        return $team['shortName'] ?? $team['name'] ?? 'TBD';
    }

    public static function crest(?array $team, string $class = 'crest'): string
    {
        if (empty($team['crest'])) {
            return '<span class="' . self::e($class) . ' crest--empty"></span>';
        }

        return sprintf(
            '<img class="%s" src="%s" alt="" loading="lazy" width="24" height="24">',
            self::e($class),
            self::e($team['crest'])
        );
    }

    public static function isLive(array $match): bool
    {
        return in_array($match['status'], ['IN_PLAY', 'PAUSED', 'LIVE'], true);
    }

    public static function isUpcoming(array $match): bool
    {
        return in_array($match['status'], ['SCHEDULED', 'TIMED'], true);
    }

    /** Returns [home, away, note] or null when there is no score yet. */
    public static function score(array $match): ?array
    {
        $raw = $match['score'] ?? [];
        $score = Score::normalize($raw);

        if ($score['home'] === null || $score['away'] === null) {
            return null;
        }

        $note = match ($raw['duration'] ?? '') {
            'PENALTY_SHOOTOUT' => $score['penHome'] !== null
                ? sprintf('%d–%d on penalties', $score['penHome'], $score['penAway'])
                : '',
            'EXTRA_TIME' => 'after extra time',
            default => '',
        };

        return [$score['home'], $score['away'], $note];
    }

    /** Season object -> "2026/27" or "2026" for a single-year tournament. */
    public static function season(?array $season): string
    {
        if (empty($season['startDate'])) {
            return '';
        }
        $start = substr($season['startDate'], 0, 4);
        $end = substr($season['endDate'] ?? $season['startDate'], 0, 4);

        return $start === $end ? $start : $start . '/' . substr($end, 2);
    }

    public static function url(array $params): string
    {
        $merged = array_filter(array_merge($_GET, $params), static fn ($v) => $v !== null && $v !== '');

        return '?' . http_build_query($merged);
    }
}
