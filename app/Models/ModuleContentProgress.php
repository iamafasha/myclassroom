<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One person's progress through one lesson: whether they have finished it and the score
 * their last quiz attempt earned. Everybody in a class keeps their own row.
 */
class ModuleContentProgress extends Model
{
    protected $table = 'module_content_user';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    public function moduleContent()
    {
        return $this->belongsTo(ModuleContent::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
