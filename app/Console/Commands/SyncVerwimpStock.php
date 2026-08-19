<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use App\Actions\Stock\SyncSupplierStock;
use Illuminate\Console\Command;

#[Signature('stock:sync-verwimp')]
#[Description('Command description')]
class SyncVerwimpStock extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SyncSupplierStock $syncSupplierStock): int
    {
        $count = $syncSupplierStock->handle('verwimp');

        $this->info("Suppliers stock synced. {$count} mutations sent.");

        return self::SUCCESS;
    }
}
