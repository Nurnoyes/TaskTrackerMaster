<?php

use App\Http\Controllers\SpaController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', [SpaController::class, 'index']);

Route::get('/task/{id}', [TaskController::class, 'showTask']);
Route::get('/tasks', [SpaController::class, 'index']);
