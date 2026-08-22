<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('afas_shopify_variant_syncs', function (Blueprint $table): void {
            $table->id();

            $table->string('afas_variant_key')->unique();

            $table->string('afas_item_code')->index();
            $table->string('afas_dimension_1')->nullable();
            $table->string('afas_dimension_2')->nullable();

            $table->string('ean_company')->nullable();
            $table->timestamp('ean_company_resolved_at')->nullable();

            $table->string('allocated_ean')->nullable();
            $table->string('allocated_sku')->nullable();
            $table->timestamp('ean_allocated_at')->nullable();
            $table->timestamp('sku_allocated_at')->nullable();

            $table->string('shopify_product_id')->nullable();
            $table->string('shopify_variant_id')->nullable();

            $table->string('status')->index();
            $table->text('error_message')->nullable();

            $table->json('afas_payload')->nullable();
            $table->json('allocator_response')->nullable();
            $table->json('afas_update_response')->nullable();
            $table->json('shopify_response')->nullable();

            $table->timestamp('updated_in_afas_at')->nullable();
            $table->timestamp('synced_to_shopify_at')->nullable();

            $table->timestamps();

            $table->index(['afas_item_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('afas_shopify_variant_syncs');
    }
};
