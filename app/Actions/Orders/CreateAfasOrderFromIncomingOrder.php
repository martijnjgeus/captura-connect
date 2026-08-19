<?php

namespace App\Actions\Orders;

use App\Models\IntegrationLog;
use App\Services\Logging\IntegrationLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CreateAfasOrderFromIncomingOrder
{
    private const string UNIT = '10';
    private const string VAT_ID = '2';

    public function __construct(
        private readonly IntegrationLogger $logger,
    )
    {
    }

    public function handle(array $order, ?IntegrationLog $integrationLog = null): array
    {
        $payload = $this->mapToAfasPayload($order);

        $response = $this->sendToAfas(
            payload: $payload,
            integrationLog: $integrationLog,
        );

        $orderNumber = data_get($response, 'OrNu');

        if (!is_string($orderNumber) && !is_numeric($orderNumber)) {
            if ($integrationLog) {
                $this->logger->webhookCompletedWithWarning(
                    log: $integrationLog,
                    message: 'AFAS returned a successful HTTP status, but no order number was found.',
                    extra: [
                        'incoming_order_id' => $order['id'] ?? null,
                        'afas_response'     => $response,
                    ],
                );
            }

            throw new HttpResponseException(
                response()->json([
                    'message'       => 'Order was accepted by AFAS, but AFAS returned no order number.',
                    'afas_response' => $response,
                ], 502)
            );
        }

        return [
            'incoming_order_id' => $order['id'],
            'order_number'      => (string)$orderNumber,
        ];
    }

    private function sendToAfas(array $payload, ?IntegrationLog $integrationLog = null): array
    {
        $baseUrl = config('api.afas.api_url');
        $apiKey  = config('api.afas.api_key');

        if (!is_string($baseUrl) || $baseUrl === '') {
            $this->markLogFailed(
                integrationLog: $integrationLog,
                message: 'AFAS API URL is not configured.',
            );

            throw new HttpResponseException(
                response()->json(['message' => 'AFAS API URL is not configured.'], 500)
            );
        }

        if (!is_string($apiKey) || $apiKey === '') {
            $this->markLogFailed(
                integrationLog: $integrationLog,
                message: 'AFAS API key is not configured.',
            );

            throw new HttpResponseException(
                response()->json(['message' => 'AFAS API key is not configured.'], 500)
            );
        }

        $apiUrl = rtrim($baseUrl, '/') . '/connectors/FbSales';

        if ($integrationLog) {
            $this->logger->webhookSentBody(
                log: $integrationLog,
                sentBody: [
                    'url'  => $apiUrl,
                    'body' => $payload,
                ],
            );
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->withHeader('Authorization', 'AfasToken ' . $apiKey)
                ->post($apiUrl, $payload);
        } catch (ConnectionException $exception) {
            $this->markLogFailed(
                integrationLog: $integrationLog,
                message: 'AFAS connection failed: ' . $exception->getMessage(),
            );

            throw new HttpResponseException(
                response()->json(['message' => 'Could not connect to AFAS.'], 502)
            );
        }

        if ($integrationLog) {
            $this->logger->webhookAfasResponse(
                log: $integrationLog,
                response: $response,
            );
        }

        if ($response->failed()) {
            $rawBody = $response->body();

            Log::error('AFAS returned an error.', [
                'status'        => $response->status(),
                'reason_phrase' => $response->reason(),
                'content_type'  => $response->header('Content-Type'),
                'body_length'   => strlen($rawBody),
                'body_preview'  => substr($rawBody, 0, 5000),
                'body_base64'   => base64_encode($rawBody),
            ]);

            throw new HttpResponseException(
                response()->json([
                    'message'           => 'AFAS returned an error.',
                    'afas_status'       => $response->status(),
                    'afas_reason'       => $response->reason(),
                    'afas_content_type' => $response->header('Content-Type'),
                    'afas_body_length'  => strlen($rawBody),
                    'afas_body_preview' => substr($rawBody, 0, 5000),
                    'afas_body_base64'  => base64_encode($rawBody),
                ], 502)
            );
        }

        $data = $response->json();

        if (!is_array($data)) {
            if ($integrationLog) {
                $this->logger->webhookCompletedWithWarning(
                    log: $integrationLog,
                    message: 'AFAS returned a successful HTTP status, but the response was not valid JSON.',
                    extra: [
                        'afas_status' => $response->status(),
                        'afas_body'   => $response->body(),
                    ],
                );
            }

            throw new HttpResponseException(
                response()->json([
                    'message'     => 'AFAS accepted the request, but did not return a valid JSON response.',
                    'afas_status' => $response->status(),
                    'afas_body'   => $response->body(),
                ], 502)
            );
        }

        return $data;
    }

    private function mapToAfasPayload(array $order): array
    {
        return [
            'FbSales' => [
                'Element' => [
                    'Objects' => [
                        [
                            'FbSalesLines' => [
                                'Element' => array_map(static fn(array $line): array => [
                                    'Fields' => [
                                        'ItCd' => $line['product_code'],
                                        'StL1' => $line['size_code'],
                                        'StL2' => $line['color_code'],
                                        'QuUn' => $line['quantity'],
                                        'VaIt' => self::VAT_ID,
                                    ],
                                ], $order['line_items']),
                            ],
                        ],
                    ],
                    'Fields'  => [
                        'DbId'  => $order['relation_id'],
                        'DelAd' => $order['shipping_address']['id'],
                        'War'   => $this->warehouseCode($order),
                        'Unit'  => self::UNIT,
                        'InvAd' => $order['billing_address']['id'] ?? '',
                    ],
                ],
            ],
        ];
    }

    private function markLogFailed(?IntegrationLog $integrationLog, string $message): void
    {
        if (!$integrationLog) {
            return;
        }

        $this->logger->webhookFailed(
            log: $integrationLog,
            exception: new RuntimeException($message),
        );
    }

    private function warehouseCode(array $order): string
    {
        $company = $order['company'] ?? '';

        if (!is_string($company)) {
            return '50';
        }

        return strtolower(trim($company)) === 'rucanor'
            ? 'OB-01'
            : '50';
    }
}
