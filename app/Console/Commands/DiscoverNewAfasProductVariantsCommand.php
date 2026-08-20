<?php

namespace App\Console\Commands;

use App\Models\AfasShopifyVariantSync;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DiscoverNewAfasProductVariantsCommand extends Command
{
    protected $signature = 'products:discover-new-afas-variants
        {--hours=24 : How far back to look at Aangemaakt_op}
        {--limit= : Stop after this many matching variants}
        {--dry-run : Show what would be created without writing to the database}';

    protected $description = 'Discover newly created AFAS product variants without CMS_ID and barcode, and store them locally for later processing.';

    public function handle(): int
    {
        $connector = config('api.afas.product_variant_sync.get_connector');

        if (!is_string($connector) || trim($connector) === '') {
            $this->error('Missing config: api.afas.product_variant_sync.get_connector');
            return self::FAILURE;
        }

        $createdAfter = now()->subHours((int)$this->option('hours'));
        $limit        = $this->option('limit') !== null ? (int)$this->option('limit') : null;

        $this->info('Discovering AFAS product variants');
        $this->line('Connector: ' . $connector);
        $this->line('Created after: ' . $createdAfter->toIso8601String());

        $created = 0;
        $seen    = 0;
        $skipped = 0;

        foreach ($this->fetchRows($connector, $createdAfter) as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            if (!$this->isCandidate($row, $createdAfter)) {
                $this->warn('Skipped candidate: ' . json_encode([
                        'Itemcode'           => $row['Itemcode'] ?? null,
                        'Dimensie_1'         => $row['Dimensie_1'] ?? null,
                        'Dimensie_2'         => $row['Dimensie_2'] ?? null,
                        'Aangemaakt_op'      => $row['Aangemaakt_op'] ?? null,
                        'CMS_ID'             => $row['CMS_ID'] ?? null,
                        'Barcode_opgschoond' => $row['Barcode_opgschoond'] ?? null,
                    ]));
                $skipped++;
                continue;
            }

            $seen++;

            $variantKey = $this->variantKey($row);

            if ($variantKey === null) {
                $skipped++;
                $this->warn('Skipped row without complete variant key: ' . json_encode($row));
                continue;
            }

            $data = [
                'afas_variant_key' => $variantKey,
                'afas_item_code'   => $this->value($row, 'item_code_field'),
                'afas_dimension_1' => $this->value($row, 'dimension_1_field'),
                'afas_dimension_2' => $this->value($row, 'dimension_2_field'),
                'status'           => AfasShopifyVariantSync::STATUS_WAITING_FOR_EAN_COMPANY,
                'error_message'    => 'Waiting for EAN company decision.',
                'afas_payload'     => $row,
            ];

            if ($this->option('dry-run')) {
                $this->line('Would create/update: ' . $variantKey);
            } else {
                AfasShopifyVariantSync::query()->updateOrCreate(
                    ['afas_variant_key' => $variantKey],
                    $data
                );
            }

            $created++;

            if ($limit !== null && $created >= $limit) {
                break;
            }
        }

        $this->info('Done.');
        $this->line('Matching variants: ' . $seen);
        $this->line(($this->option('dry-run') ? 'Would create/update: ' : 'Created/updated: ') . $created);
        $this->line('Skipped: ' . $skipped);

        return self::SUCCESS;
    }

    private function fetchRows(string $connector, CarbonInterface $createdAfter): iterable
    {
        $baseUrl  = rtrim((string)config('api.afas.api_url'), '/');
        $token    = trim((string)config('api.afas.api_key'));
        $pageSize = (int)config('api.afas.product_variant_sync.page_size', 100);

        $createdAtField = (string)config('api.afas.product_variant_sync.created_at_field', 'Aangemaakt_op');
        $cmsIdField     = (string)config('api.afas.product_variant_sync.cms_id_field', 'CMS_ID');
        $barcodeField   = (string)config('api.afas.product_variant_sync.barcode_field', 'Barcode_opgschoond');

        $createdAfterValue = $createdAfter
            ->copy()
            ->utc()
            ->format('Y-m-d\TH:i:s');

        $query = [
            'skip'           => 0,
            'take'           => $pageSize,
            'filterfieldids' => implode(',', [
                $createdAtField,
                $cmsIdField,
                $barcodeField,
            ]),
            'filtervalues'   => implode(',', [
                $createdAfterValue,
                'null',
                'null',
            ]),
            'operatortypes'  => implode(',', [
                '2', // greater than or equal
                '8', // empty
                '8', // empty
            ]),
        ];

        $skip = 0;

        while (true) {
            $query['skip'] = $skip;

            $this->line('AFAS request skip=' . $skip . ' take=' . $pageSize);

            $response = Http::timeout(60)
                ->acceptJson()
                ->withHeader('Authorization', 'AfasToken ' . $token)
                ->get($baseUrl . '/connectors/' . $connector, $query);

            if (!$response->successful()) {
                throw new \RuntimeException(
                    'AFAS request failed: ' . $response->status() . ' ' . $response->body()
                );
            }

            $rows = $response->json('rows');

            if (!is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            if (count($rows) < $pageSize) {
                break;
            }

            $skip += $pageSize;
        }
    }

    private function isCandidate(array $row, CarbonImmutable|CarbonInterface $createdAfter): bool
    {
        $createdAt = $this->value($row, 'created_at_field');

        if ($createdAt === '') {
            return false;
        }

        try {
            $createdAt = CarbonImmutable::parse($createdAt);
        } catch (\Throwable) {
            return false;
        }

        if ($createdAt->lt($createdAfter)) {
            return false;
        }

        if ($this->value($row, 'cms_id_field') !== '') {
            return false;
        }

        if ($this->value($row, 'barcode_field') !== '') {
            return false;
        }

        if ($this->value($row, 'item_code_field') === '') {
            return false;
        }

        if ($this->value($row, 'dimension_1_field') === '') {
            return false;
        }

        return true;
    }

    private function variantKey(array $row): ?string
    {
        $itemCode = $this->value($row, 'item_code_field');
        $dimension1 = $this->value($row, 'dimension_1_field');
        $dimension2 = $this->value($row, 'dimension_2_field');

        if ($itemCode === '' || $dimension1 === '') {
            return null;
        }

        return implode('|', [$itemCode, $dimension1, $dimension2]);
    }

    private function value(array $row, string $configuredFieldKey): string
    {
        $field = config('api.afas.product_variant_sync.' . $configuredFieldKey);

        if (!is_string($field) || $field === '') {
            return '';
        }

        return trim((string)($row[$field] ?? ''));
    }
}
