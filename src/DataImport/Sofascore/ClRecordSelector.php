<?php

declare(strict_types=1);

namespace App\DataImport\Sofascore;

/**
 * Chooses one usable SofaScore CL record per player. Used by the season build and the data
 * audit, so both see the same records.
 *
 * - Records without minutes are unusable.
 * - A player listed for two CL clubs (a transfer between CL clubs) keeps the club with the
 *   most minutes; that record is partial: real numbers, but not the whole campaign.
 */
final class ClRecordSelector
{
    /**
     * @param array<string, array<string, mixed>> $records external id => SofaScore record
     *
     * @return array{records: array<string, array<string, mixed>>, partial: array<string, string>, rejected: list<string>}
     *                                                                                                                     partial: external id => reason
     */
    public function select(array $records): array
    {
        $byPlayer = [];
        $rejected = [];
        foreach ($records as $externalId => $record) {
            if (!isset($record['minutesPlayed'])) {
                $rejected[] = "CL {$externalId}: {$record['player']['name']} has no minutes";
                continue;
            }
            $byPlayer[$record['player']['id']][(string) $externalId] = $record;
        }

        $selected = [];
        $partial = [];
        foreach ($byPlayer as $clubs) {
            uasort($clubs, static fn (array $a, array $b) => $b['minutesPlayed'] <=> $a['minutesPlayed']);
            $externalId = (string) array_key_first($clubs);
            $selected[$externalId] = $clubs[$externalId];
            if (count($clubs) > 1) {
                $partial[$externalId] = sprintf(
                    'one of %d CL clubs (%d of %d minutes)',
                    count($clubs),
                    $clubs[$externalId]['minutesPlayed'],
                    array_sum(array_column($clubs, 'minutesPlayed')),
                );
            }
        }

        return ['records' => $selected, 'partial' => $partial, 'rejected' => $rejected];
    }
}
