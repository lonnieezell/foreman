<?php

namespace App\Livewire;

use App\Models\Repository;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Archive')]
class Archive extends Component
{
    public function restore(int $id): void
    {
        // Already stopped when archived, so it comes back stopped
        Repository::findOrFail($id)->update(['archived_at' => null]);
    }

    public function remove(int $id): void
    {
        Repository::findOrFail($id)->delete();
    }

    public function render()
    {
        return view('livewire.archive', [
            'repositories' => Repository::query()->whereNotNull('archived_at')->orderBy('name')->get(),
        ]);
    }
}
