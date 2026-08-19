<?php

namespace App\Actions\Stock;

use App\Services\Afas\AfasStockClient;
use App\Services\Goedgepickt\GoedgepicktStockClient;
use App\Services\Logging\IntegrationLogger;
use RuntimeException;
use Throwable;

class SyncAfasStockFromGoedgepickt
{
    public function __construct(
        private readonly GoedgepicktStockClient $goedgepicktStockClient,
        private readonly AfasStockClient        $afasStockClient,
        private readonly IntegrationLogger      $logger,
    )
    {
    }


    public
    function handle(bool $dryRun = true): array
    {
        $log = $this->logger->startFeed('goedgepickt_to_afas_stock');

        try {
            $products = $this->goedgepicktStockClient->getProductsForAfasStockSync(
                excludedSupplierUuids: $this->excludedSupplierUuids(),
            );

            $this->logger->feedProductsRead($log, count($products));

            $buildResult = $this->buildStockLines($products);

            $afasResult = [
                'attempted'    => 0,
                'succeeded'    => 0,
                'failed'       => 0,
                'failed_items' => [],
            ];

            if (!$dryRun && $buildResult['conflicts'] === []) {
                $afasResult = $this->afasStockClient->sendStockLines($buildResult['lines']);
            }

            $resultBody = [
                'dry_run'                   => $dryRun,
                'goedgepickt_products_read' => count($products),
                'products_skipped'          => $buildResult['products_skipped'],
                'lines_built'               => count($buildResult['lines']),
                'conflicts'                 => count($buildResult['conflicts']),
                'conflict_items'            => $buildResult['conflicts'],
                'sample_lines'              => array_slice(array_values($buildResult['lines']), 0, 25),

                'afas_attempted'     => $afasResult['attempted'],
                'afas_succeeded'     => $afasResult['succeeded'],
                'afas_failed'        => $afasResult['failed'],
                'afas_failed_items'  => $afasResult['failed_items'],
                'afas_failed_chunks' => $afasResult['failed_chunks'] ?? [],
            ];

            $this->logger->feedFinished(
                log: $log,
                productsUpdated: $afasResult['succeeded'],
                updatesFailed: count($buildResult['conflicts']) + $afasResult['failed'],
                resultBody: $resultBody,
            );

            return [
                'goedgepickt_products_read' => count($products),
                'products_skipped'          => $buildResult['products_skipped'],
                'lines_built'               => count($buildResult['lines']),
                'conflicts'                 => count($buildResult['conflicts']),
                'afas_attempted'            => $afasResult['attempted'],
                'afas_succeeded'            => $afasResult['succeeded'],
                'afas_failed'               => $afasResult['failed'],
            ];
        } catch (Throwable $exception) {
            $this->logger->feedFailed($log, $exception);

            throw $exception;
        }
    }

    private
    function buildStockLines(array $products): array
    {
        $lines           = [];
        $conflicts       = [];
        $productsSkipped = 0;

        foreach ($products as $product) {
            if (!is_array($product)) {
                $productsSkipped++;

                continue;
            }

            $itemCode   = trim((string)($product['item_code'] ?? ''));
            $dimension1 = trim((string)($product['dimension_1'] ?? ''));
            $dimension2 = trim((string)($product['dimension_2'] ?? ''));

            if ($itemCode === '' || $dimension1 === '' || $dimension2 === '') {
                $productsSkipped++;

                continue;
            }

            $supplierUuid = $product['supplier_uuid'] ?? '';

            if (!is_string($supplierUuid) || $supplierUuid === '') {
                $productsSkipped++;

                continue;
            }

            $warehouse = $this->warehouseForSupplierUuid($supplierUuid);

            $key = $itemCode . '|' . $dimension1 . '|' . $dimension2;

            if (
                isset($lines[$key])
                && $lines[$key]['warehouse'] !== $warehouse
            ) {
                $conflicts[] = [
                    'item_code'                => $itemCode,
                    'dimension_1'              => $dimension1,
                    'dimension_2'              => $dimension2,
                    'existing_warehouse'       => $lines[$key]['warehouse'],
                    'new_warehouse'            => $warehouse,
                    'supplier_uuid'            => $supplierUuid,
                    'goedgepickt_product_uuid' => $product['uuid'] ?? null,
                    'goedgepickt_url'          => $product['goedgepickt_url'] ?? null,
                ];

                continue;
            }

            $lines[$key] ??= [
                'item_code'   => $itemCode,
                'dimension_1' => $dimension1,
                'dimension_2' => $dimension2,
                'warehouse'   => $warehouse,
                'stock'       => 0,
            ];

            $lines[$key]['stock'] += (int)($product['stock'] ?? 0);
        }

        return [
            'lines'            => $lines,
            'conflicts'        => $conflicts,
            'products_skipped' => $productsSkipped,
        ];
    }

    private
    function excludedSupplierUuids(): array
    {
        return collect(config('api.afas.stock_sync.excluded_supplier_uuids', []))
            ->filter(fn(mixed $uuid): bool => is_string($uuid) && $uuid !== '')
            ->unique()
            ->values()
            ->all();
    }

    private
    function warehouseForSupplierUuid(string $supplierUuid): string
    {
        foreach (config('api.afas.stock_sync.warehouse_rules', []) as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            if (($rule['supplier_uuid'] ?? null) !== $supplierUuid) {
                continue;
            }

            $warehouse = $rule['warehouse'] ?? null;

            if (!is_string($warehouse) || $warehouse === '') {
                throw new RuntimeException('AFAS warehouse is not configured for supplier ' . $supplierUuid);
            }

            return $warehouse;
        }

        $defaultWarehouse = config('api.afas.stock_sync.default_warehouse');

        if (!is_string($defaultWarehouse) || $defaultWarehouse === '') {
            throw new RuntimeException('Default AFAS stock sync warehouse is not configured.');
        }

        return $defaultWarehouse;
    }
}
