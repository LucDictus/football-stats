<?php

declare(strict_types=1);

namespace App\DataImport\Identity;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/** Manual identity links from config/identity/players.yaml. */
final readonly class IdentityOverrides
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/identity/players.yaml')]
        private string $file,
    ) {
    }

    /** @return array<int, string> SofaScore player id => league player key */
    public function sofascore(): array
    {
        $config = is_file($this->file) ? Yaml::parseFile($this->file) : [];

        return array_map('strval', $config['sofascore'] ?? []);
    }
}
