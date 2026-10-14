<?php

declare(strict_types=1);

namespace App\Football\Data;

/** One statistic the model may use, and where it comes from in each competition. */
final readonly class Field
{
    /**
     * @param string      $leagueColumn column in the Top-5 dataset (null for derived fields)
     * @param string|null $clColumn     field in SofaScore's CL statistics (null = no CL source)
     * @param bool        $goalkeeper   only meaningful for goalkeepers
     * @param bool        $per90        can be expressed per 90 minutes
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $category,
        public Origin $origin,
        public ?string $leagueColumn,
        public ?string $clColumn,
        public bool $goalkeeper = false,
        public bool $per90 = true,
        public ?string $note = null,
    ) {
    }
}
