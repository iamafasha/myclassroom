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
}
