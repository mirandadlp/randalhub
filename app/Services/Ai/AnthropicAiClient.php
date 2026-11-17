<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\ApiUsageLog;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Model;

/**
 * Claude via the official Anthropic PHP SDK. Every call:
 *  - enforces the monthly AI budget before sending,
 *  - uses structured outputs (JSON schema) so results are machine-validated,
 *  - enables server-side refusal fallbacks,
 *  - logs tokens and cost to api_usage_logs.
 */
class AnthropicAiClient implements AiClient
{
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private ?Client $client = null;

    public function __construct(private SettingsService $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->anthropicApiKey());
    }

    public function model(): string
    {
        return $this->settings->get('anthropic_model') ?: config('jobhunter.anthropic.model');
    }

    public static function monthSpend(): float
    {
        return (float) ApiUsageLog::where('provider', 'anthropic')
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('cost_usd');
    }

    public function structured(string $purpose, array $system, string $user, array $schema, ?Model $related = null, int $maxTokens = 16000): AiResult
    {
        $apiKey = $this->settings->anthropicApiKey();
        if (blank($apiKey)) {
            throw new AiException('Claude API key is not configured. Add ANTHROPIC_API_KEY to .env or set it in Settings.', 'not_configured');
        }
        $budget = (float) $this->settings->get('monthly_ai_budget_usd', 25);
        if ($budget > 0 && static::monthSpend() >= $budget) {
            throw new AiException(sprintf('Monthly AI budget of $%.2f reached. Raise it in Settings to continue.', $budget), 'budget');
        }

        $this->client ??= new Client(apiKey: $apiKey);
        $model = $this->model();
        $effort = $this->settings->get('anthropic_effort') ?: config('jobhunter.anthropic.effort');
        $started = microtime(true);

        $systemBlocks = [];
        foreach ($system as $block) {
            $b = ['type' => 'text', 'text' => $block['text']];
            if (! empty($block['cache'])) {
                $b['cacheControl'] = ['type' => 'ephemeral'];
            }
            $systemBlocks[] = $b;
        }

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                betas: [self::FALLBACK_BETA],
                fallbacks: 'default',
                outputConfig: [
                    'effort' => $effort,
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                system: $systemBlocks,
            );
        } catch (APIStatusException $e) {
            $this->log($purpose, $model, 0, 0, 0, 0, 'error', $started, $related, $e->getMessage());
            $status = $e->status ?? 0;
            throw new AiException('Claude API error: '.$e->getMessage(), 'api', $status === 429 || $status >= 500, $e);
        } catch (APIConnectionException $e) {
            $this->log($purpose, $model, 0, 0, 0, 0, 'error', $started, $related, $e->getMessage());
            throw new AiException('Could not reach the Claude API: '.$e->getMessage(), 'api', true, $e);
        }

        $usage = $message->usage;
        $in = (int) $usage->inputTokens;
        $out = (int) $usage->outputTokens;
        $cacheRead = (int) ($usage->cacheReadInputTokens ?? 0);
        $cacheWrite = (int) ($usage->cacheCreationInputTokens ?? 0);
        $servedModel = (string) ($message->model ?? $model);
        $cost = $this->cost($in, $out, $cacheRead, $cacheWrite);

        if ($message->stopReason === 'refusal') {
            $this->log($purpose, $servedModel, $in, $out, $cacheRead, $cacheWrite, 'refusal', $started, $related, 'Model declined the request.', $cost);
            throw new AiException('Claude declined to process this request.', 'refusal');
        }

        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $text .= $block->text;
            }
        }
        $data = json_decode($text, true);
        if (! is_array($data)) {
            $this->log($purpose, $servedModel, $in, $out, $cacheRead, $cacheWrite, 'error', $started, $related, 'Unparseable JSON output', $cost);
            throw new AiException('Claude returned output that was not valid JSON (stop reason: '.$message->stopReason.').', 'parse', true);
        }

        $this->log($purpose, $servedModel, $in, $out, $cacheRead, $cacheWrite, 'success', $started, $related, null, $cost);

        return new AiResult($data, $servedModel, $in + $cacheRead + $cacheWrite, $out, $cost);
    }

    public function cost(int $in, int $out, int $cacheRead = 0, int $cacheWrite = 0): float
    {
        $inPrice = (float) config('jobhunter.anthropic.input_price_per_mtok');
        $outPrice = (float) config('jobhunter.anthropic.output_price_per_mtok');

        return round(($in * $inPrice + $cacheWrite * $inPrice * 1.25 + $cacheRead * $inPrice * 0.1 + $out * $outPrice) / 1_000_000, 6);
    }

    private function log(string $purpose, string $model, int $in, int $out, int $cacheRead, int $cacheWrite, string $status, float $started, ?Model $related, ?string $error, float $cost = 0): void
    {
        ApiUsageLog::create([
            'provider' => 'anthropic',
            'purpose' => $purpose,
            'model' => mb_substr($model, 0, 60),
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cache_read_tokens' => $cacheRead,
            'cache_write_tokens' => $cacheWrite,
            'cost_usd' => $cost,
            'status' => $status,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            'related_type' => $related?->getMorphClass(),
            'related_id' => $related?->getKey(),
            'error' => $error ? mb_substr($error, 0, 2000) : null,
        ]);
    }
}
