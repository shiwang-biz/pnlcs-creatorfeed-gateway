<?php

namespace Modules\Gateways\CreatorFeed;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the CreatorFeed REST API (JSON over HTTPS).
 *
 * Every call returns the same shape:
 *   ['ok' => bool, 'status' => int, 'data' => array, 'code' => ?string, 'message' => ?string]
 * so callers never have to catch exceptions. status 0 means the request never
 * got an answer (DNS, TLS, timeout).
 */
class CreatorFeedApi
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $authHeader = 'bearer',
        private readonly int $timeout = 15,
    ) {}

    public static function fromSettings(): self
    {
        return new self(
            rtrim(CreatorFeedModule::setting('base_url') ?: CreatorFeedModule::DEFAULT_BASE_URL, '/'),
            (string) CreatorFeedModule::setting('api_key'),
            (string) (CreatorFeedModule::setting('auth_header') ?: 'bearer'),
            max(5, min(60, (int) (CreatorFeedModule::setting('timeout') ?: 15))),
        );
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }

    /** "test" for cf_test_ keys, "live" for cf_live_ keys, "unknown" otherwise. */
    public function mode(): string
    {
        return match (true) {
            str_starts_with($this->apiKey, 'cf_test_') => 'test',
            str_starts_with($this->apiKey, 'cf_live_') => 'live',
            default => 'unknown',
        };
    }

    /** POST /v1/orders. Safe to retry: the same order_id + details returns the existing order. */
    public function createOrder(array $payload): array
    {
        return $this->send('post', '/orders', $payload);
    }

    /** GET /v1/orders/{id} - by CreatorFeed id (CFD-TXN-…) or our own order_id. */
    public function getOrder(string $id): array
    {
        return $this->send('get', '/orders/'.rawurlencode($id));
    }

    private function send(string $method, string $path, array $payload = []): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'status' => 0, 'data' => [], 'code' => 'not_configured', 'message' => 'CreatorFeed API key / base URL not configured.'];
        }

        $attempts = 3;
        $last = null;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $request = Http::acceptJson()
                    ->asJson()
                    ->timeout($this->timeout)
                    ->connectTimeout(min(10, $this->timeout))
                    ->withHeaders($this->authHeader === 'x-api-key'
                        ? ['X-Api-Key' => $this->apiKey]
                        : ['Authorization' => 'Bearer '.$this->apiKey]);

                /** @var Response $response */
                $response = $method === 'get'
                    ? $request->get($this->baseUrl.$path)
                    : $request->post($this->baseUrl.$path, $payload);

                $last = $this->result($response);

                // 429 and 5xx are worth one more try; anything else is an answer.
                if ($response->status() !== 429 && $response->status() < 500) {
                    return $last;
                }
            } catch (ConnectionException $e) {
                $last = ['ok' => false, 'status' => 0, 'data' => [], 'code' => 'connection_error', 'message' => $e->getMessage()];
            }

            if ($i < $attempts) {
                usleep($i * 700_000);
            }
        }

        return $last;
    }

    private function result(Response $response): array
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'data' => $json,
            'code' => $response->successful() ? null : (string) ($json['code'] ?? 'http_'.$response->status()),
            'message' => $response->successful() ? null : (string) ($json['message'] ?? $response->reason()),
        ];
    }
}
