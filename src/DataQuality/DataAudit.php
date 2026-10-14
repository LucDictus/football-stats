<?php

declare(strict_types=1);

namespace App\DataQuality;

use App\Football\Data\OriginStatus;

use App\Command\ImportFotmobCommand;
use App\Command\ImportSofascoreCommand;
use App\DataImport\Fotmob\FotmobClient;
use App\DataImport\Identity\ClMatchResult;
use App\DataImport\Identity\ClPlayerMatcher;
use App\DataImport\Identity\IdentityOverrides;
use App\DataImport\Sofascore\SofascoreClient;
use App\DataImport\Top5\Top5CsvReader;
use App\Football\Assessment\AssessmentScope;
use App\Football\Data\FieldCatalog;
use App\Football\Data\Origin;
use App\Repository\ImportBatchRepository;
use App\Repository\SourceRecordRepository;

/**
 * Phase 1 audit: what the imported data can and cannot tell us.
 *
 * Checks actual records, not just columns: presence (NULL = missing, never zero),
 * agreement between providers, and the link between league and Champions League players.
 */
final readonly class DataAudit
{
    /** Players below these minutes are too small a sample to judge coverage on. */
    public const LEAGUE_MIN_MINUTES = 900;
    public const CL_MIN_MINUTES = 180;
    private const EXAMPLES = 12;

    public function __construct(
        private ImportBatchRepository $batches,
        private SourceRecordRepository $records,
        private AssessmentScope $scope,
        private Top5StintMerger $merger,
        private ClPlayerMatcher $matcher,
        private IdentityOverrides $overrides,
    ) {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $season = $this->scope->season;
        $top5Batch = $this->batches->latest(Top5CsvReader::SOURCE, Top5CsvReader::DATASET, $season)
            ?? throw new \RuntimeException('No Top-5 dataset import yet: run app:import:top5');
        $clBatch = $this->batches->latest(SofascoreClient::SOURCE, ImportSofascoreCommand::DATASET, $season)
            ?? throw new \RuntimeException('No SofaScore CL import yet: run app:import:sofascore-cl');
        $fotmobBatch = $this->batches->latest(FotmobClient::SOURCE, ImportFotmobCommand::DATASET, $season);

        $league = $this->merger->merge($this->records->payloads($top5Batch));
        $clById = $this->records->payloads($clBatch);
        $cl = array_values($clById);
        $matches = $this->matcher->match($league, $clById, $this->overrides->sofascore());

        return [
            'scope' => $this->scope,
            'generated_at' => new \DateTimeImmutable(),
            'batches' => array_values(array_filter([$top5Batch, $clBatch, $fotmobBatch])),
            'league' => $this->leagueSummary($league),
            'origins' => $this->originSummary($league),
            'fields' => $this->fieldMatrix($league, $clById, $matches),
            'cl' => $this->clSummary($clById, $matches),
            'fotmob' => $fotmobBatch !== null ? $this->fotmobCrossCheck($cl, $this->records->payloads($fotmobBatch)) : null,
            'unavailable' => FieldCatalog::unavailable(),
        ];
    }

    /** @param list<MergedPlayerSeason> $league */
    private function leagueSummary(array $league): array
    {
        $regular = array_filter($league, static fn ($p) => $p->minutes >= self::LEAGUE_MIN_MINUTES);

        return [
            'players' => count($league),
            'regular' => count($regular),
            'stint_rows' => array_sum(array_map(static fn ($p) => $p->stints, $league)),
            'transferred' => count(array_filter($league, static fn ($p) => $p->stints > 1)),
            'goalkeepers' => count(array_filter($league, static fn ($p) => $p->goalkeeper)),
            'regular_goalkeepers' => count(array_filter($regular, static fn ($p) => $p->goalkeeper)),
        ];
    }

    /**
     * Status counts per provider, and the most important affected players (most minutes first).
     *
     * @param list<MergedPlayerSeason> $league
     */
    private function originSummary(array $league): array
    {
        usort($league, static fn ($a, $b) => $b->minutes <=> $a->minutes);
        $summary = [];
        foreach ([Origin::Understat, Origin::Sofascore] as $origin) {
            $row = ['counts' => [], 'regular_counts' => [], 'examples' => []];
            foreach (OriginStatus::cases() as $status) {
                $row['counts'][$status->value] = 0;
                $row['regular_counts'][$status->value] = 0;
                $row['examples'][$status->value] = [];
            }
            foreach ($league as $player) {
                $check = $player->check($origin);
                ++$row['counts'][$check->status->value];
                if ($player->minutes >= self::LEAGUE_MIN_MINUTES) {
                    ++$row['regular_counts'][$check->status->value];
                    if ($check->status !== OriginStatus::Ok && count($row['examples'][$check->status->value]) < self::EXAMPLES) {
                        $row['examples'][$check->status->value][] = [
                            'player' => $player->name,
                            'teams' => implode(' / ', $player->teams),
                            'minutes' => $player->minutes,
                            'reason' => $check->reason,
                        ];
                    }
                }
            }
            $summary[$origin->value] = $row;
        }

        return $summary;
    }

    /**
     * Per field: league presence and trust among regular players, CL presence.
     *
     * @param list<MergedPlayerSeason>            $league
     * @param array<string, array<string, mixed>> $cl
     */
    private function fieldMatrix(array $league, array $cl, ClMatchResult $matches): array
    {
        $regular = array_values(array_filter($league, static fn ($p) => $p->minutes >= self::LEAGUE_MIN_MINUTES));
        $clRegular = array_filter($cl, static fn ($r) => ($r['minutesPlayed'] ?? 0) >= self::CL_MIN_MINUTES);
        $clIsKeeper = [];
        foreach ($clRegular as $externalId => $r) {
            $clIsKeeper[$externalId] = $matches->linkFor($externalId)['player']->goalkeeper ?? ($r['saves'] ?? 0) > 0;
        }

        $rows = [];
        foreach (FieldCatalog::all() as $field) {
            $population = array_values(array_filter($regular, static fn ($p) => !$field->goalkeeper || $p->goalkeeper));
            $present = count(array_filter($population, static fn ($p) => $p->value($field->key) !== null));
            $trusted = count(array_filter($population, static fn ($p) => $p->trusted($field->key)));
            $partial = count(array_filter($population, static fn ($p) => $p->value($field->key) !== null && $p->fieldStatus($field->key) === OriginStatus::Partial));

            $clRow = null;
            if ($field->clColumn !== null) {
                $clPopulation = array_filter($clRegular, static fn ($r, $id) => !$field->goalkeeper || $clIsKeeper[$id], ARRAY_FILTER_USE_BOTH);
                $clNull = array_values(array_filter($clPopulation, static fn ($r) => ($r[$field->clColumn] ?? null) === null));
                $clRow = ['population' => count($clPopulation), 'missing' => count($clNull)];
            }

            $trustedPct = $population !== [] ? round($trusted / count($population) * 100, 1) : null;
            $rows[] = [
                'field' => $field,
                'population' => count($population),
                'present_pct' => $population !== [] ? round($present / count($population) * 100, 1) : null,
                'trusted_pct' => $trustedPct,
                'partial' => $partial,
                'cl' => $clRow,
                'verdict' => self::verdict($trustedPct, $clRow, $field->origin === Origin::Derived),
            ];
        }

        return $rows;
    }

    /** @param array{population: int, missing: int}|null $cl */
    private static function verdict(?float $trustedPct, ?array $cl, bool $derived): string
    {
        $league = match (true) {
            $trustedPct === null => 'no data',
            $trustedPct >= 95 => 'use',
            $trustedPct >= 85 => 'use, handle missing',
            default => 'caution',
        };
        $clText = match (true) {
            $cl === null && $derived => 'CL: derived the same way',
            $cl === null => 'no CL source',
            $cl['population'] === 0 => 'no CL data',
            $cl['missing'] / $cl['population'] <= 0.05 => 'CL: use',
            default => 'CL: handle missing',
        };

        return "{$league}; {$clText}";
    }

    /** @param array<string, array<string, mixed>> $clById */
    private function clSummary(array $clById, ClMatchResult $matches): array
    {
        $teams = [];
        foreach ($clById as $externalId => $r) {
            $name = $r['team']['name'];
            $teams[$name] ??= ['team' => $name, 'players' => 0, 'linked' => [], 'unlinked' => []];
            ++$teams[$name]['players'];
            $link = $matches->linkFor($externalId);
            $entry = ['player' => $r['player']['name'], 'team' => $name, 'minutes' => $r['minutesPlayed'] ?? 0];
            if ($link === null) {
                $teams[$name]['unlinked'][] = $entry;
                continue;
            }
            $teams[$name]['linked'][] = $entry + ['method' => $link['method'], 'match' => $link['player']->name . ' (' . implode(' / ', $link['player']->teams) . ')'];
        }

        // A club is a top-5 club when the matcher could map it to a league club.
        $top5 = array_filter($teams, static fn ($t) => isset($matches->clubs[$t['team']]));
        $other = array_diff_key($teams, $top5);
        $byName = [];
        $unlinkedAtTop5 = [];
        $methods = [];
        foreach ($top5 as $team) {
            foreach ($team['linked'] as $p) {
                $methods[$p['method']] = ($methods[$p['method']] ?? 0) + 1;
                if ($p['method'] !== ClPlayerMatcher::METHOD_ID) {
                    $byName[] = $p;
                }
            }
            array_push($unlinkedAtTop5, ...$team['unlinked']);
        }
        usort($byName, static fn ($a, $b) => $b['minutes'] <=> $a['minutes']);
        usort($unlinkedAtTop5, static fn ($a, $b) => $b['minutes'] <=> $a['minutes']);

        $cl = array_values($clById);
        // SofaScore leaves xG empty when there is nothing to measure (no shots).
        $xgNullNoShots = count(array_filter($cl, static fn ($r) => ($r['expectedGoals'] ?? null) === null && ($r['totalShots'] ?? 0) === 0));
        $xgNullWithShots = count(array_filter($cl, static fn ($r) => ($r['expectedGoals'] ?? null) === null && ($r['totalShots'] ?? 0) > 0));

        return [
            'players' => count($cl),
            'regular' => count(array_filter($cl, static fn ($r) => ($r['minutesPlayed'] ?? 0) >= self::CL_MIN_MINUTES)),
            'teams' => count($teams),
            'top5_teams' => count($top5),
            'top5_players' => array_sum(array_column($top5, 'players')),
            'methods' => $methods,
            'linked_by_name' => $byName,
            'other_teams' => array_keys($other),
            'other_players' => array_sum(array_column($other, 'players')),
            'other_linked' => array_sum(array_map(static fn ($t) => count($t['linked']), $other)),
            'unlinked_at_top5' => $unlinkedAtTop5,
            'xg_null_no_shots' => $xgNullNoShots,
            'xg_null_with_shots' => $xgNullWithShots,
            'goals' => array_sum(array_map(static fn ($r) => $r['goals'] ?? 0, $cl)),
            'assists' => array_sum(array_map(static fn ($r) => $r['assists'] ?? 0, $cl)),
            'minutes' => array_sum(array_map(static fn ($r) => $r['minutesPlayed'] ?? 0, $cl)),
        ];
    }

    /**
     * SofaScore vs FotMob for the same CL players, matched on normalised name (unique only).
     *
     * @param list<array<string, mixed>>          $cl
     * @param array<string, array<string, mixed>> $fotmob raw stat-list entries
     */
    private function fotmobCrossCheck(array $cl, array $fotmob): array
    {
        $byName = [];
        foreach ($fotmob as $entry) {
            $byName[ClPlayerMatcher::normalise($entry['ParticipantName'])][$entry['ParticiantId']][$entry['StatName']] = $entry['StatValue'];
        }

        $totals = ['minutes' => 0, 'goals' => 0, 'assists' => 0];
        foreach ($fotmob as $entry) {
            $stat = ['mins_played' => 'minutes', 'goals' => 'goals', 'goal_assist' => 'assists'][$entry['StatName']] ?? null;
            if ($stat !== null) {
                $totals[$stat] += (int) $entry['StatValue'];
            }
        }

        $matched = $minutesOk = $minutesExact = $goalsOk = $assistsOk = 0;
        $ambiguous = $unmatched = 0;
        $disagreements = [];
        foreach ($cl as $r) {
            $candidates = $byName[ClPlayerMatcher::normalise($r['player']['name'])] ?? [];
            if (count($candidates) !== 1) {
                count($candidates) > 1 ? ++$ambiguous : ++$unmatched;
                continue;
            }
            $f = reset($candidates);
            if (!isset($f['mins_played'])) {
                ++$unmatched;
                continue;
            }
            ++$matched;
            $minutes = (float) ($r['minutesPlayed'] ?? 0);
            $fMinutes = (float) $f['mins_played'];
            $okMinutes = abs($minutes - $fMinutes) <= max(OriginConsistency::MINUTES_TOLERANCE * $fMinutes, OriginConsistency::MINUTES_TOLERANCE_ABSOLUTE);
            // FotMob only lists players with at least one goal / assist.
            $okGoals = (int) ($r['goals'] ?? 0) === (int) ($f['goals'] ?? 0);
            $okAssists = (int) ($r['assists'] ?? 0) === (int) ($f['goal_assist'] ?? 0);
            $minutesOk += (int) $okMinutes;
            $minutesExact += (int) ($minutes === $fMinutes);
            $goalsOk += (int) $okGoals;
            $assistsOk += (int) $okAssists;
            if (!$okMinutes || !$okGoals || !$okAssists) {
                $disagreements[] = [
                    'player' => $r['player']['name'],
                    'team' => $r['team']['name'],
                    'sofascore' => sprintf('%d min, %d G, %d A', $minutes, $r['goals'] ?? 0, $r['assists'] ?? 0),
                    'fotmob' => sprintf('%d min, %d G, %d A', $fMinutes, $f['goals'] ?? 0, $f['goal_assist'] ?? 0),
                    'gap' => abs($minutes - $fMinutes) + 90 * (abs(($r['goals'] ?? 0) - ($f['goals'] ?? 0)) + abs(($r['assists'] ?? 0) - ($f['goal_assist'] ?? 0))),
                ];
            }
        }
        usort($disagreements, static fn ($a, $b) => $b['gap'] <=> $a['gap']);

        return [
            'matched' => $matched,
            'ambiguous' => $ambiguous,
            'unmatched' => $unmatched,
            'totals' => $totals,
            'minutes_ok' => $minutesOk,
            'minutes_exact' => $minutesExact,
            'goals_ok' => $goalsOk,
            'assists_ok' => $assistsOk,
            'disagreements' => array_slice($disagreements, 0, self::EXAMPLES),
            'disagreement_count' => count($disagreements),
        ];
    }

}
