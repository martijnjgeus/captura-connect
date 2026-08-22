<?php

namespace App\Services\CodeAllocator;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CodeAllocatorClient
{
    public function allocateSku(int $quantity): array
    {
        return $this->allocate('SKU', $quantity, null);
    }

    public function allocateEan(int $quantity, string $company): array
    {
        $company = trim($company);

        if ($company === '') {
            throw new RuntimeException('Cannot allocate EAN codes without company.');
        }

        return $this->allocate('EAN', $quantity, $company);
    }

    private function allocate(string $codeType, int $quantity, ?string $company): array
    {
        $codeType = strtoupper(trim($codeType));

        if (! in_array($codeType, ['SKU', 'EAN'], true)) {
            throw new RuntimeException('Invalid code type: ' . $codeType);
        }

        if ($quantity < 1 || $quantity > 500) {
            throw new RuntimeException('Invalid quantity: ' . $quantity);
        }

        $url = $this->url();
        $token = trim((string) config('api.code_allocator.token'));

        if ($url === '') {
            throw new RuntimeException('Missing config: api.code_allocator.url or api.code_allocator.staging_url');
        }

        if ($token === '') {
            throw new RuntimeException('Missing config: api.code_allocator.token');
        }

        $payload = [
            'codeType' => $codeType,
            'quantity' => $quantity,
            'company' => $company,
        ];

        $response = Http::timeout(30)
            ->asJson()
            ->acceptJson()
            ->withToken($token, 'Basic')
            ->post($url, $payload);

        $body = $response->json();

        if (! $response->successful()) {
            throw new RuntimeException(json_encode([
                'message' => 'Code allocator request failed.',
                'status' => $response->status(),
                'reason' => $response->reason(),
                'payload' => $payload,
                'body' => $body ?? $response->body(),
            ], JSON_PRETTY_PRINT));
        }

        if (! is_array($body)) {
            throw new RuntimeException('Code allocator returned invalid JSON.');
        }

        if (($body['success'] ?? false) !== true) {
            throw new RuntimeException(json_encode([
                'message' => 'Code allocator returned unsuccessful response.',
                'payload' => $payload,
                'body' => $body,
            ], JSON_PRETTY_PRINT));
        }

        $items = $body['data']['items'] ?? null;

        if (! is_array($items)) {
            throw new RuntimeException(json_encode([
                'message' => 'Code allocator response is missing data.items.',
                'payload' => $payload,
                'body' => $body,
            ], JSON_PRETTY_PRINT));
        }

        if (count($items) !== $quantity) {
            throw new RuntimeException(json_encode([
                'message' => 'Code allocator returned unexpected item count.',
                'expected' => $quantity,
                'actual' => count($items),
                'payload' => $payload,
                'body' => $body,
            ], JSON_PRETTY_PRINT));
        }

        $codes = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('Code allocator returned an invalid item.');
            }

            $returnedCodeType = strtoupper(trim((string) ($item['codeType'] ?? '')));
            $code = trim((string) ($item['code'] ?? ''));

            if ($returnedCodeType !== $codeType) {
                throw new RuntimeException(json_encode([
                    'message' => 'Code allocator returned wrong code type.',
                    'expected' => $codeType,
                    'actual' => $returnedCodeType,
                    'item' => $item,
                ], JSON_PRETTY_PRINT));
            }

            if ($code === '') {
                throw new RuntimeException(json_encode([
                    'message' => 'Code allocator returned empty code.',
                    'item' => $item,
                ], JSON_PRETTY_PRINT));
            }

            $codes[] = [
                'code' => $code,
                'item' => $item,
            ];
        }

        return [
            'payload' => $payload,
            'response' => $body,
            'codes' => $codes,
        ];
    }

    private function url(): string
    {
        $useStaging = (bool) config('api.code_allocator.use_staging', true);

        if ($useStaging) {
            return trim((string) config('api.code_allocator.staging_url'));
        }

        return trim((string) config('api.code_allocator.url'));
    }
}
