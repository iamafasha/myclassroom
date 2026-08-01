<?php

namespace App\Models;

use App\Traits\IsContent;
use Illuminate\Database\Eloquent\Model;

class PdfNotesContent extends Model
{
    use IsContent;

    protected $casts = [
        'start_percentage' => 'integer',
        'end_percentage' => 'integer',
    ];
}
