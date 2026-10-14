<?php

declare(strict_types=1);

namespace App\DataImport\Identity;

use App\DataQuality\MergedPlayerSeason;

final readonly class ClMatchResult
{
    /**
     * @param array<string, array{player: MergedPlayerSeason, method: string}> $links CL external id => league player
     * @param array<string, string>                                            $clubs CL club name => league club name
     */
    public function __construct(
        public array $links,
        public array $clubs,
    ) {
    }

    /** @return array{player: MergedPlayerSeason, method: string}|null */
    public function linkFor(string $externalId): ?array
    {
        return $this->links[$externalId] ?? null;
    }
}
