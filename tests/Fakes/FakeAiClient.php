<?php

namespace Tests\Fakes;

use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\AiResult;
use Illuminate\Database\Eloquent\Model;

/** Test double for the Claude client: returns queued responses and records every prompt. */
class FakeAiClient implements AiClient
{
    /** @var list<array{purpose: string, system: array, user: string, schema: array}> */
    public array $calls = [];

    /** @var list<array|AiException|callable> */
    private array $responses = [];

    public bool $configured = true;

    public function push(array|AiException|callable $response): static
    {
        $this->responses[] = $response;

        return $this;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function structured(string $purpose, array $system, string $user, array $schema, ?Model $related = null, int $maxTokens = 16000): AiResult
    {
        $this->calls[] = compact('purpose', 'system', 'user', 'schema');
        $next = array_shift($this->responses) ?? throw new \LogicException("No fake AI response queued for [{$purpose}].");
        if ($next instanceof AiException) {
            throw $next;
        }
        if (is_callable($next)) {
            $next = $next($purpose, $system, $user);
        }

        return new AiResult($next, 'fake-model', 1000, 200, 0.008);
    }
}
