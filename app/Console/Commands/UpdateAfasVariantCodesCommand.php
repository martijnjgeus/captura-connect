<?php

namespace App\Console\Commands;

use App\Models\AfasShopifyVariantSync;
use App\Services\Afas\AfasProductVariantClient;
use Illuminate\Console\Command;
use Throwable;

class UpdateAfasVariantCodesCommand extends Command
{
    protected $signature = 'products:update-afas-variant-codes
        {--limit= : Stop after this many allocated variants}
        {--dry-run : Show what would be updated without writing to AFAS}';

    protected $description = 'Write allocated EAN and SKU codes back to AFAS product variants via FbUpdateAdB.';

    public function handle(AfasProductVariantClient $client): int
    {
        $syncs = $this->allocatedSyncs();

        if ($syncs->isEmpty()) {
            $this->info('No allocated variant syncs found.');

            return self::SUCCESS;
        }

        $this->info('Found allocated variant syncs: ' . $syncs->count());

        foreach ($syncs as $sync) {
            if ($this->option('dry-run')) {
                $this->line($this->dryRunLine($sync));
                continue;
            }

            try {
                $result = $client->updateVariantCodes(
                    itemCode: (string)$sync->afas_item_code,
                    dimension1: (string)$sync->afas_dimension_1,
                    dimension2: $sync->afas_dimension_2,
                    cmsId: (string)$sync->allocated_sku,
                    ean: (string)$sync->allocated_ean,
                );

                $sync->forceFill([
                    'status'               => AfasShopifyVariantSync::STATUS_UPDATED_IN_AFAS,
                    'error_message'        => null,
                    'afas_update_response' => $result,
                    'updated_in_afas_at'   => now(),
                ])->save();

                $this->line('Updated AFAS variant: ' . $sync->afas_variant_key);
            } catch (Throwable $exception) {
                $sync->forceFill([
                    'status'        => AfasShopifyVariantSync::STATUS_ALLOCATED,
                    'error_message' => $exception->getMessage(),
                ])->save();

                $this->error('AFAS update failed for ' . $sync->afas_variant_key . ': ' . $exception->getMessage());
            }
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function allocatedSyncs()
    {
        $query = AfasShopifyVariantSync::query()
            ->where('status', AfasShopifyVariantSync::STATUS_ALLOCATED)
            ->whereNotNull('allocated_ean')
            ->whereNotNull('allocated_sku')
            ->whereNull('updated_in_afas_at')
            ->orderBy('afas_variant_key');

        $limit = $this->option('limit');

        if ($limit !== null) {
            $query->limit((int)$limit);
        }

        return $query->get();
    }

    private function dryRunLine(AfasShopifyVariantSync $sync): string
    {
        return implode(' ', [
            'Would update AFAS:',
            $sync->afas_variant_key,
            'ItCd=' . $sync->afas_item_code,
            'StL1=' . $sync->afas_dimension_1,
            'StL2=' . ($sync->afas_dimension_2 ?: 'null'),
            'CMS_ID=' . $sync->allocated_sku,
            'EAN=' . $sync->allocated_ean,
        ]);
    }
}
