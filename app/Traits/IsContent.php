<?php 

namespace App\Traits;

use App\Models\Content;

trait IsContent
{
    public function content()
    {
        return $this->morphOne(Content::class, 'contentable');
    }
}