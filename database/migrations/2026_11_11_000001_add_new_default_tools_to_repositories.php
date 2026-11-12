<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Repository::DEFAULT_ALLOWED_TOOLS as of this migration, frozen so a later default doesn't change what it did. */
    private const DEFAULT_ALLOWED_TOOLS = 'Read,Edit,Write,Glob,Grep,Skill,Agent,Bash(git status:*),Bash(git diff:*),Bash(git log:*),Bash(git show:*),Bash(git add:*),Bash(git commit:*),Bash(git checkout:*),Bash(git restore:*),Bash(git rm:*),Bash(git mv:*),Bash(git stash:*),Bash(git clean:*),Bash(git fetch:*),Bash(git check-ignore:*),Bash(git grep:*),Bash(cp:*),Bash(mv:*),Bash(mkdir:*),Bash(rm:*),Bash(sed:*),Bash(grep:*),Bash(ls:*),Bash(cat:*),Bash(head:*),Bash(tail:*),Bash(wc:*),Bash(find:*),Bash(echo:*),Bash(composer:*),Bash(php:*),Bash(./vendor/bin/*),Bash(vendor/bin/*),Bash(npm:*),Bash(npx:*),Bash(pnpm:*),Bash(yarn:*),Bash(cargo:*),Bash(rustfmt:*),Bash(python:*),Bash(python3:*),Bash(pytest:*),Bash(ruff:*),Bash(mypy:*),Bash(pyright:*),Bash(uv:*),Bash(poetry:*)';

    /** Saved lists don't follow the default, so each one gains the default entries it's missing and keeps its own (#177). */
    public function up(): void
    {
        $defaults = explode(',', self::DEFAULT_ALLOWED_TOOLS);

        foreach (DB::table('repositories')->get(['id', 'allowed_tools']) as $repository) {
            $saved = array_filter(array_map('trim', explode(',', (string) $repository->allowed_tools)));
            $missing = array_diff($defaults, $saved);

            if ($missing !== []) {
                DB::table('repositories')->where('id', $repository->id)->update(['allowed_tools' => implode(',', [...$saved, ...$missing])]);
            }
        }
    }

    /** Nothing to undo: the added entries can't be told apart from ones the operator added since. */
    public function down(): void {}
};
