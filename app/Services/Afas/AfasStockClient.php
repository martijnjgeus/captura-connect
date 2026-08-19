<?php

namespace App\Services\Afas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AfasStockClient
{
    public function sendStockLines(array $lines): array
    {
        $lines = array_values($lines);

        $result = [
            'attempted'     => count($lines),
            'succeeded'     => 0,
            'failed'        => 0,
            'failed_items'  => [],
            'failed_chunks' => [],
        ];

        $chunkSize = config('api.afas.stock_sync.chunk_size', 100);

        if (!is_int($chunkSize) || $chunkSize < 1) {
            $chunkSize = 100;
        }

        foreach (array_chunk($lines, $chunkSize) as $chunkIndex => $chunk) {
            $payload = $this->payload($chunk);
            $url     = $this->url();

            try {
                $response = $this->sendChunk($payload);
            } catch (ConnectionException $exception) {
                $result['failed'] += count($chunk);

                $result['failed_chunks'][] = [
                    'reason'                 => 'afas_connection_exception',
                    'chunk_index'            => $chunkIndex,
                    'url'                    => $url,
                    'lines_count'            => count($chunk),
                    'message'                => $exception->getMessage(),
                    'sample_lines'           => array_slice($chunk, 0, 10),
                    'request_payload_sample' => [
                        $this->connector() => [
                            'Element' => array_slice(
                                $payload[$this->connector()]['Element'] ?? [],
                                0,
                                10
                            ),
                        ],
                    ],
                ];

                foreach ($chunk as $line) {
                    $result['failed_items'][] = [
                        'reason'      => 'afas_connection_exception',
                        'chunk_index' => $chunkIndex,
                        'line'        => $line,
                    ];
                }

                Log::warning('AFAS stock sync chunk connection failed.', [
                    'chunk_index' => $chunkIndex,
                    'url'         => $url,
                    'chunk_size'  => count($chunk),
                    'message'     => $exception->getMessage(),
                ]);

                continue;
            }

            if ($response->failed()) {
                $result['failed'] += count($chunk);

                $rawBody = $response->body();

                $result['failed_chunks'][] = [
                    'reason'                 => 'afas_rejected_chunk',
                    'chunk_index'            => $chunkIndex,
                    'url'                    => $url,
                    'status'                 => $response->status(),
                    'reason_phrase'          => $response->reason(),
                    'content_type'           => $response->header('Content-Type'),
                    'body_length'            => strlen($rawBody),
                    'body_preview'           => substr($rawBody, 0, 5000),
                    'body_base64'            => base64_encode($rawBody),
                    'successful'             => $response->successful(),
                    'lines_count'            => count($chunk),
                    'response_headers'       => $response->headers(),
                    'sample_lines'           => array_slice($chunk, 0, 10),
                    'request_payload_sample' => [
                        $this->connector() => [
                            'Element' => array_slice(
                                $payload[$this->connector()]['Element'] ?? [],
                                0,
                                10
                            ),
                        ],
                    ],
                ];

                foreach ($chunk as $line) {
                    $result['failed_items'][] = [
                        'reason'      => 'afas_rejected_stock_line',
                        'chunk_index' => $chunkIndex,
                        'line'        => $line,
                    ];
                }

                Log::warning('AFAS stock sync chunk was rejected.', [
                    'chunk_index'   => $chunkIndex,
                    'url'           => $url,
                    'chunk_size'    => count($chunk),
                    'status'        => $response->status(),
                    'reason_phrase' => $response->reason(),
                    'content_type'  => $response->header('Content-Type'),
                    'body_length'   => strlen($rawBody),
                    'body_preview'  => substr($rawBody, 0, 5000),
                    'body_base64'   => base64_encode($rawBody),
                ]);

                continue;
            }

            $result['succeeded'] += count($chunk);
        }

        return $result;
    }

    private function sendChunk(array $payload): Response
    {
        return Http::timeout(60)
            ->acceptJson()
            ->asJson()
            ->withHeader('Authorization', 'AfasToken '.$this->apiKey())
            ->post($this->url(), $payload);
    }

    private function payload(array $lines): array
    {
        $connector = $this->connector();

        return [
            $connector => [
                'Element' => array_map(
                    static fn(array $line): array => [
                        'Fields' => [
                            'ItCd' => $line['item_code'],
                            'StL1' => $line['dimension_1'],
                            'StL2' => $line['dimension_2'],
                            'War'  => $line['warehouse'],
                            'QuUn' => $line['stock'],
                        ],
                    ],
                    $lines,
                ),
            ],
        ];
    }

    private function url(): string
    {
        $apiUrl = config('api.afas.api_url');

        if (!is_string($apiUrl) || $apiUrl === '') {
            throw new RuntimeException('AFAS API URL is not configured.');
        }

        return rtrim($apiUrl, '/') . '/connectors/' . $this->connector();
    }

    private function apiKey(): string
    {
        $apiKey = config('api.afas.api_key');

        if (!is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('AFAS API key is not configured.');
        }

        return $apiKey;
    }

    private function connector(): string
    {
        $connector = config('api.afas.stock_sync.connector');

        if (!is_string($connector) || $connector === '') {
            throw new RuntimeException('AFAS stock sync connector is not configured.');
        }

        return $connector;
    }

    private function responseBody(Response $response): mixed
    {
        $rawBody = $response->body();

        try {
            $jsonBody = $response->json();
        } catch (Throwable) {
            $jsonBody = null;
        }

        if ($jsonBody !== null) {
            return $jsonBody;
        }

        if ($rawBody !== '') {
            return $rawBody;
        }

        return [
            '_empty_body' => true,
            'status'      => $response->status(),
            'reason'      => $response->reason(),
        ];
    }
}
