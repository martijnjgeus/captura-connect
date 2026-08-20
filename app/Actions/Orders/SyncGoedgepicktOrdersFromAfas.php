<?php

namespace App\Actions\Orders;

use App\Models\AfasOrderItemSkuOverride;
use App\Models\ProcessedAfasDeliveryNote;
use App\Services\Afas\AfasDeliveryNoteClient;
use App\Services\Goedgepickt\GoedgepicktOrderClient;
use App\Services\Logging\IntegrationLogger;
use App\Models\IntegrationLog;
use Illuminate\Support\Carbon;
use Throwable;

class SyncGoedgepicktOrdersFromAfas
{
    /*
     * Exact AFAS GetConnector field mapping.
     *
     * These are intentionally not configurable through config/api.php.
     * If the AFAS GetConnector field name changes, update it here.
     */
    private const string AFAS_DELIVERY_NOTE_NUMBER_FIELD = 'Pakbonnummer';
    private const string AFAS_DELIVERY_NOTE_LINE_NUMBER_FIELD = 'Regelnummer';
    private const string AFAS_ADMINISTRATION_FIELD = 'Administratie';

    private const string AFAS_CREATE_DATE_FIELD = 'Pakbonkop_gewijzigd_op';

    private const string AFAS_GOEDGEPICKT_SKU_FIELD = 'CMS_ID';
    private const string AFAS_GOEDGEPICKT_EAN_FIELD = 'Dimensie_barcode';
    private const string AFAS_QUANTITY_FIELD = 'Aantal';
    private const string AFAS_PRODUCT_NAME_FIELD = 'Omschrijving';

    private const string AFAS_SHIPPING_COMPANY_FIELD = 'Verkooprelatie_naam';
    private const string AFAS_SHIPPING_ADDRESS_FIELD = 'Afleveradres_straat';
    private const string AFAS_SHIPPING_HOUSE_NUMBER_FIELD = 'Afleveradres_huisnr';
    private const string AFAS_SHIPPING_HOUSE_NUMBER_ADDITION_FIELD = 'Afleveradres_toev';
    private const string AFAS_SHIPPING_ZIPCODE_FIELD = 'Afleveradres_postcode';
    private const string AFAS_SHIPPING_CITY_FIELD = 'Afleveradres_plaats';
    private const string AFAS_SHIPPING_COUNTRY_FIELD = 'Afleveradres_land';
    private const string AFAS_TAX_RATE_FIELD = 'Btw_percentage';

    private const string AFAS_ITEM_CODE_FIELD = 'Itemcode';
    private ?array $skuOverridesByAfasItemCode = null;

    public function __construct(
        private readonly AfasDeliveryNoteClient $afasDeliveryNoteClient,
        private readonly GoedgepicktOrderClient $goedgepicktOrderClient,
        private readonly IntegrationLogger      $logger,
    )
    {
    }

