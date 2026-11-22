<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Status extends Model
{

    protected $fillable = [
        'name',
        'description',
        'color',
        'group_id',
        'category',
        'sla_minutes',
        'position',
    ];

    protected $table = 'statuses';

    protected $attributes = ['category' => 'work', 'sla_minutes' => 1440, 'position' => 0];

    protected function casts(): array
    {
        return ['sla_minutes' => 'integer', 'position' => 'integer'];
    }

    public function tasks() {
        return $this->hasMany(Task::class, 'status_id');
    }
}
