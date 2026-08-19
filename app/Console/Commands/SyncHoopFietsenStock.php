<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Actions\Stock\SyncSupplierStock;


#[Signature('stock:sync-hoop-fietsen')]
#[Description('Command description')]
class SyncHoopFietsenStock extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SyncSupplierStock $syncSupplierStock): int
    {
        $count = $syncSupplierStock->handle('hoop_fietsen');

        $this->info("Hoop Fietsen stock synced. {$count} mutations sent.");

        return self::SUCCESS;
    }
}
