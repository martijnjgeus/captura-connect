<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('afas_order_item_sku_overrides', function (Blueprint $table): void {
            $table->id();

            $table->string('afas_item_code')->unique();
            $table->string('sku');
            $table->string('ean')->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('afas_order_item_sku_overrides');
    }
};
