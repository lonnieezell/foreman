<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->unsignedTinyInteger('auto_merge_max_tier')->nullable()->after('watch_prs');   // null = off, 1 or 2
            $table->string('merge_method')->default('merge')->after('auto_merge_max_tier');
        });
    }

    public function down(): void
    {
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn(['auto_merge_max_tier', 'merge_method']));
    }
};
