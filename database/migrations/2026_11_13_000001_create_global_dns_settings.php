<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('global_dns_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('dns_provider', 64)->default('cloudflare');
            $table->string('email', 254)->default('');
            $table->longText('dns_env')->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        // No credentials or existing node records are changed during an upgrade.
        DB::table('global_dns_settings')->insert([
            'id' => 1, 'dns_provider' => 'cloudflare', 'email' => '',
            'dns_env' => null, 'revision' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('global_dns_settings');
    }
};
