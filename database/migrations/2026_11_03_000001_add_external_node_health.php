<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('external_subscription_nodes', function (Blueprint $table) {
            $table->string('health_status', 12)->default('unknown');
            $table->unsignedBigInteger('health_checked_at')->nullable();
            $table->unsignedBigInteger('health_due_at')->nullable()->index();
            $table->unsignedBigInteger('health_revision')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('external_subscription_nodes', function (Blueprint $table) {
            $table->dropIndex(['health_due_at']);
            $table->dropColumn(['health_status', 'health_checked_at', 'health_due_at', 'health_revision']);
        });
    }
};
