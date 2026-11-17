<?php

namespace App\Services\Ai;

class AiResult
{
    public function __construct(
        public array $data,
        public string $model,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public float $costUsd = 0.0,
    ) {}
}
