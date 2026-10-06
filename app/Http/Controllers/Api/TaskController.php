<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskStoreRequest;
use App\Http\Requests\TaskUpdateRequest;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index($groupId)
    {
        return Task::all()->where('group_id', $groupId);
    }

    public function show($groupId, $taskId) {
        $task = Task::where('id', $taskId)
                    ->first();
        if (!$task) {
            return response(['message' => 'task not found'], 404);
        }
        return $task;
    }

    public function destroy($taskId) {
        $task = Task::find($taskId);
        if (!$task) {
            return response(['message' => 'task not found'], 404);
        }
        $task->delete();
        return response(['message' => 'task deleted successfully'], 200);
    }

    public function store(TaskStoreRequest $request)
    {
        $validatedData = $request->validated();
        $task = Task::create($validatedData);
        return $task;
    }

    public function update(TaskUpdateRequest $request, $taskId)
    {
        $validatedData = $request->validated();
        $task = Task::findOrFail($taskId);
        $task->update($validatedData);
        return $task;
    }

    public function showUserTasks($userId)
    {
        return User::with('tasks')->find($userId);
    }

    public function showStatusTasks($statusId)
    {
        return Status::with('tasks')->find($statusId);
    }
}
