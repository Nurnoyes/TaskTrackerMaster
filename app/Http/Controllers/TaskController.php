<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function showTask($taskId) {
        return view('tasks.index', ['id' => $taskId]);
    }



}
