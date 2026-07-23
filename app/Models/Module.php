<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $guarded = [];

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function moduleContents()
    {
        return $this->hasMany(ModuleContent::class)->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }
}
