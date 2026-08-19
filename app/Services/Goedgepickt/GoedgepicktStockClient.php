<?php

namespace App\Services\Goedgepickt;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
use RuntimeException;

class GoedgepicktStockClient
{
    public function getCurrentStockForSupplier(string $supplierUuid): array
    {
        $page    = 1;
        $perPage = 100;
        $stock   = [];

        while (true) {
            $response = $this->goedgepicktGet(
                config('api.goedgepickt.products_endpoint'),
                [
                    'perPage'         => 100,
                    'page'            => $page,
                    'searchAttribute' => 'supplierUuid',
                    'searchDelimiter' => '=',
                    'searchValue'     => $supplierUuid,
                ]
            );

            if ($response->failed()) {
                Log::error('Could not fetch GoedGepickt products.', [
                    'supplier_uuid' => $supplierUuid,
                    'page'          => $page,
                    'status'        => $response->status(),
                    'body'          => $response->body(),
                ]);

                throw new RuntimeException('Could not fetch GoedGepickt products.');
            }

            $items = $response->json('items', []);

            if (!is_array($items)) {
                throw new RuntimeException('GoedGepickt products response does not contain items.');
            }

            foreach ($items as $product) {
                if (!is_array($product)) {
                    continue;
                }

                $uuid = trim((string)($product['uuid'] ?? ''));
                $ean  = $this->normalizeEan((string)($product['ean'] ?? $product['barcode'] ?? ''));
                $sku  = trim((string)($product['sku'] ?? ''));

                if ($uuid === '' || $ean === '') {
                    Log::warning('GoedGepickt product skipped because UUID or EAN is missing.', [
                        'uuid' => $uuid,
                        'ean'  => $ean,
                        'sku'  => $sku,
                        'page' => $page,
                    ]);

                    continue;
                }

                $stock[$ean] = [
                    'uuid'  => $uuid,
                    'ean'   => $ean,
                    'sku'   => $sku,
                    'stock' => (int)data_get($product, 'stock.freeStock', 0),
                ];
            }

            $currentPage = (int)$response->json('pageInfo.currentPage', $page);
            $lastPage    = (int)$response->json('pageInfo.lastPage', $page);

            if ($currentPage >= $lastPage) {
                break;
            }

            $page = $currentPage + 1;
        }

        Log::info('GoedGepickt supplier products fetched.', [
            'supplier_uuid' => $supplierUuid,
            'product_count' => count($stock),
            'pages'         => $page,
        ]);

        return $stock;
    }

    public function sendMutations(array $mutations): array
    {
        $result = [
            'attempted'    => count($mutations),
            'succeeded'    => 0,
            'failed'       => 0,
            'failed_items' => [],
        ];

        foreach ($mutations as $mutation) {
            $uuid = $mutation['product_uuid'] ?? null;

            if (! is_string($uuid) || $uuid === '') {
                $result['failed']++;

                $result['failed_items'][] = [
                    'reason'       => 'missing_product_uuid',
                    'ean'          => $mutation['ean'] ?? null,
                    'sku'          => $mutation['sku'] ?? null,
                    'supplier_sku' => $mutation['supplier_sku'] ?? null,
                    'delta'        => $mutation['delta'] ?? null,
                    'stock_before' => $mutation['stock_before'] ?? null,
                    'stock_after'  => $mutation['stock_after'] ?? null,
                ];

                Log::warning('Skipping GoedGepickt stock mutation because product UUID is missing.', [
                    'ean'   => $mutation['ean'] ?? null,
                    'sku'   => $mutation['sku'] ?? null,
                    'delta' => $mutation['delta'] ?? null,
                ]);

                continue;
            }

            try {
                $response = $this->goedgepicktPut(
                    $this->stockMutationEndpoint($uuid),
                    [
                        'mutation'       => $mutation['delta'],
                        'mutationReason' => 'Stock sync - Captura Connect',
                    ]
                );
            } catch (ConnectionException $exception) {
                $result['failed']++;

                $result['failed_items'][] = [
                    'reason'          => 'connection_exception',
                    'product_uuid'    => $uuid,
                    'goedgepickt_url' => $this->productUrl($uuid),
                    'ean'             => $mutation['ean'] ?? null,
                    'sku'             => $mutation['sku'] ?? null,
                    'supplier_sku'    => $mutation['supplier_sku'] ?? null,
                    'delta'           => $mutation['delta'] ?? null,
                    'stock_before'    => $mutation['stock_before'] ?? null,
                    'stock_after'     => $mutation['stock_after'] ?? null,
                    'message'         => $exception->getMessage(),
                ];

                Log::warning('GoedGepickt stock mutation connection failed.', [
                    'product_uuid'    => $uuid,
                    'goedgepickt_url' => $this->productUrl($uuid),
                    'ean'             => $mutation['ean'] ?? null,
                    'sku'             => $mutation['sku'] ?? null,
                    'delta'           => $mutation['delta'] ?? null,
                    'message'         => $exception->getMessage(),
                ]);

                continue;
            }

            if ($response->failed()) {
                $result['failed']++;

                $result['failed_items'][] = [
                    'reason'          => 'goedgepickt_rejected_mutation',
                    'product_uuid'    => $uuid,
                    'goedgepickt_url' => $this->productUrl($uuid),
                    'ean'             => $mutation['ean'] ?? null,
                    'sku'             => $mutation['sku'] ?? null,
                    'supplier_sku'    => $mutation['supplier_sku'] ?? null,
                    'delta'           => $mutation['delta'] ?? null,
                    'stock_before'    => $mutation['stock_before'] ?? null,
                    'stock_after'     => $mutation['stock_after'] ?? null,
                    'status'          => $response->status(),
                    'body'            => $response->json() ?? $response->body(),
                ];

                Log::warning('GoedGepickt stock mutation was rejected.', [
                    'product_uuid'    => $uuid,
                    'goedgepickt_url' => $this->productUrl($uuid),
                    'ean'             => $mutation['ean'] ?? null,
                    'sku'             => $mutation['sku'] ?? null,
                    'supplier_sku'    => $mutation['supplier_sku'] ?? null,
                    'delta'           => $mutation['delta'] ?? null,
                    'stock_before'    => $mutation['stock_before'] ?? null,
                    'stock_after'     => $mutation['stock_after'] ?? null,
                    'status'          => $response->status(),
                    'body'            => $response->body(),
                ]);

                continue;
            }

            $result['succeeded']++;
        }

        return $result;
    }

