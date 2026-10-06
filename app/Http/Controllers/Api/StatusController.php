<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GroupStoreRequest;
use App\Http\Requests\GroupUpdateRequest;
use App\Http\Requests\StatusStoreRequest;
use App\Http\Requests\StatusUpdateRequest;
use App\Models\Group;
use App\Models\Status;
use Illuminate\Http\Request;

class StatusController extends Controller
{
    public function index($groupId)
    {
        return Status::all()->where('group_id', $groupId);
    }

    public function show($groupId, $statusId) {
        $status = Status::all()->where('id', $statusId)
            ->first();
        if (!$status) {
            return response(['message' => 'status not found'], 404);
        }
        return $status;
    }

    public function destroy($statusId) {
        $status = Status::find($statusId);
        if (!$status) {
            return response(['message' => 'task not found'], 404);
        }
        $status->delete();
        return response(['message' => 'task deleted successfully'], 200);
    }

    public function store(StatusStoreRequest $request)
    {
        $validatedData = $request->validated();
        $status = Status::create($validatedData);
        return $status;
    }

    public function update(StatusUpdateRequest $request, $groupid, $statusId)
    {
        $validatedData = $request->validated();
        $status = Status::findOrFail($statusId);
        $status->update($validatedData);
        return $status;
    }
}
