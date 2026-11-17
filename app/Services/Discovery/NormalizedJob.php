<?php

namespace App\Services\Discovery;

/**
 * Connector-agnostic representation of a job posting as returned by a source.
 * All string content is untrusted external data.
 */
class NormalizedJob
{
    public function __construct(
        public string $externalId,
        public string $title,
        public string $companyName,
        public string $sourceUrl,
        public ?string $applicationUrl = null,
        public ?string $location = null,
        public ?string $workplaceHint = null,
        public ?bool $remoteFlag = null,
        public ?string $country = null,
        public ?string $employmentType = null,
        public ?string $department = null,
        public ?string $descriptionHtml = null,
        public ?string $descriptionText = null,
        public ?int $salaryMin = null,
        public ?int $salaryMax = null,
        public ?string $salaryCurrency = null,
        public ?string $salaryInterval = null,
        public ?string $salaryText = null,
        public ?\DateTimeInterface $postedAt = null,
        public ?int $applicantCount = null,
        public ?string $applicantCountSource = null,
        public array $raw = [],
    ) {}
}
