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
            ->wherePivotNull('removed_at')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderByPivot('id');
    }

    /**
     * Attaches a block right after `$afterContentId`, or at the end when that is null or
     * not one of this content's blocks. Later blocks shift down one to make room.
     */
    public function addBlock(Content $content, bool $isExercise = false, $afterContentId = null): void
    {
        $blocks = $this->contents()->get();
        $afterIndex = $afterContentId === null ? false : $blocks->search(fn ($block) => $block->id == $afterContentId);
        $position = $afterIndex === false ? $blocks->count() : $afterIndex + 1;

        // Renumber so the new block's slot is free, whatever gaps or ties the old order had.
        foreach ($blocks->values() as $index => $block) {
            $this->contents()->updateExistingPivot($block->id, ['sort_order' => $index < $position ? $index : $index + 1]);
        }

        $this->contents()->attach($content->id, ['sort_order' => $position, 'is_exercise' => $isExercise]);
    }

    /** Puts the blocks in the given order. Ids that aren't this content's blocks are ignored. */
    public function reorderBlocks(array $contentIds): void
    {
        $blocks = $this->contents()->get()->keyBy('id');
        $ordered = collect($contentIds)->map(fn ($id) => (int) $id)->unique()->filter(fn ($id) => $blocks->has($id));

        // Blocks the request left out keep their relative order at the end.
        $ordered = $ordered->merge($blocks->keys()->diff($ordered))->values();

        foreach ($ordered as $index => $id) {
            $this->contents()->updateExistingPivot($id, ['sort_order' => $index]);
        }
    }

    /**
     * Deletes blocks that were removed (and so hidden) more than `$graceSeconds` ago, along
     * with their content. Their exercise answers go with the pivot row through the cascade.
     */
    public function purgeRemovedBlocks(int $graceSeconds = 0): void
    {
        $pivots = ContentModuleContent::where('module_content_id', $this->id)
            ->whereNotNull('removed_at')
            ->where('removed_at', '<=', now()->subSeconds($graceSeconds))
            ->get();

        foreach ($pivots as $pivot) {
            $content = Content::find($pivot->content_id);
            $pivot->delete();

            // A content shared with another lesson stays for that lesson.
            if ($content && ! ContentModuleContent::where('content_id', $content->id)->exists()) {
                $content->contentable?->delete();
                $content->delete();
            }
        }
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
