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

    /**
     * When work on this module starts: the earliest date across its contents, so planning a
     * content's start date moves the module with it. Falls back to the module's own date
     * while it has no contents.
     */
    public function startDate()
    {
        return $this->moduleContents
            ->map(fn (ModuleContent $moduleContent) => $moduleContent->studyDate())
            ->filter()
            ->min() ?? $this->created_at;
    }
}
