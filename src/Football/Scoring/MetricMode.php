<?php

declare(strict_types=1);

namespace App\Football\Scoring;

enum MetricMode: string
{
    /** Season total: needs every field trusted (its provider covers the whole season). */
    case Total = 'total';
    /** Per 90 minutes, on each field's own provider minutes: ok or partial providers. */
    case Per90 = 'per90';
    /** A percentage as it is (e.g. aerial duels won %): ok or partial providers, a rate holds on part of a season. */
    case Rate = 'rate';
}
