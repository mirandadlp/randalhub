<?php

namespace App\Console\Commands;

use App\Services\Discovery\AtsDetector;
use Illuminate\Console\Command;

class ProbeCompanyCommand extends Command
{
    protected $signature = 'jobhunter:probe {name : Company name or board slug}';

    protected $description = 'Detect which public ATS (Greenhouse, Lever, Ashby, Workable) a company uses';

    public function handle(AtsDetector $detector): int
    {
        $found = $detector->detect($this->argument('name'));
        if (! $found) {
            $this->warn('No public ATS board found for that name.');

            return self::SUCCESS;
        }
        $this->table(['ATS', 'Identifier', 'Open jobs'], array_map(fn ($f) => [$f['type'], $f['identifier'], $f['jobs']], $found));

        return self::SUCCESS;
    }
}
