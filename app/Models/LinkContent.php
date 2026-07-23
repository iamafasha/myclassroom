<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\IsContent;

class LinkContent extends Model
{
    use IsContent;

    protected $guarded = [];
}
