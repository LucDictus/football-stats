<?php

declare(strict_types=1);

namespace App\DataImport;

/** Source names as stored in import batches and identifiers. One definition, used everywhere. */
final class SourceName
{
    public const TOP5 = 'top5-football-dataset';
    public const SOFASCORE = 'sofascore';
    public const FOTMOB = 'fotmob';
}
