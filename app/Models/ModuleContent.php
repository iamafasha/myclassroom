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
}
