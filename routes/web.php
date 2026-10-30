<?php

use App\Livewire\Dashboard;
use App\Livewire\RepositoryForm;
use App\Livewire\RunDetail;
use Illuminate\Support\Facades\Route;

Route::get('/', Dashboard::class)->name('dashboard');
Route::get('/repositories/new', RepositoryForm::class)->name('repositories.create');
Route::get('/repositories/{repository}', RepositoryForm::class)->name('repositories.edit');
Route::get('/runs/{run}', RunDetail::class)->name('runs.show');
