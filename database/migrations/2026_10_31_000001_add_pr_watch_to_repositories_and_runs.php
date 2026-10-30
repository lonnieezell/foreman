<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->boolean('watch_prs')->default(true)->after('cleanup_worktrees');
        });

        Schema::table('runs', function (Blueprint $table) {
            $table->foreignId('previous_run_id')->nullable()->after('resume_session_id')->constrained('runs')->nullOnDelete();
            $table->unsignedTinyInteger('pr_round')->default(0)->after('pr_url');   // 0 = the run that opened the PR
            $table->string('head_sha')->nullable()->after('pr_round');              // PR head this run pushed / is watching
            $table->json('pr_feedback')->nullable()->after('head_sha');             // failing checks + bot threads a round was given
            $table->timestamp('watch_started_at')->nullable()->after('heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_run_id');
            $table->dropColumn(['pr_round', 'head_sha', 'pr_feedback', 'watch_started_at']);
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn('watch_prs');
        });
    }
};
