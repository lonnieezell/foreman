<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One run's measured facts for the improve loop (ADR 0008). The judge's observations get their own columns later.
        Schema::create('scorecards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('log_problem')->nullable();   // a missing or empty log, so its facts read as zero
            $table->json('tool_errors');                 // tool name => failed calls
            $table->json('repeated_reads');              // path => reads, for files read 3 or more times
            $table->unsignedInteger('full_test_runs');
            $table->unsignedInteger('filtered_test_runs');
            $table->unsignedInteger('stop_hook_blocks');
            $table->unsignedInteger('verify_failures');
            $table->unsignedInteger('scope_vetoes');
            $table->unsignedInteger('test_methods_added');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scorecards');
    }
};
