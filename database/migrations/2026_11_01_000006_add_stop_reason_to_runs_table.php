<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->string('stop_reason')->nullable()->after('error');   // why a run was handed to a human: rounds_ran_out, someone_pushed, agent_asked, already_done, gate_failed, scope_vetoed, scope_unusable
        });
    }

    public function down(): void
    {
        Schema::table('runs', fn (Blueprint $table) => $table->dropColumn('stop_reason'));
    }
};
