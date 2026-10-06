<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/** tasks routes */
Route::get('/groups/{groupId}/tasks', [\App\Http\Controllers\Api\TaskController::class, 'index'])->middleware('auth:sanctum');
Route::get('/groups/{groupId}/tasks/{taskId}', [\App\Http\Controllers\Api\TaskController::class, 'show'])->middleware('auth:sanctum');
Route::put('/groups/{groupId}/tasks/{taskId}', [\App\Http\Controllers\Api\TaskController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/groups/{groupId}/tasks/{taskId}', [\App\Http\Controllers\Api\TaskController::class, 'destroy'])->middleware('auth:sanctum');
Route::get('/groups/{groupId}/users/{userId}/tasks', [\App\Http\Controllers\Api\TaskController::class, 'showUserTasks'])->middleware('auth:sanctum');
Route::post('/groups/{groupId}/task', [\App\Http\Controllers\Api\TaskController::class, 'store'])->middleware('auth:sanctum');
Route::get('/groups/{groupId}/statuses/{statusId}/tasks', [\App\Http\Controllers\Api\TaskController::class, 'showStatusTasks'])->middleware('auth:sanctum');

// status routes
Route::post('/groups/{groupId}/status', [\App\Http\Controllers\Api\StatusController::class, 'store'])->middleware('auth:sanctum');
Route::get('/groups/{groupId}/statuses/{statusId}', [\App\Http\Controllers\Api\StatusController::class, 'show'])->middleware('auth:sanctum');
Route::put('/groups/{groupId}/statuses/{statusId}', [\App\Http\Controllers\Api\StatusController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/groups/{groupId}/statuses/{statusId}', [\App\Http\Controllers\Api\StatusController::class, 'destroy'])->middleware('auth:sanctum');
Route::get('/groups/{groupId}/statuses', [\App\Http\Controllers\Api\StatusController::class, 'index'])->middleware('auth:sanctum');

/** groups routes */
Route::post('/group', [\App\Http\Controllers\Api\GroupController::class, 'store'])->middleware('auth:sanctum');
Route::get('/groups/{groupId}', [\App\Http\Controllers\Api\GroupController::class, 'show'])->middleware('auth:sanctum');
Route::put('/groups/{groupId}', [\App\Http\Controllers\Api\GroupController::class, 'update'])->middleware('auth:sanctum');
Route::delete('/groups/{groupId}', [\App\Http\Controllers\Api\GroupController::class, 'destroy'])->middleware('auth:sanctum');
Route::get('/groups', [\App\Http\Controllers\Api\GroupController::class, 'index'])->middleware('auth:sanctum');


// User routes
Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login']);
Route::post('/register', [\App\Http\Controllers\AuthController::class, 'register']);
Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout'])->middleware('auth:sanctum');

//Profile routes
Route::get('/profile', [\App\Http\Controllers\ProfileController::class, 'show'])->middleware('auth:sanctum');

