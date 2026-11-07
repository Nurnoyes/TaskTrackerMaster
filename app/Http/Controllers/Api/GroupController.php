<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GroupStoreRequest;
use App\Http\Requests\GroupUpdateRequest;
use App\Models\Group;
use App\Models\Status;
use App\Models\Task;
use App\Models\User;
use App\Services\GroupAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GroupController extends Controller
{
    public function index()
    {
        $userid = auth()->id();

        return Group::whereHas('users', function ($query) use ($userid) {
            $query->where('user_id', $userid);
        })->get();
    }

    public function show($groupId) {
        $group = Group::where('id', $groupId)
            ->first();
        if (!$group) {
            return response(['message' => 'task not found'], 404);
        }
        return $group;
    }

    public function destroy($groupId) {
        $group = Group::find($groupId);
        if (!$group) {
            return response(['message' => 'task not found'], 404);
        }
        $group->delete();
        return response(['message' => 'task deleted successfully'], 200);
    }

    public function store(GroupStoreRequest $request)
    {
        $validatedData = $request->validated();
        $group = DB::transaction(function () use ($validatedData, $request) {
            $group = Group::create($validatedData);
            $group->users()->attach($request->user()->id);

            return $group;
        });
        return $group;
    }

    public function update(GroupUpdateRequest $request, $groupId)
    {
        $validatedData = $request->validated();
        $group = Group::findOrFail($groupId);
        $group->update($validatedData);
        return $group;
    }

    public function members(Request $request, GroupAccess $access, $groupId)
    {
        return $access->find($request, $groupId)
            ->users()->select(['users.id', 'users.name', 'users.email'])->get();
    }
}
