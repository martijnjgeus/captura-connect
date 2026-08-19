<?php

namespace App\Services\Afas;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AfasDeliveryNoteClient
{
    private const int TAKE = 100;

    public function getUnprocessedDeliveryNoteLines(): array
    {
        $rows = [];
        $skip = 0;

        do {
            $query = array_filter([
                'skip' => $skip,
                'take' => self::TAKE,
                'filterfieldids' => config('api.afas.delivery_note_sync.filterfieldids'),
                'filtervalues' => config('api.afas.delivery_note_sync.filtervalues'),
                'operatortypes' => config('api.afas.delivery_note_sync.operatortypes'),
            ], static fn ($value): bool => $value !== null && $value !== '');

            $url = $this->getConnectorUrl();

            try {
                $response = Http::timeout(60)
                    ->acceptJson()
                    ->withHeaders([
                        'Authorization' => 'AfasToken ' . $this->apiKey(),
                    ])
                    ->get($url, $query);
            } catch (ConnectionException $exception) {
                Log::warning('AFAS delivery note GetConnector connection failed.', [
                    'url' => $url,
                    'query' => $query,
                    'message' => $exception->getMessage(),
                ]);

                throw $exception;
            }

            if ($response->failed()) {
                $this->logFailedResponse(
                    message: 'AFAS delivery note GetConnector failed.',
                    url: $url,
                    query: $query,
                    response: $response,
                );

                throw new RuntimeException(
                    'AFAS delivery note GetConnector failed with HTTP '
                    . $response->status()
                    . ' '
                    . $response->reason()
                );
            }

            $pageRows = $response->json('rows', []);

            if (! is_array($pageRows)) {
                $pageRows = [];
            }

            $rows = array_merge($rows, $pageRows);

            $skip += self::TAKE;
        } while (count($pageRows) === self::TAKE);

        return $rows;
    }

