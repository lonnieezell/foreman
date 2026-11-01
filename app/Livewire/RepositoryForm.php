<?php

namespace App\Livewire;

use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Throwable;

class RepositoryForm extends Component
{
    public ?Repository $repository = null;

    public string $name = '';
    public string $local_path = '';
    public string $github_repo = '';
    public string $base_branch = 'main';
    public string $ready_label = 'ready-for-agent';
    public string $working_label = 'agent-working';
    public string $human_label = 'needs-human';
    public int $concurrency = 1;
    public int $poll_interval_minutes = 10;
    public string $daily_budget = '';
    public string $model = 'sonnet';
    public string $effort = '';
    public string $claude_profile = '';
    public int $max_turns = 40;
    public int $max_minutes = 45;
    public string $allowed_tools = Repository::DEFAULT_ALLOWED_TOOLS;
    public string $locked_paths = '';
    public string $setup_script = Repository::DEFAULT_SETUP_SCRIPT;
    public string $verify_script = '';
    public string $prompt_template = '';
    public bool $cleanup_worktrees = true;
    public bool $watch_prs = true;

    public function mount(?Repository $repository = null): void
    {
        if ($repository?->exists) {
            $this->repository = $repository;
            $this->fill($repository->only([
                'name', 'local_path', 'github_repo', 'base_branch', 'ready_label', 'working_label',
                'human_label', 'concurrency', 'poll_interval_minutes', 'model', 'max_turns',
                'max_minutes', 'allowed_tools', 'setup_script', 'cleanup_worktrees', 'watch_prs',
            ]));
            $this->daily_budget = (string) ($repository->daily_budget ?? '');
            $this->effort = (string) $repository->effort;
            $this->locked_paths = (string) $repository->locked_paths;
            $this->verify_script = (string) $repository->verify_script ?: Repository::defaultVerifyScript($repository->local_path);
            $this->claude_profile = (string) $repository->claude_profile;
            $this->prompt_template = (string) $repository->prompt_template;
        }

        // With profiles defined there's no default option, so start on the first one
        $this->claude_profile = $this->claude_profile ?: (string) array_key_first(config('foreman.claude_profiles'));

        if ($this->prompt_template === '') {
            $this->prompt_template = file_get_contents(resource_path('prompts/default.md'));
        }
    }

