<?php 

namespace App\Traits;

use App\Traits\Content;

trait IsContent
{
    public function content()
    {
        return $this->morphOne(Content::class, 'contentable');
    }
}