<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\TaskWorkflowService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tasks:mark-expired', function (TaskWorkflowService $workflow) {
    $count = $workflow->markExpired();
    $this->info("Marked {$count} tasks as expired.");
})->purpose('Persist overdue task flags without changing status history');

Schedule::command('tasks:mark-expired')->everyMinute()->withoutOverlapping();
