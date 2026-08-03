<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Course;
use App\Models\LiveClassContent;

new class extends Component {

    /**
     * Live classes from every course the user can see, from the ones running now
     * through the upcoming ones. There is no notifications table — the schedule
     * itself is the feed.
     */
    #[Computed]
    public function items()
    {
        $courseIds = Course::visibleTo(auth()->user())->pluck('id');

        if ($courseIds->isEmpty()) {
            return collect();
        }

        return LiveClassContent::query()
            ->with('content.moduleContents.module.course')
            ->whereHas('content.moduleContents.module', fn ($q) => $q->whereIn('course_id', $courseIds))
            ->where('starts_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->get()
            ->filter(fn ($liveClass) => $liveClass->status() !== 'ended')
            ->take(15)
            ->values();
    }

    /** Badge only counts what needs attention today: running now, or starting within 24h. */
    #[Computed]
    public function urgentCount()
    {
        return $this->items
            ->filter(fn ($liveClass) => $liveClass->status() === 'live' || $liveClass->starts_at->lte(now()->addDay()))
            ->count();
    }
}; ?>

<div x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false"
     wire:poll.120s style="position: relative;">

    <button type="button" @click="open = !open" class="sidebar-item" :class="open ? 'active' : ''" title="Notifications">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
        </svg>
        @if($this->urgentCount > 0)
            <span style="position: absolute; top: 2px; right: 2px; min-width: 17px; height: 17px; padding: 0 4px; border-radius: 9999px; background: #EF4444; color: white; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; border: 2px solid white;">
                {{ $this->urgentCount > 9 ? '9+' : $this->urgentCount }}
            </span>
        @endif
    </button>

    <!-- Panel -->
    <div x-show="open" x-transition x-cloak
         style="display: none; position: fixed; left: 88px; bottom: 16px; width: 340px; z-index: 60;">
      <div style="display: flex; flex-direction: column; max-height: calc(100vh - 32px); background: white; border: 1px solid #E5E7EB; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.12); overflow: hidden;">

        <div style="padding: 16px 18px; border-bottom: 1px solid #F3F4F6; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 14px; font-weight: 700; color: #111827;">Notifications</h3>
            <button type="button" @click="open = false" style="background: none; border: none; cursor: pointer; color: #9CA3AF; padding: 2px; display: flex;">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div style="overflow-y: auto; padding: 8px;">
            @forelse($this->items as $liveClass)
                @php
                    $isLive = $liveClass->status() === 'live';
                    $moduleContent = $liveClass->moduleContent();
                    $course = $liveClass->course();
                @endphp
                <div style="padding: 12px; border-radius: 12px; display: flex; flex-direction: column; gap: 6px; {{ $isLive ? 'background: #FEF2F2;' : '' }}">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        @if($isLive)
                            <span style="width: 7px; height: 7px; border-radius: 9999px; background: #DC2626; display: inline-block;"></span>
                            <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #DC2626;">Live now</span>
                        @else
                            <span style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #6D28D9;">
                                {{ $liveClass->starts_at->diffForHumans() }}
                            </span>
                        @endif
                    </div>

                    <p style="margin: 0; font-size: 13px; font-weight: 600; color: #111827;">{{ $liveClass->calendarTitle() }}</p>

                    <p style="margin: 0; font-size: 11px; color: #6B7280;">
                        {{ $liveClass->starts_at->format('D, j M · H:i') }} – {{ $liveClass->endsAt()->format('H:i') }}
                        @if($course) <br>{{ $course->title }} @endif
                    </p>

                    <div style="display: flex; gap: 10px; align-items: center; margin-top: 2px;">
                        @if($moduleContent)
                            <a href="{{ route('content.show', $moduleContent->id) }}" wire:navigate @click="open = false"
                               style="font-size: 11px; font-weight: 700; color: #2563EB; text-decoration: none;">Open</a>
                        @endif
                        <a href="{{ route('live-class.ics', $liveClass->id) }}"
                           style="font-size: 11px; font-weight: 700; color: #4B5563; text-decoration: none;">Add to calendar</a>
                        <a href="{{ $liveClass->googleCalendarUrl() }}" target="_blank" rel="noopener noreferrer"
                           style="font-size: 11px; font-weight: 700; color: #4B5563; text-decoration: none;">Google</a>
                    </div>
                </div>
            @empty
                <div style="text-align: center; padding: 40px 20px;">
                    <svg width="32" height="32" fill="none" stroke="#D1D5DB" stroke-width="1.5" viewBox="0 0 24 24" style="margin: 0 auto 10px; display: block;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <p style="margin: 0; font-size: 13px; font-weight: 600; color: #4B5563;">You're all caught up</p>
                    <p style="margin: 4px 0 0; font-size: 12px; color: #9CA3AF;">Upcoming live classes will appear here.</p>
                </div>
            @endforelse
        </div>
      </div>
    </div>
</div>
