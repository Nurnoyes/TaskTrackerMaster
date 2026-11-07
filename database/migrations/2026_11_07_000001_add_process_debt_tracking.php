<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statuses', function (Blueprint $table) {
            $table->string('category', 20)->default('work');
            $table->unsignedInteger('sla_minutes')->default(1440);
            $table->unsignedInteger('position')->default(0);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('deadline')->nullable();
            $table->boolean('expired')->default(false);
            $table->timestamp('status_changed_at')->nullable();
            $table->string('type', 100)->default('general');
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['group_id', 'completed', 'expired', 'deadline'], 'tasks_expiry_index');
        });
        Schema::create('task_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->unsignedBigInteger('status_original_id')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('manager_name')->nullable();
            $table->string('task_type', 100)->default('general');
            $table->string('status_name');
            $table->string('status_color');
            $table->string('status_category', 20);
            $table->unsignedInteger('status_position')->default(0);
            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->boolean('is_rework')->default(false);
            $table->index(['task_id', 'entered_at']);
            $table->index(['group_id', 'task_type', 'manager_id'], 'history_analytics_index');
        });

        // Earlier transitions are unknown: observe from now without inventing past debt.
        $baseline = now()->startOfSecond();
        DB::table('tasks')->orderBy('id')->chunkById(200, function ($tasks) use ($baseline) {
            foreach ($tasks as $task) {
                $status = DB::table('statuses')->where('id', $task->status_id)->first();
                if (! $status) {
                    continue;
                }
                $deadline = $baseline->copy()->addMinutes($status->sla_minutes);
                DB::table('tasks')->where('id', $task->id)->update([
                    'deadline' => $deadline,
                    'status_changed_at' => $baseline,
                    'expired' => false,
                ]);
                DB::table('task_status_histories')->insert([
                    'task_id' => $task->id,
                    'group_id' => $task->group_id,
                    'status_id' => $status->id,
                    'status_original_id' => $status->id,
                    'task_type' => 'general',
                    'status_name' => $status->name,
                    'status_color' => $status->color,
                    'status_category' => $status->category,
                    'status_position' => $status->position,
                    'entered_at' => $baseline,
                    'left_at' => $task->completed ? $baseline : null,
                    'deadline' => $deadline,
                    'is_rework' => false,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_status_histories');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex('tasks_expiry_index');
            $table->dropForeign(['manager_id']);
            $table->dropColumn(['deadline', 'expired', 'status_changed_at', 'type', 'manager_id']);
        });
        Schema::table('statuses', function (Blueprint $table) {
            $table->dropColumn(['category', 'sla_minutes', 'position']);
        });
    }
};
