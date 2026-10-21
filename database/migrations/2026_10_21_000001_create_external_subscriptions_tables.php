<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('external_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->text('url');
            $table->boolean('enabled')->default(false)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
        Schema::create('external_subscription_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('external_subscriptions')->cascadeOnDelete();
            $table->string('fingerprint', 64);
            $table->string('name', 255);
            $table->string('type', 32);
            $table->longText('config');
            $table->boolean('selected')->default(false);
            $table->boolean('present')->default(true);
            $table->boolean('supported')->default(false);
            $table->string('error', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['source_id', 'fingerprint']);
            $table->index(['source_id', 'selected', 'present', 'supported'], 'external_nodes_available');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_subscription_nodes');
        Schema::dropIfExists('external_subscriptions');
    }
};
