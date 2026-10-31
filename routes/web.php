<?php

use App\Livewire\Archive;
use App\Livewire\Dashboard;
use App\Livewire\Docs;
use App\Livewire\History;
use App\Livewire\RepositoryForm;
use App\Livewire\RunDetail;
use App\Livewire\Settings;
use Illuminate\Support\Facades\Route;

Route::get('/', Dashboard::class)->name('dashboard');
Route::get('/history', History::class)->name('history');
Route::get('/archive', Archive::class)->name('archive');
Route::get('/settings', Settings::class)->name('settings');
Route::redirect('/docs', '/docs/getting-started');
Route::get('/docs/{page}', Docs::class)->name('docs');
Route::get('/repositories/{repository}', RepositoryForm::class)->name('repositories.edit');
Route::get('/runs/{run}', RunDetail::class)->name('runs.show');
