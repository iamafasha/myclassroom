<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Meta extends Model
{
    use HasFactory, HasUuids;

 
    
    protected $guarded = [];
    public $hidden = ['created_at', 'updated_at' , 'metable_type', 'metable_id', 'id'];

    /**
     * Get the parent commentable model (post or video).
     */
    public function metarable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getValueAttribute($value)
    {
        return $this->serializeMetaValue($value);
    }


        public  function serializeMetaValue($value): mixed
       {
                if($this->type == 'array' || $this->type == 'objectArray') {
                    if (json_decode($value) !== null) {
                            return json_decode($value, true);
                    }                
                }
              return $value;
       }

}
