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
            ->withPivot('id', 'sort_order', 'is_exercise')
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderByPivot('id');
    }
}
