<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();

            $table->string('type');   // webhook, feed
            $table->string('source'); // incoming_order, verwimp, hoop_fietsen
            $table->string('status')->default('started');

            $table->json('received_body')->nullable();
            $table->json('validation_result')->nullable();
            $table->json('sent_body')->nullable();
            $table->json('result_body')->nullable();

            $table->unsignedSmallInteger('http_status')->nullable();

            $table->unsignedInteger('products_read')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('updates_failed')->default(0);

            $table->text('error_message')->nullable();

            $table->timestamps();

            $table->index(['type', 'source']);
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_logs');
    }
};
