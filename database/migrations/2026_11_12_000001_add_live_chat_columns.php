<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How long a live session stays open for the operator's answer to a question. 0 ends it at once (ADR 0009)
        Schema::table('repositories', function (Blueprint $table) {
            $table->unsignedSmallInteger('question_window_minutes')->default(10);
        });

        // Set while the agent waits in its session for an answer
        Schema::table('runs', function (Blueprint $table) {
            $table->timestamp('question_until')->nullable();
        });

        // When Foreman sent an operator's message into the live session. Null: it waits for Continue or Start over
        Schema::table('run_messages', function (Blueprint $table) {
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('run_messages', fn (Blueprint $table) => $table->dropColumn('sent_at'));
        Schema::table('runs', fn (Blueprint $table) => $table->dropColumn('question_until'));
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn('question_window_minutes'));
    }
};
