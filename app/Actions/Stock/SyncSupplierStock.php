<?php

namespace App\Actions\Stock;

use App\Mail\StockMutationRejectedMail;
use App\Services\Goedgepickt\GoedgepicktStockClient;
use App\Services\Logging\IntegrationLogger;
use App\Services\Suppliers\VerwimpFtpsClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

class SyncSupplierStock
{
    public function __construct(
        private readonly GoedGepicktStockClient $goedgepicktStockClient,
        private readonly VerwimpFtpsClient      $verwimpFtpsClient,
        private readonly IntegrationLogger      $logger,
    )
    {
    }

    public function handle(string $supplier): int
    {
        $log = $this->logger->startFeed($supplier);

        try {
            $config = config("suppliers.{$supplier}");

            if (!is_array($config)) {
                throw new RuntimeException("Supplier [{$supplier}] is not configured.");
            }

            $supplierStock = $this->getSupplierStock($config);

            $this->logger->feedProductsRead(
                log: $log,
                productsRead: count($supplierStock),
            );

            $goedgepicktStock = $this->goedgepicktStockClient->getCurrentStockForSupplier(
                supplierUuid: $config['goedgepickt_supplier_uuid'],
            );

            $mutations = $this->calculateMutations(
                supplier: $supplier,
                supplierStock: $supplierStock,
                goedgepicktStock: $goedgepicktStock,
            );

            if ($mutations === []) {
                $this->logger->feedFinished(
                    log: $log,
                    productsUpdated: 0,
                    updatesFailed: 0,
                    resultBody: [
                        'supplier_products_read'    => count($supplierStock),
                        'goedgepickt_products_read' => count($goedgepicktStock),
                        'mutations_calculated'      => 0,
                        'mutations_attempted'       => 0,
                        'mutations_succeeded'       => 0,
                        'mutations_failed'          => 0,
                        'message'                   => 'No supplier stock mutations needed.',
                    ],
                );

                Log::info('No supplier stock mutations needed.', [
                    'supplier' => $supplier,
                ]);

                return 0;
            }

            $result = $this->goedgepicktStockClient->sendMutations($mutations);

            $this->sendRejectedMutationAlert(
                supplier: $supplier,
                result: $result,
            );

            $this->logger->feedFinished(
                log: $log,
                productsUpdated: $result['succeeded'],
                updatesFailed: $result['failed'],
                resultBody: [
                    'supplier_products_read'    => count($supplierStock),
                    'goedgepickt_products_read' => count($goedgepicktStock),
                    'mutations_calculated'      => count($mutations),
                    'mutations_attempted'       => $result['attempted'],
                    'mutations_succeeded'       => $result['succeeded'],
                    'mutations_failed'          => $result['failed'],
                    'failed_items'              => $result['failed_items'] ?? [],
                ],
            );

            Log::info('Supplier stock mutations processed.', [
                'supplier'  => $supplier,
                'attempted' => $result['attempted'],
                'succeeded' => $result['succeeded'],
                'failed'    => $result['failed'],
            ]);

            return $result['succeeded'];
        } catch (Throwable $exception) {
            $this->logger->feedFailed(
                log: $log,
                exception: $exception,
            );

            throw $exception;
        }
    }

    private function getSupplierStock(array $config): array
    {
        $contents = $this->getSupplierFileContents($config);

        return match ($config['format']) {
            'csv' => $this->getCsvSupplierStock($contents, $config),
            'xml' => $this->getXmlSupplierStock($contents, $config),
            default => throw new RuntimeException("Unknown supplier file format [{$config['format']}]."),
        };
    }

    private function getCsvSupplierStock(string $contents, array $config): array
    {
        $rows = $this->parseCsv(
            contents: $contents,
            delimiter: $config['delimiter'] ?? ';',
        );

        $stock = [];

        foreach ($rows as $row) {
            $ean = $this->normalizeEan((string)($row[$config['ean_column']] ?? ''));

            if ($ean === '') {
                Log::warning('Supplier row has no EAN.', [
                    'sku' => $row[$config['sku_column']] ?? null,
                ]);

                continue;
            }

            $rawStock = $this->parseStockQuantity(
                (string)($row[$config['stock_column']] ?? '0')
            );

            $stock[$ean] = [
                'ean'          => $ean,
                'supplier_sku' => trim((string)($row[$config['sku_column']] ?? '')),
                'target_stock' => $this->transformSupplierStock(
                    stock: $rawStock,
                    config: $config,
                ),
            ];
        }

        return $stock;
    }

