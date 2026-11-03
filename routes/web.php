<?php

use App\Livewire\Archive;
use App\Livewire\Dashboard;
use App\Livewire\Docs;
use App\Livewire\History;
use App\Livewire\RepositoryForm;
use App\Livewire\RunDetail;
use App\Livewire\Settings;
use App\Models\Run;
use Illuminate\Support\Facades\Route;

Route::get('/', Dashboard::class)->name('dashboard');
Route::get('/history', History::class)->name('history');
Route::get('/archive', Archive::class)->name('archive');
Route::get('/settings', Settings::class)->name('settings');
Route::redirect('/docs', '/docs/getting-started');
Route::get('/docs/{page}', Docs::class)->name('docs');
Route::get('/repositories/{repository}', RepositoryForm::class)->name('repositories.edit');
Route::get('/runs/{run}', RunDetail::class)->name('runs.show');

// The browser tab icon's status dot, polled from app.js on every page
Route::get('/status/tab-dot', function () {
    $dots = Run::onDashboard()->distinct()->pluck('status')->map->dot();

    // The worst dot wins
    return ['dot' => collect(['bad', 'attention', 'good', 'busy'])->first(fn ($dot) => $dots->contains($dot))];
})->name('tab-dot');
