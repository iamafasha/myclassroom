<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Traits\IsContent;

class LiveClassContent extends Model
{
    use IsContent;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'is_join_enabled' => 'boolean',
    ];

    /** The join button only shows when the owner has switched joining on and given a link. */
    public function canJoin(): bool
    {
        return $this->is_join_enabled && filled($this->join_link);
    }

    public function endsAt()
    {
        return $this->starts_at?->copy()->addMinutes($this->duration_minutes ?: 60);
    }

    /** upcoming | live | ended */
    public function status(): string
    {
        if (!$this->starts_at) {
            return 'upcoming';
        }

        return match (true) {
            now()->lt($this->starts_at) => 'upcoming',
            now()->lte($this->endsAt()) => 'live',
            default => 'ended',
        };
    }
}
