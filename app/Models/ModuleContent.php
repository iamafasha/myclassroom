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
        return $this->belongsToMany(Content::class, 'content_module_content')->withTimestamps();
    }
}
