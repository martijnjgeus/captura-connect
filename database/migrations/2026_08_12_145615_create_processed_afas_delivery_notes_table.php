<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_afas_delivery_notes', function (Blueprint $table): void {
            $table->id();

            $table->string('afas_delivery_note_number')->unique();
            $table->string('goedgepickt_order_uuid')->nullable();

            $table->string('status')->default('pending')->index();

            $table->json('goedgepickt_response')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('posted_to_goedgepickt_at')->nullable();
            $table->timestamp('marked_processed_in_afas_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_afas_delivery_notes');
    }
};