    public function markDeliveryNoteAsProcessed(string $deliveryNoteNumber): array
    {
        $deliveryNoteNumber = trim($deliveryNoteNumber);

        if ($deliveryNoteNumber === '') {
            return [
                'successful' => false,
                'skipped' => true,
                'reason' => 'empty_delivery_note_number',
                'message' => 'AFAS delivery note number is empty.',
            ];
        }

        $connector = $this->updateConnector();

        if ($connector === '') {
            return [
                'successful' => false,
                'skipped' => true,
                'reason' => 'afas_delivery_note_update_connector_not_configured',
                'message' => 'AFAS delivery note update connector is not configured.',
            ];
        }

        $url = $this->updateConnectorUrl($connector);
        $payload = $this->deliveryNoteProcessedPayload(
            connector: $connector,
            deliveryNoteNumber: $deliveryNoteNumber,
        );

        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'Authorization' => 'AfasToken ' . $this->apiKey(),
                ])
                ->send($this->updateMethod(), $url, [
                    'json' => $payload,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('AFAS delivery note UpdateConnector connection failed.', [
                'url' => $url,
                'method' => $this->updateMethod(),
                'payload' => $payload,
                'message' => $exception->getMessage(),
            ]);

            return [
                'successful' => false,
                'skipped' => false,
                'reason' => 'afas_delivery_note_update_connection_exception',
                'url' => $url,
                'method' => $this->updateMethod(),
                'payload' => $payload,
                'status' => null,
                'reason_phrase' => null,
                'content_type' => null,
                'body_length' => 0,
                'body_preview' => '',
                'body_base64' => '',
                'body' => null,
                'raw_body' => '',
                'headers' => [],
                'message' => $exception->getMessage(),
            ];
        }

        $rawBody = $response->body();
        $body = $this->responseBody($response);

        $result = [
            'successful' => $response->successful(),
            'skipped' => false,
            'reason' => $response->successful()
                ? 'afas_delivery_note_marked_processed'
                : 'afas_delivery_note_mark_processed_rejected',
            'url' => $url,
            'method' => $this->updateMethod(),
            'payload' => $payload,
            'status' => $response->status(),
            'reason_phrase' => $response->reason(),
            'content_type' => $response->header('Content-Type'),
            'body_length' => strlen($rawBody),
            'body_preview' => substr($rawBody, 0, 5000),
            'body_base64' => base64_encode($rawBody),
            'body' => $body,
            'raw_body' => $rawBody,
            'headers' => $response->headers(),
        ];

        if ($response->failed()) {
            Log::warning('AFAS delivery note UpdateConnector failed.', [
                'url' => $url,
                'method' => $this->updateMethod(),
                'payload' => $payload,
                'status' => $response->status(),
                'reason_phrase' => $response->reason(),
                'content_type' => $response->header('Content-Type'),
                'body_length' => strlen($rawBody),
                'body_preview' => substr($rawBody, 0, 5000),
                'body_base64' => base64_encode($rawBody),
                'body' => $body,
                'headers' => $response->headers(),
            ]);
        }

        return $result;
    }

    private function deliveryNoteProcessedPayload(string $connector, string $deliveryNoteNumber): array
    {
        return [
            $connector => [
                'Element' => [
                    '@OrNu' => $deliveryNoteNumber,
                    'Fields' => [
                        $this->processedField() => $this->processedValue(),
                    ],
                ],
            ],
        ];
    }

    private function getConnectorUrl(): string
    {
        $connector = (string) config(
            'api.afas.delivery_note_sync.get_connector',
            'MOT_pakbonregels_alle',
        );

        if (trim($connector) === '') {
            throw new RuntimeException('AFAS delivery note GetConnector is not configured.');
        }

        return rtrim($this->apiUrl(), '/') . '/connectors/' . trim($connector);
    }

    private function updateConnectorUrl(string $connector): string
    {
        return rtrim($this->apiUrl(), '/') . '/connectors/' . trim($connector);
    }

    private function apiUrl(): string
    {
        $apiUrl = (string) config('api.afas.api_url');

        if (trim($apiUrl) === '') {
            throw new RuntimeException('AFAS API URL is not configured.');
        }

        return trim($apiUrl);
    }

    private function apiKey(): string
    {
        $apiKey = trim((string) config('api.afas.api_key'));

        if ($apiKey === '') {
            throw new RuntimeException('AFAS API key is not configured.');
        }

        return $apiKey;
    }

    private function updateConnector(): string
    {
        return trim((string) config(
            'api.afas.delivery_note_sync.update_connector',
            'FbDeliveryNote',
        ));
    }

    private function updateMethod(): string
    {
        $method = strtoupper(trim((string) config(
            'api.afas.delivery_note_sync.update_method',
            'PUT',
        )));

        return $method !== '' ? $method : 'PUT';
    }

    private function processedField(): string
    {
        $field = trim((string) config(
            'api.afas.delivery_note_sync.processed_field',
            'Distributie_date_time',
        ));

        if ($field === '') {
            throw new RuntimeException('AFAS delivery note processed field is not configured.');
        }

        return $field;
    }

    private function processedValue(): string
    {
        $value = trim((string) config(
            'api.afas.delivery_note_sync.processed_value',
            'now',
        ));

        if ($value !== '' && strtolower($value) !== 'now') {
            return $value;
        }

        $format = (string) config(
            'api.afas.delivery_note_sync.processed_datetime_format',
            'Y-m-d\TH:i:s',
        );

        return now()->format($format);
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
            'status' => $response->status(),
            'reason_phrase' => $response->reason(),
        ];
    }

    private function logFailedResponse(
        string $message,
        string $url,
        array $query,
        Response $response,
    ): void {
        $rawBody = $response->body();

        Log::warning($message, [
            'url' => $url,
            'query' => $query,
            'status' => $response->status(),
            'reason_phrase' => $response->reason(),
            'content_type' => $response->header('Content-Type'),
            'body_length' => strlen($rawBody),
            'body_preview' => substr($rawBody, 0, 5000),
            'body_base64' => base64_encode($rawBody),
            'headers' => $response->headers(),
        ]);
    }
}
