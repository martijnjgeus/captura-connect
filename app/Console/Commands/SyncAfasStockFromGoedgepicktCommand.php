<?php

namespace App\Console\Commands;

use App\Actions\Stock\SyncAfasStockFromGoedgepickt;
use Illuminate\Console\Command;

class SyncAfasStockFromGoedgepicktCommand extends Command
{
    protected $signature = 'stock:sync-afas-from-goedgepickt {--dry-run : Do not send stock to AFAS}';

    protected $description = 'Sync stock from GoedGepickt to AFAS.';

    public function handle(SyncAfasStockFromGoedgepickt $sync): int
    {
        $result = $sync->handle(
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->info('GoedGepickt to AFAS stock sync finished.');
        $this->line('Products read: '.$result['goedgepickt_products_read']);
        $this->line('Products skipped: '.$result['products_skipped']);
        $this->line('Lines built: '.$result['lines_built']);
        $this->line('Conflicts: '.$result['conflicts']);
        $this->line('AFAS would attempt: '.$result['lines_built']);
        $this->line('AFAS attempted: '.($result['afas_attempted'] ?? 0));
        $this->line('AFAS succeeded: '.($result['afas_succeeded'] ?? 0));
        $this->line('AFAS failed: '.($result['afas_failed'] ?? 0));

        return self::SUCCESS;
    }
}
