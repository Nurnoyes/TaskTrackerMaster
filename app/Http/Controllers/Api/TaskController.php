<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskStoreRequest;
use App\Http\Requests\TaskUpdateRequest;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Models\Group;
use App\Services\GroupAccess;
use App\Services\TaskWorkflowService;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private readonly TaskWorkflowService $workflow) {}

    public function index($groupId)
    {
        $this->workflow->markExpired((int) $groupId);

        return Task::all()->where('group_id', $groupId)->values()->load(['status', 'manager:id,name']);
    }

    public function show($groupId, $taskId) {
        $task = Task::where('id', $taskId)
                    ->first();
        if (!$task) {
            return response(['message' => 'task not found'], 404);
        }
        $this->workflow->markExpired($task->group_id);

        return $task->refresh()->load(['status', 'manager:id,name']);
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
        $group = Group::findOrFail($validatedData['group_id']);

        return $this->workflow->create($group, $validatedData);
    }

    public function update(TaskUpdateRequest $request, $groupId, $taskId)
    {
        $validatedData = $request->validated();
        $task = Task::findOrFail($taskId);
        $group = Group::findOrFail($task->group_id);

        return $this->workflow->update($group, $taskId, $validatedData);
    }

    public function showUserTasks($userId)
    {
        return User::with('tasks')->find($userId);
    }

    public function showStatusTasks($statusId)
    {
        return Status::with('tasks')->find($statusId);
    }

    public function history(Request $request, GroupAccess $access, $groupId, $taskId)
    {
        $group = $access->find($request, $groupId);
        $this->workflow->markExpired($group->id);

        return $group->tasks()->findOrFail($taskId)->history()->get();
    }
}
