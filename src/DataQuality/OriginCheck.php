<?php

declare(strict_types=1);

namespace App\DataQuality;

final readonly class OriginCheck
{
    public function __construct(
        public OriginStatus $status,
        public ?float $minutes,
        public ?string $reason = null,
    ) {
    }
}
