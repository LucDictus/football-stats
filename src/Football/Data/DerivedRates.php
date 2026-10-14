<?php

declare(strict_types=1);

namespace App\Football\Data;

/**
 * Success rates calculated from two counts of the same provider. Used for league and
 * Champions League data alike. A rate needs a minimum number of attempts: 2 out of 3 is
 * not a 67% aerial duel winner, so a small sample gives null (unknown), never a rate.
 */
final class DerivedRates
{
    /** rate field => [successes field, attempts: successes + this field | 'total:<field>', minimum attempts, goalkeepers only] */
    public const RATES = [
        'save_pct' => ['saves', 'plus:goals_conceded', 1, true],
        'aerial_win_pct' => ['aerial_duels_won', 'plus:aerial_duels_lost', 20, false],
        'tackle_success_pct' => ['tackles_won', 'total:tackles', 15, false],
    ];

    /**
     * @param array<string, float|null> $values field => value
     *
     * @return array<string, float|null> rate field => rate in % (or null)
     */
    public static function compute(array $values, bool $goalkeeper): array
    {
        $rates = [];
        foreach (self::RATES as $rate => [$successField, $attemptsSpec, $minimum, $goalkeepersOnly]) {
            [$kind, $field] = explode(':', $attemptsSpec, 2);
            $successes = $values[$successField] ?? null;
            $other = $values[$field] ?? null;
            $attempts = $successes === null || $other === null ? null : ($kind === 'plus' ? $successes + $other : $other);

            $rates[$rate] = ($goalkeepersOnly && !$goalkeeper) || $attempts === null || $attempts < $minimum || $attempts <= 0
                ? null
                : round($successes / $attempts * 100, 1);
        }

        return $rates;
    }
}
