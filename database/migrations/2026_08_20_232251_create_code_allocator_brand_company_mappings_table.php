<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_allocator_brand_company_mappings', function (Blueprint $table): void {
            $table->id();

            $table->string('afas_brand_code')->unique();
            $table->string('afas_brand_name')->nullable();
            $table->string('ean_company');

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index('ean_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_allocator_brand_company_mappings');
    }
};
