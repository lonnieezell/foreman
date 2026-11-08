<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            // auto: Foreman picks a step from the ladder per issue; fixed: every run uses model and effort
            $table->string('model_mode')->default('fixed')->after('effort');
            $table->string('difficulty_floor')->default('trivial')->after('model_mode');
            $table->string('difficulty_ceiling')->default('hard')->after('difficulty_floor');
        });

        // Existing repositories switch to Auto over the full range (ADR 0004)
        DB::table('repositories')->update(['model_mode' => 'auto']);

        Schema::table('runs', function (Blueprint $table) {
            $table->string('difficulty')->nullable()->after('effort');          // null in Fixed mode, or for a fallback inside the range
            $table->string('difficulty_source')->nullable()->after('difficulty'); // classifier, label or fallback; null in Fixed mode
        });
    }

    public function down(): void
    {
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn(['model_mode', 'difficulty_floor', 'difficulty_ceiling']));
        Schema::table('runs', fn (Blueprint $table) => $table->dropColumn(['difficulty', 'difficulty_source']));
    }
};