    private function getXmlSupplierStock(string $contents, array $config): array
    {
        $contents = trim($contents);

        if ($contents === '') {
            throw new RuntimeException('Supplier XML feed is empty.');
        }

        libxml_use_internal_errors(true);

        $xml = simplexml_load_string($contents);

        if (!$xml instanceof SimpleXMLElement) {
            Log::error('Could not parse supplier XML feed.', [
                'errors' => array_map(
                    static fn($error): string => trim($error->message),
                    libxml_get_errors()
                ),
            ]);

            libxml_clear_errors();

            throw new RuntimeException('Could not parse supplier XML feed.');
        }

        libxml_clear_errors();

        $stock = [];

        foreach ($xml->{$config['item_node']} as $item) {
            $supplierSku = $this->xmlValue($item, $config['sku_field']);
            $ean         = $this->normalizeEan(
                $this->xmlValue($item, $config['ean_field'])
            );

            $rawEan = $this->xmlValue($item, $config['ean_field']);

            if ($ean === '') {
                Log::warning('Supplier XML item has empty EAN.', [
                    'supplier_sku'     => $supplierSku,
                    'raw_ean'          => $rawEan,
                    'available_fields' => array_keys((array)$item),
                ]);

                continue;
            }

            $rawStock = $this->parseStockQuantity(
                $this->xmlValue($item, $config['stock_field'])
            );

            $stock[$ean] = [
                'ean'          => $ean,
                'supplier_sku' => $supplierSku,
                'target_stock' => $this->transformSupplierStock(
                    stock: $rawStock,
                    config: $config,
                ),
            ];
        }

        return $stock;
    }

    private function getSupplierFileContents(array $config): string
    {
        return match ($config['source'] ?? 'ftp') {
            'ftps_curl' => $this->verwimpFtpsClient->download($config),
            'ftp' => $this->getFtpFileContents($config),
            'http' => $this->getHttpFileContents($config),
            default => throw new RuntimeException("Unknown supplier source [{$config['source']}]."),
        };
    }

    private function getFtpFileContents(array $config): string
    {
        $contents = Storage::disk($config['disk'])->get($config['file']);

        if (!is_string($contents)) {
            throw new RuntimeException('Could not read supplier FTP file.');
        }

        return $contents;
    }

    private function getHttpFileContents(array $config): string
    {
        $url      = $config['url'] ?? null;
        $username = $config['username'] ?? null;
        $password = $config['password'] ?? null;

        if (!is_string($url) || $url === '') {
            throw new RuntimeException('Supplier HTTP URL is not configured.');
        }

        $request = Http::timeout(60)->accept('*/*');

        if (is_string($username) && $username !== '' && is_string($password) && $password !== '') {
            $request = $request->withBasicAuth($username, $password);
        }

        $response = $request->get($url);

        if ($response->failed()) {
            Log::error('Could not fetch supplier HTTP file.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            throw new RuntimeException('Could not fetch supplier HTTP file.');
        }

        return $response->body();
    }

    private function transformSupplierStock(int $stock, array $config): int
    {
        return match ($config['mode']) {
            'take_over_with_margin' => max(0, $stock - (int)($config['margin'] ?? 0)),
            'positive_becomes_two' => $stock > 0 ? 2 : 0,
            default => throw new RuntimeException("Unknown stock mode [{$config['mode']}]."),
        };
    }

    private function parseCsv(string $contents, string $delimiter): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents);

        if (!is_array($lines)) {
            return [];
        }

        $lines = array_values(array_filter(
            $lines,
            static fn(string $line): bool => trim($line) !== ''
        ));

