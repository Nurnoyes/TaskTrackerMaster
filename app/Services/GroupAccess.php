<?php

namespace App\Services;

use App\Models\Group;
use Illuminate\Http\Request;

class GroupAccess
{
    public function find(Request $request, int $groupId): Group
    {
        return $request->user()->groups()->where('groups.id', $groupId)->firstOrFail();
    }
}
