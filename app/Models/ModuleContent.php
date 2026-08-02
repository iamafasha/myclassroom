<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleContent extends Model
{
    protected $guarded = [];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function contents()
    {
        return $this->belongsToMany(Content::class, 'content_module_content')
            ->using(ContentModuleContent::class)
            ->withPivot('sort_order', 'is_exercise', 'submission_link', 'submission_file_path', 'score')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderByPivot('id');
    }
}
