<?php

namespace App\Models;

use App\Traits\Meta\HasMeta;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasFactory, HasMeta;

    protected $guarded = [];


    public static function current()
    {
        return self::find(session('workspace_id'));
    }
    
}
