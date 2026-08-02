<?php 

namespace App\Traits\Meta;

use App\Models\Meta;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasMeta
{
       public function metas(): MorphMany
       {
              return $this->morphMany(Meta::class, 'metable');
       }

       public function setMeta(string $key, mixed $value, string $type = 'string'): void
       {
              $meta = $this->metas()->where('key', $key)->first();

              if (is_object($value)) {
                     $value = json_encode($value, );
              }
              
              if(is_array($value)) {
                     $value = json_encode($value, JSON_UNESCAPED_UNICODE);
              }

              if ($meta) {
                     $meta->value = $value;
                     $meta->type = $type;
                     $meta->save();
              } else {
                     $this->metas()->create([
                            'key' => $key, 
                            'value' => $value,
                            'type' => $type,
                     ]);
              }
       }

       public function getMeta(string $key): mixed
       {
              $meta = $this->metas()->where('key', $key)->first();
              $metavalue = $meta ? $meta->value : null;
              return $metavalue;
       }


       public function setMultipleMeta(array $data): void
       {
              foreach ($data as $key => $value) {
                     $this->setMeta($key, $value);
              }
       }

       public function getAllMeta(): array
       {
              $metas =  $this->metas()->get()->toArray();
              return $metas;
       }
       
       public function getMetaKeyValues(): array
       {
              $metas = $this->getAllMeta();
              $keyValues = [];
              foreach ($metas as $meta) {
                     $keyValues[$meta['key']] = $meta['value'];
              }
              return $keyValues;
       }
}
