<?php

namespace App\Services\Ai;

use Illuminate\Database\Eloquent\Model;

interface AiClient
{
    public function isConfigured(): bool;

    public function model(): string;

    /**
     * Run one request that must return JSON matching $schema.
     *
     * @param  array<int, array{text: string, cache?: bool}>  $system  system prompt blocks
     * @param  string  $user  user message (may contain untrusted data wrapped in tags)
     *
     * @throws AiException
     */
    public function structured(string $purpose, array $system, string $user, array $schema, ?Model $related = null, int $maxTokens = 16000): AiResult;
}
