<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);

Route::get('/task/{id}', [TaskController::class, 'showTask']);
Route::get('/tasks', [TaskController::class, 'tasks']);
