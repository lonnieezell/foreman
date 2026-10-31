<?php

use App\Livewire\Archive;
use App\Livewire\Dashboard;
use App\Livewire\RepositoryForm;
use App\Livewire\RunDetail;
use Illuminate\Support\Facades\Route;

Route::get('/', Dashboard::class)->name('dashboard');
Route::get('/archive', Archive::class)->name('archive');
Route::get('/repositories/{repository}', RepositoryForm::class)->name('repositories.edit');
Route::get('/runs/{run}', RunDetail::class)->name('runs.show');
