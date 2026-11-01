<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->string('effort')->nullable()->after('model');   // null = Default: leave out --effort
        });

        Schema::table('runs', function (Blueprint $table) {
            $table->string('effort')->nullable()->after('model');   // copied from the repository when the run is created
        });
    }

    public function down(): void
    {
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn('effort'));
        Schema::table('runs', fn (Blueprint $table) => $table->dropColumn('effort'));
    }
};
