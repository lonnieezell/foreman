<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->string('kind')->nullable()->after('pr_round');   // null for an ordinary run or round; conflict_fix for a Fix conflicts run
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
