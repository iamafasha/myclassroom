<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ContentModuleContent extends Pivot
{
    protected $table = 'content_module_content';

    protected $guarded = [];

    protected $casts = [
        'is_exercise' => 'boolean',
    ];

    public function content()
    {
        return $this->belongsTo(Content::class);
    }

    public function moduleContent()
    {
        return $this->belongsTo(ModuleContent::class);
    }

    public function exerciseAnswers()
    {
        return $this->hasMany(ContentExerciseAnswer::class, 'content_module_content_id');
    }

    public function exerciseAnswerFor($user)
    {
        return $this->exerciseAnswers()->where('user_id', $user instanceof \App\Models\User ? $user->id : $user)->first();
    }
}
