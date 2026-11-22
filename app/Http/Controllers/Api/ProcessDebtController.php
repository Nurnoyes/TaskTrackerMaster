<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GroupAccess;
use App\Services\ProcessDebtService;
use App\Services\TaskWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessDebtController extends Controller
{
    public function __construct(
        private readonly GroupAccess $access,
        private readonly TaskWorkflowService $workflow,
        private readonly ProcessDebtService $analytics,
    ) {}

    public function index(Request $request, int $groupId): JsonResponse
    {
        $group = $this->access->find($request, $groupId);
        $filters = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'manager_id' => ['nullable', 'integer'],
        ]);
        $this->workflow->markExpired($group->id);

        return response()->json($this->analytics->analyze(
            $group,
            $filters['type'] ?? null,
            isset($filters['manager_id']) ? (int) $filters['manager_id'] : null,
        ));
    }
}
