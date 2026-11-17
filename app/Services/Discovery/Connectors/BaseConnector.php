<?php

namespace App\Services\Discovery\Connectors;

use App\Models\JobSource;
use App\Services\Support\HttpFetcher;
use Illuminate\Http\Client\Response;

abstract class BaseConnector implements Connector
{
    public function __construct(protected HttpFetcher $http) {}

    protected function getJson(string $url, array $query = [], array $headers = []): array
    {
        try {
            $response = $this->http->get($url, $query, $headers);
        } catch (\Throwable $e) {
            throw new ConnectorException('Request failed: '.$e->getMessage(), 'http', false, $e);
        }

        return $this->decode($response, $url);
    }

    protected function decode(Response $response, string $url): array
    {
        $status = $response->status();
        if (in_array($status, [401, 403], true)) {
            throw new ConnectorException("Access denied ({$status}) by {$this->host($url)}; skipping (no bypass attempted).", 'blocked', true);
        }
        if ($status === 404) {
            throw new ConnectorException("Board not found (404) at {$this->host($url)}.", 'http', true);
        }
        if (! $response->successful()) {
            throw new ConnectorException("HTTP {$status} from {$this->host($url)}.", 'http');
        }
        if (strlen($response->body()) > HttpFetcher::MAX_BYTES) {
            throw new ConnectorException('Response exceeded size limit.', 'parse');
        }
        $data = $response->json();
        if (! is_array($data)) {
            throw new ConnectorException('Response was not valid JSON.', 'parse');
        }

        return $data;
    }

    protected function host(string $url): string
    {
        return parse_url($url, PHP_URL_HOST) ?: $url;
    }

    protected function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (blank($value)) {
            return null;
        }
        try {
            if (is_numeric($value)) {
                $ts = (int) $value;

                return (new \DateTimeImmutable)->setTimestamp($ts > 1e11 ? intdiv($ts, 1000) : $ts);
            }

            return new \DateTimeImmutable((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function probe(string $identifier): ?int
    {
        try {
            $count = 0;
            foreach ($this->fetch(new JobSource(['type' => $this->type(), 'identifier' => $identifier]), 1000) as $job) {
                $count++;
            }

            return $count;
        } catch (ConnectorException) {
            return null;
        }
    }
}
