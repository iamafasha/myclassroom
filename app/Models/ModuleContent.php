<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleContent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'study_at' => 'date',
        ];
    }

    /** The day this content is meant to be studied, falling back to when it was added. */
    public function studyDate()
    {
        return $this->study_at ?? $this->created_at;
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function contents()
    {
        return $this->belongsToMany(Content::class, 'content_module_content')
            ->using(ContentModuleContent::class)
            ->withPivot('id', 'sort_order', 'is_exercise')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderByPivot('id');
    }

    /** Everyone's progress through this lesson — one row per person. */
    public function progress()
    {
        return $this->hasMany(ModuleContentProgress::class);
    }

    /**
     * Reads from the loaded relation when it is there, so a list of lessons rendered with
     * `with('progress')` doesn't fire a query per row.
     */
    public function progressFor($user): ?ModuleContentProgress
    {
        $userId = $user instanceof User ? $user->id : $user;

        if (! $userId) {
            return null;
        }

        if ($this->relationLoaded('progress')) {
            return $this->progress->firstWhere('user_id', $userId);
        }

        return $this->progress()->where('user_id', $userId)->first();
    }

    public function isCompletedFor($user): bool
    {
        return (bool) $this->progressFor($user)?->isCompleted();
    }

    public function quizScoreFor($user): ?string
    {
        return $this->progressFor($user)?->quiz_score;
    }

    public function markCompletedFor($user, ?string $quizScore = null): ModuleContentProgress
    {
        return $this->recordProgressFor($user, [
            'completed_at' => now(),
        ] + ($quizScore === null ? [] : ['quiz_score' => $quizScore]));
    }

    public function toggleCompletedFor($user): ModuleContentProgress
    {
        return $this->recordProgressFor($user, [
            'completed_at' => $this->isCompletedFor($user) ? null : now(),
        ]);
    }

    private function recordProgressFor($user, array $attributes): ModuleContentProgress
    {
        $userId = $user instanceof User ? $user->id : $user;

        $progress = ModuleContentProgress::firstOrNew([
            'module_content_id' => $this->id,
            'user_id' => $userId,
        ]);

        $progress->fill($attributes)->save();

        if ($this->relationLoaded('progress')) {
            $this->unsetRelation('progress');
        }

        return $progress;
    }
}