    public function handle(bool $dryRun = true): array
    {
        $log = $this->logger->startFeed('afas_to_goedgepickt_orders');

        try {
            $lines = $this->afasDeliveryNoteClient->getUnprocessedDeliveryNoteLines();

            $this->logger->feedProductsRead($log, count($lines));

            $groupResult = $this->groupLinesByDeliveryNote($lines);

            $orders                             = [];
            $deliveryNotesToMarkProcessedInAfas = [];

            $alreadyMarkedProcessed     = 0;
            $alreadyPostedToGoedgepickt = 0;

            $goedgepicktAttempted = 0;
            $goedgepicktSucceeded = 0;
            $goedgepicktFailed    = 0;

            $afasMarkProcessedAttempted = 0;
            $afasMarkProcessedSucceeded = 0;
            $afasMarkProcessedFailed    = 0;
            $afasMarkProcessedSkipped   = 0;

            $skippedNotPostable   = 0;
            $failedItems          = [];
            $goedgepicktResponses = [];

            foreach ($groupResult['groups'] as $deliveryNoteNumber => $deliveryNoteLines) {
                $processedDeliveryNote = ProcessedAfasDeliveryNote::query()
                    ->where('afas_delivery_note_number', $deliveryNoteNumber)
                    ->first();

                if (
                    $processedDeliveryNote instanceof ProcessedAfasDeliveryNote
                    && $processedDeliveryNote->status === ProcessedAfasDeliveryNote::STATUS_MARKED_PROCESSED_IN_AFAS
                ) {
                    $alreadyMarkedProcessed++;

                    continue;
                }

                $payload = $this->buildGoedgepicktOrderPayload(
                    deliveryNoteNumber: $deliveryNoteNumber,
                    lines: $deliveryNoteLines,
                );

                $orders[] = [
                    'afas_delivery_note_number'     => $deliveryNoteNumber,
                    'already_posted_to_goedgepickt' => $processedDeliveryNote instanceof ProcessedAfasDeliveryNote
                        && $processedDeliveryNote->status === ProcessedAfasDeliveryNote::STATUS_POSTED_TO_GOEDGEPICKT,
                    'webshop_uuid'                  => $payload['webshopUuid'] ?? '',
                    'order_items_count'             => count($payload['orderItems'] ?? []),
                    'payload'                       => $payload,
                ];

                if (!$this->payloadIsPostable($payload)) {
                    $skippedNotPostable++;

                    $failedItems[] = [
                        'reason'                    => 'skipped_not_postable_goedgepickt_order',
                        'afas_delivery_note_number' => $deliveryNoteNumber,
                        'message'                   => 'Order is missing orderId, webshopUuid or valid order items.',
                        'payload'                   => $payload,
                    ];

                    continue;
                }

                if ($dryRun) {
                    continue;
                }

                if (
                    $processedDeliveryNote instanceof ProcessedAfasDeliveryNote
                    && $processedDeliveryNote->status === ProcessedAfasDeliveryNote::STATUS_POSTED_TO_GOEDGEPICKT
                ) {
                    $alreadyPostedToGoedgepickt++;

                    $deliveryNotesToMarkProcessedInAfas[$deliveryNoteNumber] = $processedDeliveryNote;

                    continue;
                }

                $goedgepicktAttempted++;

                $processedDeliveryNote = ProcessedAfasDeliveryNote::query()->updateOrCreate(
                    [
                        'afas_delivery_note_number' => $deliveryNoteNumber,
                    ],
                    [
                        'status'        => ProcessedAfasDeliveryNote::STATUS_PENDING,
                        'error_message' => null,
                    ],
                );

                $goedgepicktResult = $this->goedgepicktOrderClient->postOrder($payload);

                $goedgepicktResponseForLog = [
                    'afas_delivery_note_number' => $deliveryNoteNumber,
                    'successful'                => $goedgepicktResult['successful'] ?? false,
                    'reason'                    => $goedgepicktResult['reason'] ?? null,
                    'url'                       => $goedgepicktResult['url'] ?? null,
                    'status'                    => $goedgepicktResult['status'] ?? null,
                    'reason_phrase'             => $goedgepicktResult['reason_phrase'] ?? null,
                    'content_type'              => $goedgepicktResult['content_type'] ?? null,
                    'body_length'               => $goedgepicktResult['body_length'] ?? null,
                    'body_preview'              => $goedgepicktResult['body_preview'] ?? null,
                    'body_base64'               => $goedgepicktResult['body_base64'] ?? null,
                    'body'                      => $goedgepicktResult['body'] ?? null,
                    'raw_body'                  => $goedgepicktResult['raw_body'] ?? null,
                    'headers'                   => $goedgepicktResult['headers'] ?? null,
                    'goedgepickt_order_uuid'    => $goedgepicktResult['goedgepickt_order_uuid'] ?? null,
                    'payload'                   => $payload,
                ];

                $goedgepicktResponses[] = $goedgepicktResponseForLog;

                $this->appendGoedgepicktResponseToLog(
                    log: $log,
                    response: $goedgepicktResponseForLog,
                );

                if (($goedgepicktResult['successful'] ?? false) !== true) {
                    $goedgepicktFailed++;

                    $processedDeliveryNote->update([
                        'status'               => ProcessedAfasDeliveryNote::STATUS_FAILED,
                        'goedgepickt_response' => $goedgepicktResult,
                        'error_message'        => $goedgepicktResult['body_preview']
                            ?? $goedgepicktResult['message']
                                ?? 'GoedGepickt order post failed.',
                    ]);

                    $failedItems[] = [
                        'reason'                    => 'goedgepickt_order_failed',
                        'afas_delivery_note_number' => $deliveryNoteNumber,
                        'result'                    => $goedgepicktResult,
                        'payload'                   => $payload,
                    ];

                    continue;
                }

                $goedgepicktSucceeded++;

                $processedDeliveryNote->update([
                    'status'                   => ProcessedAfasDeliveryNote::STATUS_POSTED_TO_GOEDGEPICKT,
                    'goedgepickt_order_uuid'   => $goedgepicktResult['goedgepickt_order_uuid'] ?? null,
                    'goedgepickt_response'     => $goedgepicktResult,
                    'error_message'            => null,
                    'posted_to_goedgepickt_at' => now(),
                ]);

                $deliveryNotesToMarkProcessedInAfas[$deliveryNoteNumber] = $processedDeliveryNote;
            }

            if (!$dryRun) {
                foreach ($deliveryNotesToMarkProcessedInAfas as $deliveryNoteNumber => $processedDeliveryNote) {
                    $afasResult = $this->afasDeliveryNoteClient->markDeliveryNoteAsProcessed($deliveryNoteNumber);

                    if (($afasResult['skipped'] ?? false) === true) {
                        $afasMarkProcessedSkipped++;

                        $failedItems[] = [
                            'reason'                    => $afasResult['reason'] ?? 'afas_mark_processed_skipped',
                            'afas_delivery_note_number' => $deliveryNoteNumber,
                            'result'                    => $afasResult,
                        ];

                        continue;
                    }

                    $afasMarkProcessedAttempted++;

                    if (($afasResult['successful'] ?? false) === true) {
                        $afasMarkProcessedSucceeded++;

                        $processedDeliveryNote->update([
                            'status'                      => ProcessedAfasDeliveryNote::STATUS_MARKED_PROCESSED_IN_AFAS,
                            'marked_processed_in_afas_at' => now(),
                            'error_message'               => null,
                        ]);

                        continue;
                    }

                    $afasMarkProcessedFailed++;

                    $processedDeliveryNote->update([
                        'status'        => ProcessedAfasDeliveryNote::STATUS_POSTED_TO_GOEDGEPICKT,
                        'error_message' => $afasResult['body_preview']
                            ?? $afasResult['message']
                                ?? 'AFAS mark processed failed.',
                    ]);

                    $failedItems[] = [
                        'reason'                    => 'afas_mark_processed_failed',
                        'afas_delivery_note_number' => $deliveryNoteNumber,
                        'result'                    => $afasResult,
                    ];
                }
            }

            $resultBody = [
                'dry_run'                     => $dryRun,
                'afas_lines_read'             => count($lines),
                'afas_delivery_notes_grouped' => count($groupResult['groups']),
                'skipped_lines'               => count($groupResult['skipped_lines']),
                'skipped_line_items'          => $groupResult['skipped_lines'],
                'skipped_not_postable_orders' => $skippedNotPostable,

                'already_marked_processed_locally'      => $alreadyMarkedProcessed,
                'already_posted_to_goedgepickt_locally' => $alreadyPostedToGoedgepickt,

                'orders_built' => count($orders),

                'goedgepickt_attempted' => $goedgepicktAttempted,
                'goedgepickt_succeeded' => $goedgepicktSucceeded,
                'goedgepickt_failed'    => $goedgepicktFailed,
                'goedgepickt_responses' => $goedgepicktResponses,

                'afas_mark_processed_queued'    => count($deliveryNotesToMarkProcessedInAfas),
                'afas_mark_processed_attempted' => $afasMarkProcessedAttempted,
                'afas_mark_processed_succeeded' => $afasMarkProcessedSucceeded,
                'afas_mark_processed_failed'    => $afasMarkProcessedFailed,
                'afas_mark_processed_skipped'   => $afasMarkProcessedSkipped,

                'failed_items'  => $failedItems,
                'sample_orders' => array_slice($orders, 0, 5),
            ];

            $this->logger->feedFinished(
                log: $log,
                productsUpdated: $goedgepicktSucceeded + $afasMarkProcessedSucceeded,
                updatesFailed: $goedgepicktFailed
                + $afasMarkProcessedFailed
                + $afasMarkProcessedSkipped
                + $skippedNotPostable
                + count($groupResult['skipped_lines']),
                resultBody: $resultBody,
            );

            return [
                'dry_run'                     => $dryRun,
                'afas_lines_read'             => count($lines),
                'afas_delivery_notes_grouped' => count($groupResult['groups']),
                'skipped_lines'               => count($groupResult['skipped_lines']),
                'skipped_not_postable_orders' => $skippedNotPostable,

                'already_marked_processed_locally'      => $alreadyMarkedProcessed,
                'already_posted_to_goedgepickt_locally' => $alreadyPostedToGoedgepickt,

                'orders_built' => count($orders),

                'goedgepickt_attempted' => $goedgepicktAttempted,
                'goedgepickt_succeeded' => $goedgepicktSucceeded,
                'goedgepickt_failed'    => $goedgepicktFailed,

                'afas_mark_processed_queued'    => count($deliveryNotesToMarkProcessedInAfas),
                'afas_mark_processed_attempted' => $afasMarkProcessedAttempted,
                'afas_mark_processed_succeeded' => $afasMarkProcessedSucceeded,
                'afas_mark_processed_failed'    => $afasMarkProcessedFailed,
                'afas_mark_processed_skipped'   => $afasMarkProcessedSkipped,
            ];
        } catch (Throwable $exception) {
            $this->logger->feedFailed($log, $exception);

            throw $exception;
        }
    }

