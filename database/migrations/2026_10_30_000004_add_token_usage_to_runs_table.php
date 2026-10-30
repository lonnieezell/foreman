<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            // As Claude Code reports them in the final result event; null for runs before this was tracked
            $table->unsignedBigInteger('input_tokens')->nullable()->after('cost_usd');
            $table->unsignedBigInteger('output_tokens')->nullable()->after('input_tokens');
            $table->unsignedBigInteger('cache_read_tokens')->nullable()->after('output_tokens');
            $table->unsignedBigInteger('cache_write_tokens')->nullable()->after('cache_read_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropColumn(['input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens']);
        });
    }
};
