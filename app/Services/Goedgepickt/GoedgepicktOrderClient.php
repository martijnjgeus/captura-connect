<?php

namespace App\Services\Goedgepickt;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GoedgepicktOrderClient
{
    public function postOrder(array $payload): array
    {
        $url = $this->url();

        try {
            $response = $this->sendOrder($payload);
        } catch (ConnectionException $exception) {
            Log::warning('GoedGepickt order connection failed.', [
                'url' => $url,
                'message' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return [
                'successful' => false,
                'reason' => 'goedgepickt_connection_exception',
                'url' => $url,
                'status' => null,
                'reason_phrase' => null,
                'content_type' => null,
                'body_length' => 0,
                'body_preview' => '',
                'body_base64' => '',
                'body' => null,
                'raw_body' => '',
                'headers' => [],
                'goedgepickt_order_uuid' => null,
                'message' => $exception->getMessage(),
            ];
        }

        $rawBody = $response->body();
        $body = $this->responseBody($response);

        $result = [
            'successful' => $response->successful(),
            'reason' => $response->successful()
                ? 'goedgepickt_order_created'
                : 'goedgepickt_order_rejected',
            'url' => $url,
            'status' => $response->status(),
            'reason_phrase' => $response->reason(),
            'content_type' => $response->header('Content-Type'),
            'body_length' => strlen($rawBody),
            'body_preview' => substr($rawBody, 0, 5000),
            'body_base64' => base64_encode($rawBody),
            'body' => $body,
            'raw_body' => $rawBody,
            'headers' => $response->headers(),
            'goedgepickt_order_uuid' => $this->orderUuidFromResponse($body),
        ];

        if ($response->failed()) {
            Log::warning('GoedGepickt order was rejected.', [
                'url' => $url,
                'status' => $response->status(),
                'reason_phrase' => $response->reason(),
                'content_type' => $response->header('Content-Type'),
                'body_length' => strlen($rawBody),
                'body_preview' => substr($rawBody, 0, 5000),
                'body_base64' => base64_encode($rawBody),
                'body' => $body,
                'payload' => $payload,
            ]);
        }

        return $result;
    }

    private function sendOrder(array $payload): Response
    {
        return Http::timeout(60)
            ->acceptJson()
            ->withToken($this->apiKey())
            ->send('POST', $this->url(), [
                'multipart' => $this->multipartFields($payload),
            ]);
    }

    private function multipartFields(array $payload, string $prefix = ''): array
    {
        $fields = [];

        foreach ($payload as $key => $value) {
            $fieldName = $prefix === ''
                ? (string)$key
                : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                $fields = array_merge(
                    $fields,
                    $this->multipartFields($value, $fieldName)
                );

                continue;
            }

            $fields[] = [
                'name'     => $fieldName,
                'contents' => $value === null ? '' : (string)$value,
            ];
        }

        return $fields;
    }

    private function url(): string
    {
        $apiUrl = config('api.goedgepickt.api_url');

        if (!is_string($apiUrl) || $apiUrl === '') {
            throw new RuntimeException('GoedGepickt API URL is not configured.');
        }

        $endpoint = config('api.goedgepickt.orders_endpoint', '/orders');

        if (!is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException('GoedGepickt orders endpoint is not configured.');
        }

        return rtrim($apiUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    private function apiKey(): string
    {
        $apiKey = config('api.goedgepickt.api_key');

        if (!is_string($apiKey) || $apiKey === '') {
            throw new RuntimeException('GoedGepickt API key is not configured.');
        }

        return $apiKey;
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

    private function orderUuidFromResponse(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        $uuid = data_get($body, 'uuid')
            ?? data_get($body, 'orderUuid')
            ?? data_get($body, 'order.uuid')
            ?? data_get($body, 'data.uuid')
            ?? data_get($body, 'data.orderUuid');

        if (!is_string($uuid) && !is_numeric($uuid)) {
            return null;
        }

        return (string)$uuid;
    }
}
