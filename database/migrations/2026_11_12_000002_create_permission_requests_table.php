<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How long a call off the allowlist waits for the operator before it is denied. 0 denies at once (ADR 0010)
        Schema::table('repositories', function (Blueprint $table) {
            $table->unsignedSmallInteger('permission_timeout_minutes')->default(3);
        });

        // A tool call the agent's session asked Foreman to allow, and what the operator said
        Schema::create('permission_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->string('request_id');   // the CLI's, for the answer
            $table->string('tool_name');
            $table->json('input');
            $table->timestamp('expires_at');
            $table->string('decision')->nullable();   // allow, always, deny or timeout
            $table->string('pattern')->nullable();    // the rule an "always" added to the repository's allowlist
            $table->timestamp('sent_at')->nullable(); // when Foreman wrote the answer to the session
            $table->timestamps();
            $table->index(['run_id', 'decision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_requests');
        Schema::table('repositories', fn (Blueprint $table) => $table->dropColumn('permission_timeout_minutes'));
    }
};
