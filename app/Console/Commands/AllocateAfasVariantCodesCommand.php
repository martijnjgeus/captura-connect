<?php

namespace App\Console\Commands;

use App\Models\AfasShopifyVariantSync;
use App\Services\CodeAllocator\CodeAllocatorClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class AllocateAfasVariantCodesCommand extends Command
{
    protected $signature = 'products:allocate-afas-variant-codes
        {--limit= : Stop after this many pending variants}
        {--dry-run : Show what would be allocated without calling the allocator}';

    protected $description = 'Allocate EAN and SKU codes for pending AFAS Shopify variant syncs and store them locally only.';

    public function handle(CodeAllocatorClient $client): int
    {
        $syncs = $this->pendingSyncs();

        if ($syncs->isEmpty()) {
            $this->info('No pending variant syncs found.');

            return self::SUCCESS;
        }

        $this->info('Found pending variant syncs: ' . $syncs->count());

        if ($this->option('dry-run')) {
            $this->dryRun($syncs);

            return self::SUCCESS;
        }

        $this->allocateMissingEans($client, $syncs);
        $this->allocateMissingSkus($client, $syncs);

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function pendingSyncs(): Collection
    {
        $query = AfasShopifyVariantSync::query()
            ->where('status', AfasShopifyVariantSync::STATUS_PENDING)
            ->whereNotNull('ean_company')
            ->where(function ($query): void {
                $query
                    ->whereNull('allocated_ean')
                    ->orWhereNull('allocated_sku');
            })
            ->orderBy('afas_variant_key');

        $limit = $this->option('limit');

        if ($limit !== null) {
            $query->limit((int)$limit);
        }

        return $query->get();
    }

    private function dryRun(Collection $syncs): void
    {
        $missingEans = $syncs
            ->filter(fn(AfasShopifyVariantSync $sync): bool => $this->blank($sync->allocated_ean))
            ->groupBy(fn(AfasShopifyVariantSync $sync): string => (string)$sync->ean_company);

        foreach ($missingEans as $company => $companySyncs) {
            $this->line('Would allocate EAN quantity=' . $companySyncs->count() . ' company=' . $company);
        }

        $missingSkus = $syncs
            ->filter(fn(AfasShopifyVariantSync $sync): bool => $this->blank($sync->allocated_sku));

        if ($missingSkus->isNotEmpty()) {
            $this->line('Would allocate SKU quantity=' . $missingSkus->count());
        }

        foreach ($syncs as $sync) {
            $this->line(
                $sync->afas_variant_key
                . ' ean_company=' . $sync->ean_company
                . ' allocated_ean=' . ($sync->allocated_ean ?: 'null')
                . ' allocated_sku=' . ($sync->allocated_sku ?: 'null')
            );
        }
    }

    private function allocateMissingEans(CodeAllocatorClient $client, Collection $syncs): void
    {
        $missingEansByCompany = $syncs
            ->filter(fn(AfasShopifyVariantSync $sync): bool => $this->blank($sync->allocated_ean))
            ->groupBy(fn(AfasShopifyVariantSync $sync): string => trim((string)$sync->ean_company));

        foreach ($missingEansByCompany as $company => $companySyncs) {
            if ($company === '') {
                foreach ($companySyncs as $sync) {
                    $this->markFailed($sync, 'Cannot allocate EAN because ean_company is empty.');
                }

                continue;
            }

            $companySyncs
                ->sortBy('afas_variant_key')
                ->values()
                ->chunk(500)
                ->each(function (Collection $chunk) use ($client, $company): void {
                    $this->line('Allocating EAN quantity=' . $chunk->count() . ' company=' . $company);

                    try {
                        $result = $client->allocateEan($chunk->count(), $company);
                    } catch (Throwable $exception) {
                        foreach ($chunk as $sync) {
                            $this->markAllocationRetryable($sync, $exception->getMessage());
                        }

                        $this->error('EAN allocation failed for company=' . $company . ': ' . $exception->getMessage());

                        return;
                    }

                    $codes = $result['codes'];

                    foreach ($chunk->values() as $index => $sync) {
                        $code = $codes[$index]['code'];
                        $item = $codes[$index]['item'];

                        $allocatorResponse = is_array($sync->allocator_response)
                            ? $sync->allocator_response
                            : [];

                        $allocatorResponse['ean'] = [
                            'payload' => $result['payload'],
                            'item'    => $item,
                        ];

                        $sync->forceFill([
                            'allocated_ean'      => $code,
                            'ean_allocated_at'   => now(),
                            'allocator_response' => $allocatorResponse,
                            'status'             => $this->statusAfterAllocation($sync->allocated_sku, $code),
                            'error_message'      => null,
                        ])->save();

                        $this->line('Allocated EAN ' . $code . ' to ' . $sync->afas_variant_key);
                    }
                });
        }
    }

    private function allocateMissingSkus(CodeAllocatorClient $client, Collection $originalSyncs): void
    {
        $missingSkus = AfasShopifyVariantSync::query()
            ->whereIn('id', $originalSyncs->pluck('id')->all())
            ->where('status', AfasShopifyVariantSync::STATUS_PENDING)
            ->whereNotNull('allocated_ean')
            ->whereNull('allocated_sku')
            ->orderBy('afas_variant_key')
            ->get();

        if ($missingSkus->isEmpty()) {
            return;
        }

        $missingSkus
            ->chunk(500)
            ->each(function (Collection $chunk) use ($client): void {
                $this->line('Allocating SKU quantity=' . $chunk->count());

                try {
                    $result = $client->allocateSku($chunk->count());
                } catch (Throwable $exception) {
                    foreach ($chunk as $sync) {
                        $this->markAllocationRetryable($sync, $exception->getMessage());
                    }

                    $this->error('SKU allocation failed: ' . $exception->getMessage());

                    return;
                }

                $codes = $result['codes'];

                foreach ($chunk->values() as $index => $sync) {
                    $code = $codes[$index]['code'];
                    $item = $codes[$index]['item'];

                    $allocatorResponse = is_array($sync->allocator_response)
                        ? $sync->allocator_response
                        : [];

                    $allocatorResponse['sku'] = [
                        'payload' => $result['payload'],
                        'item'    => $item,
                    ];

                    $sync->forceFill([
                        'allocated_sku'      => $code,
                        'sku_allocated_at'   => now(),
                        'allocator_response' => $allocatorResponse,
                        'status'             => $this->statusAfterAllocation($code, $sync->allocated_ean),
                        'error_message'      => null,
                    ])->save();

                    $this->line('Allocated SKU ' . $code . ' to ' . $sync->afas_variant_key);
                }
            });
    }

    private function statusAfterAllocation(?string $sku, ?string $ean): string
    {
        if (!$this->blank($sku) && !$this->blank($ean)) {
            return AfasShopifyVariantSync::STATUS_ALLOCATED;
        }

        return AfasShopifyVariantSync::STATUS_PENDING;
    }

    private function markAllocationRetryable(AfasShopifyVariantSync $sync, string $message): void
    {
        $sync->forceFill([
            'status'        => AfasShopifyVariantSync::STATUS_PENDING,
            'error_message' => $message,
        ])->save();
    }

    private function markFailed(AfasShopifyVariantSync $sync, string $message): void
    {
        $sync->forceFill([
            'status'        => AfasShopifyVariantSync::STATUS_FAILED,
            'error_message' => $message,
        ])->save();
    }

    private function blank(?string $value): bool
    {
        return trim((string)$value) === '';
    }
}
