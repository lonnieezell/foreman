<?php

namespace App\Guardrails;

use Symfony\Component\Yaml\Yaml;

/** The GitHub Actions workflow that carries Foreman's guardrails into a target repo. */
class CiKit
{
    public const WORKFLOW = '.github/workflows/foreman-ci.yml';

    /** Where the copy of the test-integrity script goes in the target repo. */
    public const SCRIPT = '.github/foreman/test-integrity.php';

    /** The integrity script needs PHP 8.1 or newer. Pinned so a runner image update can't change it. */
    public const PHP_VERSION = '8.3';

    private const HEADER = <<<'YAML'
# Written by Foreman. Review it before you push it.
#
# One job per stack, with the cheap checks first so a failure shows up early.
# Make each job a required status check in branch protection, or nothing stops a merge.
#
# CodeQL runs on public repos. On a private repo it needs GitHub Advanced Security, so Semgrep runs there instead.
# If your private repo has Advanced Security, delete the `if` lines on the CodeQL and Semgrep steps.

YAML;

    public static function yaml(StackSet $stacks): string
    {
        return self::HEADER.Yaml::dump(self::workflow($stacks), 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    public static function workflow(StackSet $stacks): array
    {
        $jobs = [];

        foreach ($stacks->stacks as $stack) {
            $jobs[$stack->name()] = self::job($stack);
        }

        return [
            'name' => 'Foreman CI',
            'on' => ['pull_request' => null],
            'permissions' => ['contents' => 'read'],
            'jobs' => $jobs,
        ];
    }

    private static function job(Stack $stack): array
    {
        $groups = $stack->ciSteps();
        $dir = $stack->ciDir();

        $steps = [
            ['name' => 'Check out', 'uses' => 'actions/checkout@v4', 'with' => ['fetch-depth' => 0]],
            ['name' => 'Set up PHP', 'uses' => 'shivammathur/setup-php@v2', 'with' => ['php-version' => self::PHP_VERSION, 'tools' => $stack->name() === 'php' ? 'composer' : 'none']],
            ...$groups['setup'],
            ...$groups['lint'],
            [
                'name' => 'Test integrity',
                'working-directory' => '${{ github.workspace }}',
                // The base branch's copy, so a PR can't weaken the check that judges it. The PR that adds the script has no base copy yet.
                'run' => 'git show "origin/${{ github.base_ref }}:'.self::SCRIPT.'" > "$RUNNER_TEMP/test-integrity.php" || cp '.self::SCRIPT." \"\$RUNNER_TEMP/test-integrity.php\"\n"
                    .'php "$RUNNER_TEMP/test-integrity.php" --base=origin/${{ github.base_ref }}',
            ],
            ...$groups['test'],
            ...$groups['audit'],
        ];

        $job = ['name' => $stack->name(), 'runs-on' => 'ubuntu-latest'];

        if (array_filter($steps, fn (array $step) => str_starts_with($step['uses'] ?? '', 'github/codeql-action'))) {
            $job['permissions'] = ['contents' => 'read', 'security-events' => 'write'];
        }
        if ($dir !== '') {
            $job['defaults'] = ['run' => ['working-directory' => $dir]];
        }

        return $job + ['steps' => $steps];
    }
}