    private function stockMutationUrl(string $uuid): string
    {
        $endpoint = str_replace(
            '{uuid}',
            $uuid,
            config('api.goedgepickt.stock_mutation_endpoint')
        );

        return $this->url($endpoint);
    }

    private function url(string $endpoint): string
    {
        if (str_starts_with($endpoint, 'http://') || str_starts_with($endpoint, 'https://')) {
            return $endpoint;
        }

        $apiUrl = config('api.goedgepickt.api_url');

        if (!is_string($apiUrl) || $apiUrl === '') {
            throw new RuntimeException('GoedGepickt API URL is not configured.');
        }

        return rtrim($apiUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    private function normalizeEan(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return preg_replace('/\D/', '', $value) ?? '';
    }

    private function goedgepicktGet(string $endpoint, array $query = []): Response
    {
        return $this->goedgepicktRequest(function () use ($endpoint, $query) {
            return Http::timeout(30)
                ->acceptJson()
                ->withToken(config('api.goedgepickt.api_key'))
                ->get($this->url($endpoint), $query);
        });
    }

    private function goedgepicktPut(string $endpoint, array $payload = []): Response
    {
        return $this->goedgepicktRequest(function () use ($endpoint, $payload) {
            return Http::timeout(30)
                ->acceptJson()
                ->asJson()
                ->withToken(config('api.goedgepickt.api_key'))
                ->put($this->url($endpoint), $payload);
        });
    }

    private function goedgepicktRequest(callable $request): Response
    {
        $maxRetries = 5;
        $attempt    = 0;

        do {
            $attempt++;

            /** @var Response $response */
            $response = $request();

            if ($response->status() === 429) {
                $waitSeconds = $this->retryAfterSeconds($response);

                Log::warning('GoedGepickt rate limit hit. Waiting before retry.', [
                    'attempt'               => $attempt,
                    'wait_seconds'          => $waitSeconds,
                    'retry_after'           => $response->header('Retry-After'),
                    'x_ratelimit_limit'     => $response->header('X-Ratelimit-Limit'),
                    'x_ratelimit_remaining' => $response->header('X-Ratelimit-Remaining'),
                    'x_ratelimit_reset'     => $response->header('X-Ratelimit-Reset'),
                ]);

                sleep($waitSeconds);

                continue;
            }

            $this->waitIfRateLimitIsAlmostReached($response);

            return $response;
        } while ($attempt <= $maxRetries);

        return $response;
    }

    private function waitIfRateLimitIsAlmostReached(Response $response): void
    {
        $remaining = $response->header('X-Ratelimit-Remaining');

        if (!is_numeric($remaining)) {
            return;
        }

        if ((int)$remaining > 1) {
            return;
        }

        $waitSeconds = $this->secondsUntilRateLimitReset($response);

        if ($waitSeconds <= 0) {
            return;
        }

        Log::info('GoedGepickt rate limit almost reached. Waiting before next request.', [
            'wait_seconds'          => $waitSeconds,
            'x_ratelimit_limit'     => $response->header('X-Ratelimit-Limit'),
            'x_ratelimit_remaining' => $response->header('X-Ratelimit-Remaining'),
            'x_ratelimit_reset'     => $response->header('X-Ratelimit-Reset'),
        ]);

        sleep($waitSeconds);
    }

    private function retryAfterSeconds(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_numeric($retryAfter)) {
            return max(1, (int)$retryAfter);
        }

        return $this->secondsUntilRateLimitReset($response) ?: 60;
    }

    private function secondsUntilRateLimitReset(Response $response): int
    {
        $reset = $response->header('X-Ratelimit-Reset');

        if (!is_numeric($reset)) {
            return 0;
        }

        $seconds = (int)$reset - time();

        return max(1, $seconds);
    }

    private function stockMutationEndpoint(string $uuid): string
    {
        return str_replace(
            '{uuid}',
            $uuid,
            config('api.goedgepickt.stock_mutation_endpoint')
        );
    }

    private function productUrl(string $uuid): string
    {
        $appUrl = config('api.goedgepickt.app_url');

        if (!is_string($appUrl) || $appUrl === '') {
            return '';
        }

        return rtrim($appUrl, '/') . '/products/view/' . $uuid;
    }
}
