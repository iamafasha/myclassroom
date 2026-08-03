<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContentExerciseAnswer extends Model
{
    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function contentModuleContent()
    {
        return $this->belongsTo(ContentModuleContent::class);
    }
}
