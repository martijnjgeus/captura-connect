<?php

namespace App\Services\Afas;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AfasProductVariantClient
{
    public function updateVariantCodes(
        string  $itemCode,
        string  $dimension1,
        ?string $dimension2,
        string  $cmsId,
        string  $ean
    ): array
    {
        $itemCode   = trim($itemCode);
        $dimension1 = trim($dimension1);
        $dimension2 = trim((string)$dimension2);
        $cmsId      = trim($cmsId);
        $ean        = trim($ean);

        if ($itemCode === '') {
            throw new RuntimeException('Cannot update AFAS variant without item code.');
        }

        if ($dimension1 === '') {
            throw new RuntimeException('Cannot update AFAS variant without dimension 1.');
        }

        if ($cmsId === '') {
            throw new RuntimeException('Cannot update AFAS variant without CMS ID.');
        }

        if ($ean === '') {
            throw new RuntimeException('Cannot update AFAS variant without EAN.');
        }

        $connector = $this->connector();

        $fields = [
            $this->configField('update_item_type_field', 'VaIt')                           => $this->configValue('update_item_type_value', '2'),
            $this->configField('update_item_code_field', 'ItCd')                           => $itemCode,
            $this->configField('update_dimension_1_field', 'StL1')                         => $dimension1,
            $this->configField('update_barcode_type_field', 'VaBc')                        => $this->configValue('update_barcode_type_value', '3'),
            $this->configField('update_barcode_field', 'BaCo')                             => $ean,
            $this->configField('update_cms_id_field', 'U786FD90C040B41329F2BF4A90100D4C6') => $cmsId,
        ];

        if ($dimension2 !== '') {
            $fields[$this->configField('update_dimension_2_field', 'StL2')] = $dimension2;
        }

        $payload = [
            $connector => [
                'Element' => [
                    'Fields' => $fields,
                ],
            ],
        ];

        $response = Http::timeout(60)
            ->asJson()
            ->acceptJson()
            ->withHeader('Authorization', 'AfasToken ' . trim((string)config('api.afas.api_key')))
            ->send($this->method(), $this->url($connector), [
                'json' => $payload,
            ]);

        $body = $response->json() ?? $response->body();

        if (!$response->successful()) {
            throw new RuntimeException(json_encode([
                'message' => 'AFAS product variant update failed.',
                'status'  => $response->status(),
                'reason'  => $response->reason(),
                'payload' => $payload,
                'body'    => $body,
            ], JSON_PRETTY_PRINT));
        }

        return [
            'status'  => $response->status(),
            'reason'  => $response->reason(),
            'payload' => $payload,
            'body'    => $body,
        ];
    }

    private function connector(): string
    {
        $connector = trim((string)config('api.afas.product_variant_sync.update_connector', 'FbUpdateAdB'));

        if ($connector === '') {
            throw new RuntimeException('Missing config: api.afas.product_variant_sync.update_connector');
        }

        return $connector;
    }

    private function method(): string
    {
        return strtoupper(trim((string)config('api.afas.product_variant_sync.update_method', 'PUT')));
    }

    private function url(string $connector): string
    {
        $baseUrl = rtrim((string)config('api.afas.api_url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('Missing config: api.afas.api_url');
        }

        return $baseUrl . '/connectors/' . $connector;
    }

    private function configField(string $key, string $default): string
    {
        $value = trim((string)config('api.afas.product_variant_sync.' . $key, $default));

        if ($value === '') {
            throw new RuntimeException('Missing config: api.afas.product_variant_sync.' . $key);
        }

        return $value;
    }

    private function configValue(string $key, string $default): string
    {
        $value = trim((string)config('api.afas.product_variant_sync.' . $key, $default));

        if ($value === '') {
            throw new RuntimeException('Missing config: api.afas.product_variant_sync.' . $key);
        }

        return $value;
    }
}
