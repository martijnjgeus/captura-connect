<?php

namespace App\Console\Commands;

use App\Models\AfasOrderItemSkuOverride;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

class ImportAfasOrderItemSkuOverridesCommand extends Command
{
    protected $signature = 'orders:import-afas-item-sku-overrides
        {path : Path to the JSON export file}';

    protected $description = 'Import AFAS item code to GoedGepickt SKU overrides.';

    public function handle(): int
    {
        $path = (string)$this->argument('path');

        if (!file_exists($path)) {
            $this->error('File does not exist: ' . $path);

            return self::FAILURE;
        }

        try {
            $rows = json_decode(
                json: file_get_contents($path),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->error('Invalid JSON: ' . $exception->getMessage());

            return self::FAILURE;
        }

        if (!is_array($rows)) {
            $this->error('JSON root must be an array.');

            return self::FAILURE;
        }

        $imported = 0;
        $skipped  = 0;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                $skipped++;

                continue;
            }

            $afasItemCode = $this->stringValue(
                $row['source_key']
                ?? $row['item_code']
                ?? $row['Itemcode']
                ?? null,
            );

            $sku = $this->stringValue(
                $row['target_key']
                ?? $row['sku']
                ?? $row['SKU']
                ?? null,
            );

            $ean = $this->stringValue(
                $row['ean']
                ?? $row['EAN']
                ?? null,
            );

            if ($afasItemCode === '' || $sku === '') {
                $skipped++;

                $this->warn('Skipped row ' . ($index + 1) . ': missing item code or SKU.');

                continue;
            }

            AfasOrderItemSkuOverride::query()->updateOrCreate(
                [
                    'afas_item_code' => $afasItemCode,
                ],
                [
                    'sku'       => $sku,
                    'ean'       => $ean !== '' ? $ean : null,
                    'is_active' => true,
                ],
            );

            $imported++;
        }

        $this->info('Imported: ' . $imported);
        $this->info('Skipped: ' . $skipped);

        return self::SUCCESS;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (!is_scalar($value)) {
            throw new RuntimeException('Expected scalar value.');
        }

        return trim((string)$value);
    }
}
