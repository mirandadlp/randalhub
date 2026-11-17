<?php

namespace App\Services\Discovery;

use App\Services\Discovery\Connectors\AdzunaConnector;
use App\Services\Discovery\Connectors\AshbyConnector;
use App\Services\Discovery\Connectors\CareerPageConnector;
use App\Services\Discovery\Connectors\Connector;
use App\Services\Discovery\Connectors\GreenhouseConnector;
use App\Services\Discovery\Connectors\LeverConnector;
use App\Services\Discovery\Connectors\SmartRecruitersConnector;
use App\Services\Discovery\Connectors\WorkableConnector;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class ConnectorRegistry
{
    private const MAP = [
        'greenhouse' => GreenhouseConnector::class,
        'lever' => LeverConnector::class,
        'ashby' => AshbyConnector::class,
        'smartrecruiters' => SmartRecruitersConnector::class,
        'workable' => WorkableConnector::class,
        'career_page' => CareerPageConnector::class,
        'adzuna' => AdzunaConnector::class,
    ];

    /** ATS types that can be auto-detected from a company slug. */
    public const PROBEABLE = ['greenhouse', 'lever', 'ashby', 'workable'];

    public function __construct(private Container $container) {}

    public function for(string $type): Connector
    {
        if (! isset(self::MAP[$type])) {
            throw new InvalidArgumentException("Unknown connector type [{$type}].");
        }

        return $this->container->make(self::MAP[$type]);
    }

    public function types(): array
    {
        return array_keys(self::MAP);
    }
}
