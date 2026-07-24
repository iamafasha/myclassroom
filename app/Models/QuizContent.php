<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\IsContent;

class QuizContent extends Model
{
    use IsContent;

    protected $guarded = [];

    protected $casts = [
        'questions' => 'array',
    ];
}
