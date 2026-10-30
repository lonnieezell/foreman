<?php

namespace App\Livewire;

use App\Models\Repository;
use App\Services\GitHub;
use Illuminate\Support\Facades\File;
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
    public string $claude_profile = '';
    public int $max_turns = 40;
    public int $max_minutes = 45;
    public string $allowed_tools = Repository::DEFAULT_ALLOWED_TOOLS;
    public string $setup_script = Repository::DEFAULT_SETUP_SCRIPT;
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
            $this->claude_profile = (string) $repository->claude_profile;
            $this->prompt_template = (string) $repository->prompt_template;
        }

        // With profiles defined there's no default option, so start on the first one
        $this->claude_profile = $this->claude_profile ?: (string) array_key_first(config('factory.claude_profiles'));

        if ($this->prompt_template === '') {
            $this->prompt_template = file_get_contents(resource_path('prompts/default.md'));
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
            'model' => ['required', 'string'],
            'claude_profile' => [config('factory.claude_profiles') ? 'required' : 'nullable', Rule::in(array_keys(config('factory.claude_profiles')))],
            'max_turns' => ['required', 'integer', 'between:1,500'],
            'max_minutes' => ['required', 'integer', 'between:1,600'],
            'allowed_tools' => ['required', 'string'],
            'setup_script' => ['required', 'string'],
            'prompt_template' => ['required', 'string'],
            'cleanup_worktrees' => ['boolean'],
            'watch_prs' => ['boolean'],
        ], [
            'github_repo.regex' => 'Use the owner/name form, like vault-innovation/vault-app.',
        ]);

        $data['daily_budget'] = $data['daily_budget'] === '' ? null : $data['daily_budget'];
        $data['claude_profile'] = $data['claude_profile'] ?: null;

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

    public function resetPrompt(): void
    {
        $this->prompt_template = file_get_contents(resource_path('prompts/default.md'));
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
