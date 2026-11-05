<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('external_subscription_nodes', function (Blueprint $table) {
            $table->unsignedInteger('health_latency_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_subscription_nodes', function (Blueprint $table) {
            $table->dropColumn('health_latency_ms');
        });
    }
};
