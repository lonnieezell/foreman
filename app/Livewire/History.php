<?php

namespace App\Livewire;

use App\Models\Repository;
use App\Models\Run;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('History')]
class History extends Component
{
    use WithPagination;

    // Repository id to filter by; kept in the URL so it survives pagination and reloads
    #[Url(as: 'repository', except: null)]
    public ?int $repositoryId = null;

    public function updatedRepositoryId(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        // One row per repository and issue, with totals across all of the issue's runs
        $issues = Run::query()
            ->toBase()
            ->when($this->repositoryId, fn ($q) => $q->where('repository_id', $this->repositoryId))
            ->selectRaw('repository_id, issue_number, max(id) as latest_id, count(*) as run_count, sum(num_turns) as turns, sum(cost_usd) as cost, sum(coalesce(input_tokens, 0) + coalesce(cache_read_tokens, 0) + coalesce(cache_write_tokens, 0)) as tokens_in, sum(coalesce(output_tokens, 0)) as tokens_out')
            ->groupBy('repository_id', 'issue_number')
            ->orderByDesc('latest_id')
            ->paginate(25);

        $latest = Run::with('repository')->findMany($issues->pluck('latest_id'))->keyBy('id');

        return view('livewire.history', [
            'issues' => $issues,
            'latest' => $latest,
            'repositories' => Repository::query()->orderBy('name')->get(),
        ]);
    }
}
