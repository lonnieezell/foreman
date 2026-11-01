<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->text('verify_script')->nullable()->after('setup_script');   // empty means "the detected stacks' checks"
        });
    }

    public function down(): void
    {
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn('verify_script'));
    }
};