    private function groupLinesByDeliveryNote(array $lines): array
    {
        $groups       = [];
        $skippedLines = [];

        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                $skippedLines[] = [
                    'reason'     => 'invalid_line',
                    'line_index' => $index,
                    'line'       => $line,
                ];

                continue;
            }

            $deliveryNoteNumber = $this->deliveryNoteNumber($line);

            if ($deliveryNoteNumber === null) {
                $skippedLines[] = [
                    'reason'           => 'skipped_missing_delivery_note_number',
                    'line_index'       => $index,
                    'available_fields' => array_keys($line),
                    'line'             => $line,
                ];

                continue;
            }

            $groups[$deliveryNoteNumber][] = $line;
        }

        return [
            'groups'        => $groups,
            'skipped_lines' => $skippedLines,
        ];
    }

    private function deliveryNoteNumber(array $line): ?string
    {
        return $this->exactLineValue($line, self::AFAS_DELIVERY_NOTE_NUMBER_FIELD);
    }

    private function buildGoedgepicktOrderPayload(string $deliveryNoteNumber, array $lines): array
    {
        $firstLine = $lines[0] ?? [];

        if (!is_array($firstLine)) {
            $firstLine = [];
        }

        return [
            'orderId'        => $deliveryNoteNumber,
            'orderDisplayId' => $deliveryNoteNumber,

            'createDate' => $this->dateValue(
                $this->exactLineValue($firstLine, self::AFAS_CREATE_DATE_FIELD)
            ),

            'finishDate'  => '',
            'orderStatus' => $this->goedgepicktOrderStatus(),
            'currency'    => 'EUR',

            'discountTotal' => '',
            'discountTax'   => '',
            'shippingTotal' => '',
            'shippingTax'   => '',
            'paidTotal'     => '',
            'paidTax'       => '',

            'billingFirstName'           => '',
            'billingLastName'            => '',
            'billingCompany'             => '',
            'billingAddress'             => '',
            'billingHouseNumber'         => '',
            'billingHouseNumberAddition' => '',
            'billingAddress2'            => '',
            'billingZipcode'             => '',
            'billingCity'                => '',
            'billingCountry'             => '',
            'billingEmail'               => '',
            'billingPhone'               => '',

            'shippingFirstName'           => '',
            'shippingLastName'            => '',
            'shippingCompany'             => $this->exactLineValue($firstLine, self::AFAS_SHIPPING_COMPANY_FIELD) ?? '',
            'shippingAddress'             => $this->exactLineValue($firstLine, self::AFAS_SHIPPING_ADDRESS_FIELD) ?? '',
            'shippingHouseNumber'         => $this->exactLineValue($firstLine, self::AFAS_SHIPPING_HOUSE_NUMBER_FIELD) ?? '',
            'shippingHouseNumberAddition' => $this->exactLineValue($firstLine, self::AFAS_SHIPPING_HOUSE_NUMBER_ADDITION_FIELD) ?? '',
            'shippingAddress2'            => '',
            'shippingZipcode'             => $this->exactLineValue($firstLine, self::AFAS_SHIPPING_ZIPCODE_FIELD) ?? '',
            'shippingCity'                => $this->exactLineValue($firstLine, self::AFAS_SHIPPING_CITY_FIELD) ?? '',
            'shippingCountry'             => $this->countryCode(
                $this->exactLineValue($firstLine, self::AFAS_SHIPPING_COUNTRY_FIELD)
            ),

            'paymentMethod' => '',
            'invoiceNumber' => '',
            'customerNote'  => '',

            'webshopUuid' => $this->goedgepicktWebshopUuid($firstLine),

            'orderItems' => $this->buildGoedgepicktOrderItems(
                deliveryNoteNumber: $deliveryNoteNumber,
                lines: $lines,
            ),

            'pickupLocationData' => [
                'locationNumber' => '',
                'carrier'        => '',
                'location'       => '',
                'street'         => '',
                'houseNumber'    => '',
                'zipcode'        => '',
                'city'           => '',
                'country'        => '',
            ],
        ];
    }

    private function buildGoedgepicktOrderItems(string $deliveryNoteNumber, array $lines): array
    {
        $orderItems = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            if ($this->shouldSkipAfasOrderLine($line)) {
                continue;
            }

            $identifiers = $this->orderItemIdentifiers($line);

            $sku = $identifiers['sku'];
            $ean = $identifiers['ean'];

            $orderItems[] = [
                'lineItemId' => $this->lineItemId(
                    deliveryNoteNumber: $deliveryNoteNumber,
                    line: $line,
                    index: $index,
                ),
                'productId' => '',
                'sku' => $sku,
                'ean' => $ean,
                'productName' => $this->exactLineValue($line, self::AFAS_PRODUCT_NAME_FIELD) ?? $sku,
                'productImage' => '',
                'productQuantity' => $this->quantityValue(
                    $this->exactLineValue($line, self::AFAS_QUANTITY_FIELD)
                ),
                'taxRate' => $this->taxRateValue(
                    $this->exactLineValue($line, self::AFAS_TAX_RATE_FIELD)
                ),
                'totalPriceIncl' => '',
                'totalPriceExcl' => '',
                'totalPriceTax' => '',
                'unitPriceIncl' => '',
                'unitPriceExcl' => '',
                'unitPriceTax' => '',
            ];
        }

        return $orderItems;
    }

    private function payloadIsPostable(array $payload): bool
    {
        if (trim((string)($payload['orderId'] ?? '')) === '') {
            return false;
        }

        if (trim((string)($payload['webshopUuid'] ?? '')) === '') {
            return false;
        }

        $orderItems = $payload['orderItems'] ?? [];

        if (!is_array($orderItems) || count($orderItems) === 0) {
            return false;
        }

        $lineItemIds = [];

        foreach ($orderItems as $item) {
            if (!is_array($item)) {
                return false;
            }

            $lineItemId = trim((string)($item['lineItemId'] ?? ''));

            if ($lineItemId === '') {
                return false;
            }

            if (isset($lineItemIds[$lineItemId])) {
                return false;
            }

            $lineItemIds[$lineItemId] = true;

            $sku = trim((string)($item['sku'] ?? ''));

            if ($sku === '' || $this->isPlaceholderSku($sku)) {
                return false;
            }

            if (trim((string)($item['productQuantity'] ?? '')) === '') {
                return false;
            }

            if (trim((string)($item['taxRate'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private function goedgepicktWebshopUuid(array $line): string
    {
        $administration = $this->administrationValue($line);

        if ($administration === '') {
            return '';
        }

        $webshops = config('api.goedgepickt.orders_webshops', []);

        if (!is_array($webshops)) {
            return '';
        }

        foreach ($webshops as $webshop) {
            if (!is_array($webshop)) {
                continue;
            }

            $webshopAdministration = $webshop['administration'] ?? null;

            if (!is_string($webshopAdministration) && !is_numeric($webshopAdministration)) {
                continue;
            }

            if ((string)$webshopAdministration !== $administration) {
                continue;
            }

            $webshopUuid = $webshop['webshop_uuid'] ?? null;

            if (!is_string($webshopUuid) || trim($webshopUuid) === '') {
                return '';
            }

            return trim($webshopUuid);
        }

        return '';
    }

    private function administrationValue(array $line): string
    {
        return $this->exactLineValue($line, self::AFAS_ADMINISTRATION_FIELD) ?? '';
    }

    private function exactLineValue(array $line, string $field): ?string
    {
        if (!array_key_exists($field, $line)) {
            return null;
        }

        $value = $line[$field];

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    private function isPlaceholderSku(string $sku): bool
    {
        $sku = trim($sku);

        if ($sku === '') {
            return false;
        }

        return preg_match('/^\*+$/', $sku) === 1;
    }

    private function dateValue(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return now()->toDateTimeString();
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (Throwable) {
            return $value;
        }
    }

    private function quantityValue(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '1';
        }

        $normalized = str_replace(',', '.', trim($value));

        if (!is_numeric($normalized)) {
            return '1';
        }

        return (string)max(1, (int)ceil((float)$normalized));
    }

    private function countryCode(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'NL';
        }

        $value = strtoupper(trim($value));

        return match ($value) {
            'NEDERLAND', 'THE NETHERLANDS', 'NETHERLANDS' => 'NL',
            'BELGIË', 'BELGIE', 'BELGIUM' => 'BE',
            'DUITSLAND', 'GERMANY', 'DEUTSCHLAND' => 'DE',
            'FRANKRIJK', 'FRANCE' => 'FR',
            default => $value,
        };
    }

    private function goedgepicktOrderStatus(): string
    {
        $status = config('api.goedgepickt.orders_status', 'open');

        if (!is_string($status) || trim($status) === '') {
            return 'open';
        }

        return trim($status);
    }

    private function appendGoedgepicktResponseToLog(IntegrationLog $log, array $response): void
    {
        $log->refresh();

        $currentResultBody = is_array($log->result_body)
            ? $log->result_body
            : [];

        $responses = $currentResultBody['goedgepickt_responses'] ?? [];

        if (!is_array($responses)) {
            $responses = [];
        }

        $responses[] = $response;

        $currentResultBody['goedgepickt_responses'] = $responses;

        $log->update([
            'result_body' => $currentResultBody,
            'http_status' => $response['status'] ?? $log->http_status,
        ]);
    }

    private function taxRateValue(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = trim(str_replace(',', '.', $value));

        if ($value === '') {
            return '';
        }

        if (!is_numeric($value)) {
            return '';
        }

        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.');
    }

    private function lineItemId(string $deliveryNoteNumber, array $line, int $index): string
    {
        $lineNumber = $this->exactLineValue($line, self::AFAS_DELIVERY_NOTE_LINE_NUMBER_FIELD);

        if ($lineNumber !== null) {
            return $deliveryNoteNumber . '-' . $lineNumber . '-' . ($index + 1);
        }

        return $deliveryNoteNumber . '-' . ($index + 1);
    }

    private function isTransportCostLine(array $line): bool
    {
        $productName = $this->exactLineValue($line, self::AFAS_PRODUCT_NAME_FIELD);

        if ($productName === null) {
            return false;
        }

        $productName = mb_strtolower(trim($productName));

        return $productName === 'transportkosten';
    }

    private function orderItemIdentifiers(array $line): array
    {
        $sku = $this->exactLineValue($line, self::AFAS_GOEDGEPICKT_SKU_FIELD) ?? '';
        $ean = $this->exactLineValue($line, self::AFAS_GOEDGEPICKT_EAN_FIELD) ?? '';

        if ($sku !== '' && $ean !== '') {
            return [
                'sku' => $sku,
                'ean' => $ean,
            ];
        }

        $override = $this->skuOverrideForLine($line);

        if ($override === null) {
            return [
                'sku' => $sku,
                'ean' => $ean,
            ];
        }

        if ($sku === '') {
            $sku = $override['sku'] ?? '';
        }

        if ($ean === '') {
            $ean = $override['ean'] ?? '';
        }

        return [
            'sku' => trim((string) $sku),
            'ean' => trim((string) $ean),
        ];
    }

    private function skuOverrideForLine(array $line): ?array
    {
        $afasItemCode = $this->exactLineValue($line, self::AFAS_ITEM_CODE_FIELD);

        if ($afasItemCode === null) {
            return null;
        }

        $overrides = $this->skuOverridesByAfasItemCode();

        return $overrides[$afasItemCode] ?? null;
    }

    private function skuOverridesByAfasItemCode(): array
    {
        if ($this->skuOverridesByAfasItemCode !== null) {
            return $this->skuOverridesByAfasItemCode;
        }

        $this->skuOverridesByAfasItemCode = AfasOrderItemSkuOverride::query()
            ->where('is_active', true)
            ->get([
                'afas_item_code',
                'sku',
                'ean',
            ])
            ->mapWithKeys(static function (AfasOrderItemSkuOverride $override): array {
                return [
                    trim((string) $override->afas_item_code) => [
                        'sku' => trim((string) $override->sku),
                        'ean' => trim((string) $override->ean),
                    ],
                ];
            })
            ->all();

        return $this->skuOverridesByAfasItemCode;
    }

    private function shouldSkipAfasOrderLine(array $line): bool
    {
        if ($this->isTransportCostLine($line)) {
            return true;
        }

        $cmsId = $this->exactLineValue($line, self::AFAS_GOEDGEPICKT_SKU_FIELD) ?? '';

        if ($this->isPlaceholderSku($cmsId)) {
            return true;
        }

        $itemCode = $this->exactLineValue($line, self::AFAS_ITEM_CODE_FIELD) ?? '';

        if ($this->isPlaceholderSku($itemCode)) {
            return true;
        }

        return false;
    }
}
