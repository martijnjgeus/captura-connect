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
    private const string UNIT_DANSANTE = '10';
    private const string UNIT_OB_BRANDS = '11';
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

        $orderNumber = $this->afasOrderNumber($response);

        if ($orderNumber === null) {
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
            throw new HttpResponseException(
                response()->json([
                    'message'       => 'AFAS returned an error.',
                    'afas_status'   => $response->status(),
                    'afas_response' => $response->json() ?? $response->body(),
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
                        'DlAd'  => $order['shipping_address']['id'],
                        'War'   => $this->warehouseCode($order),
                        'Unit'  => $this->unitForCompany($order),
                        'Fref'  => (string)$order['id'],
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

    private function afasOrderNumber(array $response): ?string
    {
        $orderNumber = data_get($response, 'results.FbSales.OrNu');

        if (!is_string($orderNumber) && !is_numeric($orderNumber)) {
            $orderNumber = data_get($response, 'OrNu');
        }

        if (!is_string($orderNumber) && !is_numeric($orderNumber)) {
            return null;
        }

        return (string)$orderNumber;
    }

    private function unitForCompany(array $order): string
    {
        $company = $order['company'] ?? '';

        if (!is_string($company)) {
            return self::UNIT_DANSANTE;
        }

        return match (strtolower(trim($company))) {
            'rucanor',
            'ob-brands',
            'ob brands' => self::UNIT_OB_BRANDS,

            'dansante' => self::UNIT_DANSANTE,

            default => self::UNIT_DANSANTE,
        };
    }
}