    /**
     * Opens the OS's folder dialog on this machine. Browser pickers can't reveal an absolute path,
     * but Foreman runs locally, so the server can ask instead.
     */
    public function browse(GitHub $github): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => ['osascript', '-e', 'POSIX path of (choose folder with prompt "Choose the local checkout")'],
            'Windows' => ['powershell', '-NoProfile', '-Command', 'Add-Type -AssemblyName System.Windows.Forms; $d = New-Object System.Windows.Forms.FolderBrowserDialog; if ($d.ShowDialog() -eq "OK") { $d.SelectedPath } else { exit 1 }'],
            default => ['zenity', '--file-selection', '--directory', '--title=Choose the local checkout'],
        };

        // Waits for as long as the dialog stays open; a non-zero exit means it was cancelled
        $result = Process::timeout(600)->run($command);
        $path = trim($result->output());

        if ($result->successful() && $path !== '') {
            $this->local_path = $path;
            $this->detect($github);
        }
    }

    public function detect(GitHub $github): void
    {
        $this->resetErrorBag('local_path');
        $path = rtrim($this->expandedPath(), '/');

        if (! File::isDirectory("{$path}/.git") && ! File::exists("{$path}/.git")) {
            $this->addError('local_path', 'That folder isn’t a git checkout.');

            return;
        }

        $this->local_path = $path;
        $detected = $github->detectRepo($path);

        if (! $detected) {
            $this->addError('local_path', 'Couldn’t read the GitHub remote. Check that `gh repo view` works in that folder.');

            return;
        }

        $this->github_repo = $detected;
        $this->verify_script = $this->verify_script ?: Repository::defaultVerifyScript($path);
        $this->name = $this->name ?: str($detected)->afterLast('/')->toString();
    }

    public function save(GitHub $github)
    {
        $this->local_path = rtrim($this->expandedPath(), '/');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'local_path' => ['required', 'string', function ($attr, $value, $fail) {
                if (! File::exists("{$value}/.git")) {
                    $fail('That folder isn’t a git checkout.');
                }
            }],
            'github_repo' => ['required', 'regex:/^[\w.-]+\/[\w.-]+$/'],
            'base_branch' => ['required', 'string'],
            'ready_label' => ['required', 'string'],
            'working_label' => ['required', 'string', 'different:ready_label'],
            'human_label' => ['required', 'string', 'different:ready_label'],
            'concurrency' => ['required', 'integer', 'between:1,8'],
            'poll_interval_minutes' => ['required', 'integer', 'between:1,1440'],
            'daily_budget' => ['nullable', 'numeric', 'min:0'],
            'model' => ['required', Rule::in(array_keys(Repository::MODELS))],
            'effort' => ['nullable', Rule::in(array_keys(Repository::EFFORTS))],
            'claude_profile' => [config('foreman.claude_profiles') ? 'required' : 'nullable', Rule::in(array_keys(config('foreman.claude_profiles')))],
            'max_turns' => ['required', 'integer', 'between:1,500'],
            'max_minutes' => ['required', 'integer', 'between:1,600'],
            'allowed_tools' => ['required', 'string'],
            'locked_paths' => ['nullable', 'string'],
            'setup_script' => ['required', 'string'],
            'verify_script' => ['nullable', 'string'],
            'prompt_template' => ['required', 'string'],
            'cleanup_worktrees' => ['boolean'],
            'watch_prs' => ['boolean'],
        ], [
            'github_repo.regex' => 'Use the owner/name form, like vault-innovation/vault-app.',
        ]);

        $data['daily_budget'] = $data['daily_budget'] === '' ? null : $data['daily_budget'];
        $data['claude_profile'] = $data['claude_profile'] ?: null;
        $data['effort'] = $data['effort'] ?: null;
        $data['locked_paths'] = trim((string) $data['locked_paths']) ?: null;
        $data['verify_script'] = trim((string) $data['verify_script']) ?: null;

        // Store null when the prompt is unchanged, so improvements to the default reach this repository
        $default = file_get_contents(resource_path('prompts/default.md'));
        $data['prompt_template'] = trim($data['prompt_template']) === trim($default) ? null : $data['prompt_template'];

        $repository = $this->repository ?? new Repository;
        $repository->fill($data)->save();

        try {
            $github->ensureLabels($repository);
        } catch (Throwable $e) {
            session()->flash('label_warning', 'Saved, but the labels couldn’t be created on GitHub: '.trim($e->getMessage()));

            return $this->redirectRoute('repositories.edit', $repository, navigate: true);
        }

        return $this->redirectRoute('dashboard', navigate: true);
    }

    public function delete()
    {
        $this->repository?->delete();

        return $this->redirectRoute('dashboard', navigate: true);
    }

    public function archive()
    {
        if (! $this->repository || $this->repository->hasWorkInFlight()) {
            return;
        }

        // Stopped as well, so it can't be polled and comes back stopped when restored
        $this->repository->update(['archived_at' => now(), 'enabled' => false]);

        return $this->redirectRoute('dashboard', navigate: true);
    }

    public function resetPrompt(): void
    {
        $this->prompt_template = file_get_contents(resource_path('prompts/default.md'));
    }

    /** For pasting into Claude Code in the project; built from the form so unsaved edits count. */
    public function setupScriptPrompt(): string
    {
        return strtr(file_get_contents(resource_path('prompts/setup-script.md')), [
            '{{base}}' => $this->base_branch,
            '{{allowed_tools}}' => $this->allowed_tools,
            '{{setup_script}}' => $this->setup_script,
        ]);
    }

    /** For pasting into Claude Code in the project; built from the form so unsaved edits count. */
    public function verifyScriptPrompt(): string
    {
        return strtr(file_get_contents(resource_path('prompts/verify-script.md')), [
            '{{base}}' => $this->base_branch,
            '{{verify_script}}' => $this->verify_script,
        ]);
    }

    private function expandedPath(): string
    {
        return str_starts_with($this->local_path, '~/')
            ? rtrim((string) getenv('HOME'), '/').substr($this->local_path, 1)
            : $this->local_path;
    }

    public function render()
    {
        return view('livewire.repository-form')
            ->title($this->repository ? "{$this->repository->name} settings" : 'Add repository');
    }
}
