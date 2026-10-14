<?php

declare(strict_types=1);

namespace App\Football\Assessment;

/**
 * What one ranking evaluates: which season and which competitions.
 *
 * Included: the five domestic leagues and the Champions League from the league phase on
 * (no qualifying rounds). Excluded: domestic cups, super cups, the Club World Cup and
 * international matches. See config/services.yaml for the values.
 */
final readonly class AssessmentScope
{
    /**
     * @param list<string>                        $leagues     competition codes
     * @param array{tournament: int, season: int} $sofascoreCl SofaScore ids of the CL season
     * @param array{league: int, season: int}     $fotmobCl    FotMob ids of the CL season
     */
    public function __construct(
        public string $season,
        public string $top5SeasonCode,
        public string $periodFrom,
        public string $periodTo,
        public array $leagues,
        public array $sofascoreCl,
        public array $fotmobCl,
    ) {
    }
}
