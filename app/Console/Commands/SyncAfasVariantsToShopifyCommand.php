<?php

namespace App\Console\Commands;

use App\Models\AfasShopifyVariantSync;
use App\Services\Shopify\ShopifyProductClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SyncAfasVariantsToShopifyCommand extends Command
{
    protected $signature = 'products:sync-afas-variants-to-shopify
        {--item-code= : Only sync one AFAS item code}
        {--limit=1 : Maximum number of AFAS products to sync}
        {--dry-run : Show what would be synced without writing to Shopify}';

    protected $description = 'Create Shopify products and variants from AFAS variant sync records for the configured Shopify source company.';

    public function handle(ShopifyProductClient $client): int
    {
        $sourceEanCompany = $this->shopifySourceEanCompany();

        $this->info('Shopify source EAN company: ' . $sourceEanCompany);

        $itemCodes = $this->itemCodes($sourceEanCompany);

        if ($itemCodes->isEmpty()) {
            $this->info('No AFAS variants ready for Shopify sync for company: ' . $sourceEanCompany);

            return self::SUCCESS;
        }

        foreach ($itemCodes as $itemCode) {
            $syncs = $this->syncsForItemCode($itemCode, $sourceEanCompany);

            if ($syncs->isEmpty()) {
                continue;
            }

            try {
                $preparedProduct = $this->prepareProduct($itemCode, $syncs);

                if ($this->option('dry-run')) {
                    $this->line('Would create Shopify product: ' . $preparedProduct['product']['handle']);
                    $this->line(json_encode($preparedProduct, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                    continue;
                }

                $result = $client->createProductWithVariants(
                    productInput: $preparedProduct['product'],
                    variants: $preparedProduct['variants'],
                );

                $this->storeShopifyResult($syncs, $result);

                $this->line('Created Shopify product: ' . $result['product']['handle']);
            } catch (Throwable $exception) {
                foreach ($syncs as $sync) {
                    $sync->forceFill([
                        'status'        => AfasShopifyVariantSync::STATUS_UPDATED_IN_AFAS,
                        'error_message' => $exception->getMessage(),
                    ])->save();
                }

                $this->error('Shopify sync failed for item code ' . $itemCode . ': ' . $exception->getMessage());
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function itemCodes(string $sourceEanCompany): Collection
    {
        $query = AfasShopifyVariantSync::query()
            ->where('ean_company', $sourceEanCompany)
            ->where('status', AfasShopifyVariantSync::STATUS_UPDATED_IN_AFAS)
            ->whereNotNull('allocated_ean')
            ->whereNotNull('allocated_sku')
            ->whereNull('shopify_variant_id')
            ->whereNull('synced_to_shopify_at')
            ->select('afas_item_code')
            ->distinct()
            ->orderBy('afas_item_code');

        $itemCode = trim((string)$this->option('item-code'));

        if ($itemCode !== '') {
            $query->where('afas_item_code', $itemCode);
        }

        $limit = (int)$this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->pluck('afas_item_code');
    }

    private function syncsForItemCode(string $itemCode, string $sourceEanCompany): Collection
    {
        return AfasShopifyVariantSync::query()
            ->where('ean_company', $sourceEanCompany)
            ->where('afas_item_code', $itemCode)
            ->where('status', AfasShopifyVariantSync::STATUS_UPDATED_IN_AFAS)
            ->whereNotNull('allocated_ean')
            ->whereNotNull('allocated_sku')
            ->whereNull('shopify_variant_id')
            ->whereNull('synced_to_shopify_at')
            ->orderBy('afas_variant_key')
            ->get();
    }

    private function prepareProduct(string $itemCode, Collection $syncs): array
    {
        $firstPayload = $this->payload($syncs->first());

        $title = $this->firstFilled([
            $firstPayload['Omschrijving'] ?? null,
            $firstPayload['Item_omschrijving'] ?? null,
            $itemCode,
        ]);

        $vendor = $this->firstFilled([
            $firstPayload['Item_merk_omschrijving'] ?? null,
        ]);

        $handle = Str::slug($itemCode);

        if ($handle === '') {
            throw new RuntimeException('Cannot create Shopify handle for item code: ' . $itemCode);
        }

        $dimensions = $this->dimensionsForProduct($itemCode, $syncs);

        $productOptions = $this->productOptions($syncs, $dimensions);
        $variants       = $this->variants($syncs, $dimensions);

        $product = [
            'title'          => $title,
            'handle'         => $handle,
            'status'         => strtoupper(trim((string)config('api.shopify.products.default_status', 'DRAFT'))),
            'productOptions' => $productOptions,
        ];

        if ($vendor !== '') {
            $product['vendor'] = $vendor;
        }

        return [
            'product'                => $product,
            'variants'               => $variants,
            'afas_dimension_mapping' => $dimensions,
        ];
    }

    private function dimensionsForProduct(string $itemCode, Collection $syncs): array
    {
        $hasDimension2 = $syncs->contains(
            fn(AfasShopifyVariantSync $sync): bool => trim((string)$sync->afas_dimension_2) !== ''
        );

        if ($hasDimension2) {
            $missingDimension2 = $syncs->first(
                fn(AfasShopifyVariantSync $sync): bool => trim((string)$sync->afas_dimension_2) === ''
            );

            if ($missingDimension2 !== null) {
                throw new RuntimeException(
                    'Cannot mix one-dimension and two-dimension variants in one Shopify product: '
                    . $itemCode
                );
            }
        }

        $dimensions = [
            1 => [
                'afas_type_code'      => $this->singleAfasDimensionTypeCode($syncs, 1),
                'shopify_option_name' => null,
            ],
        ];

        if ($hasDimension2) {
            $dimensions[2] = [
                'afas_type_code'      => $this->singleAfasDimensionTypeCode($syncs, 2),
                'shopify_option_name' => null,
            ];
        }

        foreach ($dimensions as $dimensionNumber => $dimension) {
            $dimensions[$dimensionNumber]['shopify_option_name'] = $this->shopifyOptionName(
                dimensionTypeCode: $dimension['afas_type_code'],
                dimensionNumber: $dimensionNumber,
            );
        }

        return $dimensions;
    }

    private function singleAfasDimensionTypeCode(Collection $syncs, int $dimensionNumber): string
    {
        $codes = $syncs
            ->map(fn(AfasShopifyVariantSync $sync): string => $this->afasDimensionTypeCode($sync, $dimensionNumber))
            ->filter(fn(string $code): bool => $code !== '')
            ->unique()
            ->values();

        if ($codes->isEmpty()) {
            throw new RuntimeException('Missing AFAS dimension type Dim_' . $dimensionNumber . '.');
        }

        if ($codes->count() > 1) {
            throw new RuntimeException(
                'Cannot create one Shopify product with mixed AFAS dimension types for Dim_'
                . $dimensionNumber
                . ': '
                . $codes->implode(', ')
            );
        }

        return (string)$codes->first();
    }

    private function productOptions(Collection $syncs, array $dimensions): array
    {
        $productOptions = [];

        foreach ($dimensions as $dimensionNumber => $dimension) {
            $optionName = $dimension['shopify_option_name'];

            $values = $syncs
                ->map(fn(AfasShopifyVariantSync $sync): string => $this->shopifyOptionValue($sync, $dimensionNumber))
                ->unique()
                ->values()
                ->map(fn(string $value): array => ['name' => $value])
                ->all();

            if ($values === []) {
                throw new RuntimeException('Missing Shopify option values for dimension ' . $dimensionNumber . '.');
            }

            $productOptions[] = [
                'name'     => $optionName,
                'position' => $dimensionNumber,
                'values'   => $values,
            ];
        }

        return $productOptions;
    }

    private function variants(Collection $syncs, array $dimensions): array
    {
        return $syncs
            ->values()
            ->map(function (AfasShopifyVariantSync $sync) use ($dimensions): array {
                $optionValues = [];

                foreach ($dimensions as $dimensionNumber => $dimension) {
                    $optionValues[] = [
                        'optionName' => $dimension['shopify_option_name'],
                        'name'       => $this->shopifyOptionValue($sync, $dimensionNumber),
                    ];
                }

                return [
                    'barcode'       => trim((string)$sync->allocated_ean),
                    'price'         => trim((string)config('api.shopify.products.default_price', '0.00')),
                    'inventoryItem' => [
                        'sku'              => trim((string)$sync->allocated_sku),
                        'tracked'          => true,
                        'requiresShipping' => true,
                    ],
                    'optionValues'  => $optionValues,
                ];
            })
            ->all();
    }

    private function afasDimensionTypeCode(AfasShopifyVariantSync $sync, int $dimensionNumber): string
    {
        $payload = $this->payload($sync);

        return trim((string)($payload['Dim_' . $dimensionNumber] ?? ''));
    }

    private function shopifyOptionName(string $dimensionTypeCode, int $dimensionNumber): string
    {
        $dimensionTypeCode = trim($dimensionTypeCode);

        if ($dimensionTypeCode === '') {
            throw new RuntimeException('Missing AFAS dimension type code for dimension ' . $dimensionNumber . '.');
        }

        $translatedName = trim((string)config('api.shopify.products.dimension_type_names.' . $dimensionTypeCode));

        if ($translatedName !== '') {
            return $translatedName;
        }

        return $dimensionTypeCode;
    }

    private function shopifyOptionValue(AfasShopifyVariantSync $sync, int $dimensionNumber): string
    {
        $payload = $this->payload($sync);

        $description = trim((string)($payload['Omschrijving_dimensie_' . $dimensionNumber] ?? ''));

        if ($this->usableAfasDescription($description)) {
            return $description;
        }

        $value = match ($dimensionNumber) {
            1 => trim((string)$sync->afas_dimension_1),
            2 => trim((string)$sync->afas_dimension_2),
            default => '',
        };

        if ($value === '') {
            throw new RuntimeException(
                'Missing AFAS dimension value Dimensie_'
                . $dimensionNumber
                . ' for variant '
                . $sync->afas_variant_key
            );
        }

        return $value;
    }

    private function usableAfasDescription(string $description): bool
    {
        $description = trim($description);

        if ($description === '') {
            return false;
        }

        return mb_strtoupper($description) !== 'NIET GEBRUIKEN';
    }

    private function storeShopifyResult(Collection $syncs, array $result): void
    {
        $product   = $result['product'];
        $productId = trim((string)($product['id'] ?? ''));

        if ($productId === '') {
            throw new RuntimeException('Shopify response is missing product ID.');
        }

        $variantsBySku = collect($result['variants'] ?? [])
            ->filter(fn($variant): bool => is_array($variant) && trim((string)($variant['sku'] ?? '')) !== '')
            ->keyBy(fn(array $variant): string => trim((string)$variant['sku']));

        foreach ($syncs as $sync) {
            $sku     = trim((string)$sync->allocated_sku);
            $variant = $variantsBySku->get($sku);

            if (!is_array($variant)) {
                throw new RuntimeException('Shopify response is missing variant for SKU: ' . $sku);
            }

            $variantId = trim((string)($variant['id'] ?? ''));

            if ($variantId === '') {
                throw new RuntimeException('Shopify response is missing variant ID for SKU: ' . $sku);
            }

            $sync->forceFill([
                'shopify_product_id'   => $productId,
                'shopify_variant_id'   => $variantId,
                'shopify_response'     => [
                    'product' => $product,
                    'variant' => $variant,
                    'raw'     => $result['raw'] ?? null,
                ],
                'status'               => AfasShopifyVariantSync::STATUS_SYNCED_TO_SHOPIFY,
                'synced_to_shopify_at' => now(),
                'error_message'        => null,
            ])->save();
        }
    }

    private function shopifySourceEanCompany(): string
    {
        $company = trim((string)config('api.shopify.products.source_ean_company'));

        if ($company === '') {
            throw new RuntimeException('Missing config: api.shopify.products.source_ean_company');
        }

        return $company;
    }

    private function payload(?AfasShopifyVariantSync $sync): array
    {
        if ($sync === null) {
            return [];
        }

        return is_array($sync->afas_payload) ? $sync->afas_payload : [];
    }

    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string)$value);

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
