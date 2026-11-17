<?php

namespace App\Services\Discovery\Connectors;

use App\Models\JobSource;
use App\Services\Discovery\NormalizedJob;

interface Connector
{
    /** Short machine name matching job_sources.type. */
    public function type(): string;

    /**
     * Fetch all currently open postings for the source.
     *
     * @return iterable<NormalizedJob>
     *
     * @throws ConnectorException
     */
    public function fetch(JobSource $source, int $limit): iterable;

    /** True when the board identifier exists and returns a valid payload (used for ATS auto-detection). */
    public function probe(string $identifier): ?int;
}
