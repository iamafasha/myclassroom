<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Content extends Model
{
    protected $guarded = [];

    public function contentable()
    {
        return $this->morphTo();
    }

    public function moduleContents()
    {
        return $this->belongsToMany(ModuleContent::class, 'content_module_content')
            ->using(ContentModuleContent::class)
            ->withPivot('sort_order', 'is_exercise', 'submission_link', 'submission_file_path', 'score')
            ->withTimestamps();
    }

}
