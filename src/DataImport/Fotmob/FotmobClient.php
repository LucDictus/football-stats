<?php

declare(strict_types=1);

namespace App\DataImport\Fotmob;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * FotMob's static season stat lists (one JSON file per statistic). Used as an
 * independent cross-check for minutes, goals and assists.
 */
final readonly class FotmobClient
{
    public const SOURCE = 'fotmob';
    private const BASE_URL = 'https://data.fotmob.com/stats';

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    public function statUrl(int $leagueId, int $seasonId, string $stat): string
    {
        return sprintf('%s/%d/season/%d/%s.json', self::BASE_URL, $leagueId, $seasonId, $stat);
    }

    /** @return list<array<string, mixed>> the StatList entries of one statistic */
    public function statList(int $leagueId, int $seasonId, string $stat): array
    {
        $data = $this->httpClient->request('GET', $this->statUrl($leagueId, $seasonId, $stat))->toArray();

        return $data['TopLists'][0]['StatList'] ?? [];
    }
}
