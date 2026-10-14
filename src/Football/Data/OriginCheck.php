<?php

declare(strict_types=1);

namespace App\Football\Data;

final readonly class OriginCheck
{
    public function __construct(
        public OriginStatus $status,
        public ?float $minutes,
        public ?string $reason = null,
    ) {
    }
}
