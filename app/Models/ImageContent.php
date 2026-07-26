<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\IsContent;

class ImageContent extends Model
{
    use IsContent;
    protected $guarded = [];
}
