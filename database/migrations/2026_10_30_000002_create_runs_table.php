<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('issue_number');
            $table->string('issue_title')->nullable();
            $table->string('issue_url')->nullable();
            $table->string('branch');
            $table->string('status')->index();
            $table->string('model');
            $table->string('worktree_path')->nullable();
            $table->string('session_id')->nullable();        // Claude session, so a run can be resumed
            $table->string('resume_session_id')->nullable(); // set when this run continues an earlier one
            $table->unsignedInteger('pid')->nullable();
            $table->string('pr_url')->nullable();
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->unsignedSmallInteger('num_turns')->nullable();
            $table->text('error')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamps();
        });

        Schema::create('factory_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runs');
        Schema::dropIfExists('factory_settings');
    }
};