        if ($lines === []) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines), $delimiter);

        if ($headers === false || $headers === [null]) {
            return [];
        }

        $headers = array_map(
            static fn(string $header): string => trim($header, "\xEF\xBB\xBF \t\n\r\0\x0B"),
            $headers,
        );

        return array_values(array_filter(array_map(
            static function (string $line) use ($headers, $delimiter): array {
                $values = str_getcsv($line, $delimiter);

                if (count($headers) !== count($values)) {
                    return [];
                }

                return array_combine($headers, $values) ?: [];
            },
            $lines
        )));
    }

    private function parseStockQuantity(string $value): int
    {
        $value = trim($value);

        if ($value === '') {
            return 0;
        }

        /*
         * Suppliers kan voorraadwaarden hebben zoals "6.446".
         * Dat behandelen we als duizendtalsnotatie: 6446.
         */
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '', $value);

        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int)$value);
    }

    private function calculateMutations(
        string $supplier,
        array  $supplierStock,
        array  $goedgepicktStock,
    ): array
    {
        $eans = array_values(array_unique([
            ...array_keys($goedgepicktStock),
            ...array_keys($supplierStock),
        ]));

        $mutations = [];

        foreach ($eans as $ean) {
            $goedgepicktProduct = $goedgepicktStock[$ean] ?? null;

            if (!is_array($goedgepicktProduct)) {
                Log::warning('Supplier EAN not found in GoedGepickt supplier stock.', [
                    'supplier'     => $supplier,
                    'ean'          => $ean,
                    'supplier_sku' => $supplierStock[$ean]['supplier_sku'] ?? null,
                    'target_stock' => $supplierStock[$ean]['target_stock'] ?? null,
                ]);

                continue;
            }

            $productUuid = $goedgepicktProduct['uuid'] ?? null;

            if (!is_string($productUuid) || $productUuid === '') {
                Log::warning('GoedGepickt product has no UUID.', [
                    'supplier' => $supplier,
                    'ean'      => $ean,
                    'sku'      => $goedgepicktProduct['sku'] ?? null,
                ]);

                continue;
            }

            $current = (int)($goedgepicktProduct['stock'] ?? 0);

            /*
             * Staat een EAN wel in GoedGepickt onder deze leverancier,
             * maar niet meer in de leverancierfeed? Dan wordt target 0.
             */
            $target = (int)($supplierStock[$ean]['target_stock'] ?? 0);

            $delta = $target - $current;

            if ($delta === 0) {
                continue;
            }

            $mutations[] = [
                'product_uuid' => $productUuid,
                'ean'          => $ean,
                'sku'          => $goedgepicktProduct['sku'] ?? null,
                'supplier_sku' => $supplierStock[$ean]['supplier_sku'] ?? null,
                'delta'        => $delta,
                'stock_before' => $current,
                'stock_after'  => $target,
                'source'       => $supplier,
            ];
        }

        return $mutations;
    }

    private function normalizeEan(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return preg_replace('/\D/', '', $value) ?? '';
    }

    private function xmlValue(SimpleXMLElement $item, string $field): string
    {
        $field = trim($field);

        foreach ($item->children() as $key => $value) {
            if (strcasecmp(trim((string)$key), $field) === 0) {
                return trim((string)$value);
            }
        }

        return '';
    }

    private function sendRejectedMutationAlert(string $supplier, array $result): void
    {
        $failedItems = $result['failed_items'] ?? [];

        if (! is_array($failedItems) || $failedItems === []) {
            return;
        }

        $rejectedItems = array_values(array_filter(
            $failedItems,
            static fn (array $item): bool => ($item['reason'] ?? null) === 'goedgepickt_rejected_mutation',
        ));

        if ($rejectedItems === []) {
            return;
        }

        $recipient = config('alerts.supply_chain_email');

        if (! is_string($recipient) || $recipient === '') {
            Log::warning('Supply chain alert email is not configured.');

            return;
        }

        try {
            Mail::to($recipient)->send(
                new StockMutationRejectedMail(
                    supplier: $supplier,
                    failedItems: $rejectedItems,
                )
            );
        } catch (\Throwable $exception) {
            Log::warning('Could not send stock mutation rejected email.', [
                'supplier' => $supplier,
                'recipient' => $recipient,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
