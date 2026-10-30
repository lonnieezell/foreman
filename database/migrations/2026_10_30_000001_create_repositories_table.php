<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('local_path');
            $table->string('github_repo');               // owner/name
            $table->string('base_branch')->default('main');

            $table->string('ready_label')->default('ready-for-agent');
            $table->string('working_label')->default('agent-working');
            $table->string('human_label')->default('needs-human');

            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('concurrency')->default(1);
            $table->unsignedSmallInteger('poll_interval_minutes')->default(10);
            $table->decimal('daily_budget', 8, 2)->nullable();

            $table->string('model')->default('sonnet');
            $table->unsignedSmallInteger('max_turns')->default(40);
            $table->unsignedSmallInteger('max_minutes')->default(45);
            $table->text('allowed_tools');
            $table->text('setup_script');
            $table->text('prompt_template')->nullable();  // null = resources/prompts/default.md
            $table->boolean('cleanup_worktrees')->default(true);

            $table->timestamp('last_polled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
