<?php

use App\Models\MentorSession;
use App\Models\SessionContent;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

/**
 * The Session content block, rendered inside a lesson.
 *
 * Every student who opens it works with their own MentorSession attached to this
 * block. The mentor owns the details here: the block sets what the session is and
 * how long it runs, and times only ever come from the mentor. A student asks for a
 * session, then books by picking one of the times offered — they never type a topic
 * or propose a time of their own. Cancelling and rejoining stay theirs.
 */
new class extends Component {
    public int $sessionContentId;

    /** A published time picked in the UI but not booked yet. */
    public string $chosenSlot = '';

    /** A time the mentor offered on an existing session, keyed by session id. */
    public array $selectedSlots = [];

    /** Reply form (mentor): a shortlist of times for the student to choose from. */
    public ?int $respondingId = null;
    public array $slotInputs = [''];
    public string $meetingLink = '';
    public string $mentorNote = '';

    public string $flash = '';

    public function mount($sessionContent)
    {
        $block = $sessionContent instanceof SessionContent
            ? $sessionContent
            : SessionContent::findOrFail($sessionContent);

        abort_unless(
            $block->isVisibleTo(auth()->user()),
            403,
            'This session belongs to a course you do not have access to.'
        );

        $this->sessionContentId = $block->id;
    }

    #[Computed]
    public function block()
    {
        return SessionContent::findOrFail($this->sessionContentId);
    }

    #[Computed]
    public function isMentor()
    {
        return $this->block->isManagedBy(auth()->user());
    }

    /** The sessions the viewing student has booked from this block. */
    #[Computed]
    public function mySessions()
    {
        return $this->block->sessionsFor(auth()->user());
    }

    /** Requests students sent from this block, for the course owner. */
    #[Computed]
    public function incoming()
    {
        if (! $this->isMentor) {
            return collect();
        }

        return MentorSession::forMentor(auth()->user())
            ->forSessionContent($this->sessionContentId)
            ->with('student')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status IN ('proposed', 'scheduled') THEN 1 ELSE 2 END")
            ->orderByRaw('COALESCE(scheduled_at, preferred_at, created_at) ASC')
            ->get();
    }

    #[Computed]
    public function openSlots()
    {
        return $this->block->openSlots();
    }

    #[Computed]
    public function canBook()
    {
        return $this->block->canBeBookedBy(auth()->user());
    }

    /** Books one of the times the mentor published on the block itself. */
    public function bookSlot()
    {
        $block = $this->block;

        abort_unless($block->canBeBookedBy(auth()->user()), 403, 'Bookings are closed for this session.');

        if (! filled($this->chosenSlot)) {
            $this->addError('chosenSlot', 'Pick one of the times offered.');

            return;
        }

        // Someone else may have taken the time between render and submit.
        if (! $block->hasOpenSlot($this->chosenSlot)) {
            $this->addError('chosenSlot', 'That time has just been taken. Pick another one.');
            $this->reset('chosenSlot');
            unset($this->openSlots);

            return;
        }

        $session = $this->createSession(['status' => MentorSession::STATUS_PENDING])
            ->book($this->chosenSlot, $block->meeting_link);

        $this->reset('chosenSlot');
        $this->refresh();
        $this->flash = 'Session booked for ' . $session->scheduled_at->format('D j M Y, H:i') . '.';
    }

    /**
     * Asks the mentor for a session. Nothing to fill in: the block says what the
     * session is, and the times come back from the mentor for the student to pick.
     */
    public function requestSession()
    {
        $this->createSession(['status' => MentorSession::STATUS_PENDING]);

        $this->reset('chosenSlot');
        $this->refresh();
        $this->flash = 'Request sent. ' . ($this->block->mentor()?->name ?? 'Your mentor') . ' will send you times to choose from.';
    }

    /** Every session on this block is the block's own: same subject, same length. */
    private function createSession(array $attributes): MentorSession
    {
        $block = $this->block;

        abort_unless($block->canBeBookedBy(auth()->user()), 403, 'Bookings are closed for this session.');

        $course = $block->course();
        abort_unless($course && $course->created_by, 404, 'This session is not attached to a course yet.');

        return MentorSession::create(array_merge([
            'course_id' => $course->id,
            'session_content_id' => $block->id,
            'student_id' => auth()->id(),
            'mentor_id' => $course->created_by,
            'topic' => $block->displayTitle(),
            'duration_minutes' => $block->duration_minutes ?: 30,
        ], $attributes));
    }

    /** The student books by picking one of the times the mentor offered back. */
    public function confirmSlot($sessionId)
    {
        $session = $this->studentSession($sessionId);

        abort_unless($session->isProposed(), 403, 'There are no times to pick for this session.');

        $chosen = $this->selectedSlots[$sessionId] ?? null;

        if (! $chosen || ! $session->hasSlot($chosen)) {
            $this->addError('selectedSlots.' . $sessionId, 'Pick one of the times offered.');

            return;
        }

        $session->book($chosen, $this->block->meeting_link);

        unset($this->selectedSlots[$sessionId]);
        $this->refresh();
        $this->flash = 'Session booked for ' . $session->scheduled_at->format('D j M Y, H:i') . '.';
    }

    public function cancelSession($sessionId)
    {
        $session = $this->studentSession($sessionId);

        abort_unless($session->isOpen(), 403, 'This session can no longer be cancelled.');

        $session->update(['status' => MentorSession::STATUS_CANCELLED]);

        $this->refresh();
        $this->flash = 'Session cancelled.';
    }

    /** Opens the panel where the mentor offers times, prefilled with what is known so far. */
    public function respondTo($sessionId)
    {
        $session = $this->mentorSession($sessionId);

        $this->resetValidation();
        $this->respondingId = $session->id;
        $this->meetingLink = (string) ($session->meeting_link ?: $this->block->meeting_link);
        $this->mentorNote = (string) $session->mentor_note;

        $existing = $session->slots()->map(fn ($slot) => $slot->format('Y-m-d\TH:i'))->all();

        // Nothing offered yet: start from the time the student asked for.
        $this->slotInputs = $existing ?: [optional($session->preferred_at)->format('Y-m-d\TH:i') ?? ''];
    }

    public function closeResponder()
    {
        $this->reset(['respondingId', 'slotInputs', 'meetingLink', 'mentorNote']);
        $this->resetValidation();
    }

    public function addSlot()
    {
        if (count($this->slotInputs) < 5) {
            $this->slotInputs[] = '';
        }
    }

    public function removeSlot($index)
    {
        unset($this->slotInputs[$index]);
        $this->slotInputs = array_values($this->slotInputs);

        if ($this->slotInputs === []) {
            $this->slotInputs = [''];
        }
    }

    public function proposeSlots()
    {
        $session = $this->mentorSession($this->respondingId);

        abort_unless($session->isOpen(), 403, 'This session is closed.');

        // Blank rows are just unused inputs, not an error.
        $this->slotInputs = array_values(array_filter($this->slotInputs, fn ($slot) => trim((string) $slot) !== ''));

        $this->validate([
            'slotInputs' => 'required|array|min:1|max:5',
            'slotInputs.*' => 'required|date|after:now',
            'meetingLink' => 'nullable|url|max:255',
            'mentorNote' => 'nullable|string|max:2000',
        ], [
            'slotInputs.required' => 'Offer the student at least one time.',
            'slotInputs.min' => 'Offer the student at least one time.',
            'slotInputs.*.required' => 'Fill in this time or remove the row.',
            'slotInputs.*.after' => 'Times must be in the future.',
        ]);

        $slots = collect($this->slotInputs)
            ->map(fn ($slot) => \Illuminate\Support\Carbon::parse($slot)->format('Y-m-d H:i:00'))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $session->update([
            'status' => MentorSession::STATUS_PROPOSED,
            'proposed_slots' => $slots,
            // Re-offering clears the old booking; the student picks again.
            'scheduled_at' => null,
            'meeting_link' => $this->meetingLink ?: null,
            'mentor_note' => $this->mentorNote ?: null,
        ]);

        $this->closeResponder();
        $this->refresh();
        $this->flash = 'Times sent. The student will pick one of them.';
    }

    public function declineSession($sessionId = null)
    {
        $session = $this->mentorSession($sessionId ?? $this->respondingId);

        abort_unless($session->isOpen(), 403, 'This session is closed.');

        $session->update([
            'status' => MentorSession::STATUS_DECLINED,
            'mentor_note' => ($sessionId === null && $this->mentorNote !== '') ? $this->mentorNote : $session->mentor_note,
        ]);

        $this->closeResponder();
        $this->refresh();
        $this->flash = 'Session declined.';
    }

    public function completeSession($sessionId)
    {
        $session = $this->mentorSession($sessionId);

        abort_unless($session->isScheduled(), 403, 'Only a booked session can be marked done.');

        $session->update(['status' => MentorSession::STATUS_COMPLETED]);

        $this->refresh();
        $this->flash = 'Session marked as completed.';
    }

    /** Ids come from the browser, so every action re-checks the session is this block's and this user's. */
    private function studentSession($sessionId): MentorSession
    {
        return MentorSession::forStudent(auth()->user())
            ->forSessionContent($this->sessionContentId)
            ->findOrFail($sessionId);
    }

    private function mentorSession($sessionId): MentorSession
    {
        return MentorSession::forMentor(auth()->user())
            ->forSessionContent($this->sessionContentId)
            ->findOrFail($sessionId);
    }

    private function refresh(): void
    {
        unset($this->block, $this->mySessions, $this->incoming, $this->openSlots, $this->canBook);
    }
}; ?>

@php
    $block = $this->block;
    $mentorName = $block->mentor()?->name;
@endphp

<div style="border: 1px solid #DDD6FE; background: #FAF5FF; border-radius: 12px; padding: 24px;">

    <!-- Block header -->
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
        <div>
            <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #6D28D9;">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                One-to-one session
            </span>
            <h3 style="margin: 8px 0 0; font-size: 1.25rem; font-weight: 700; color: #111827;">{{ $block->displayTitle() }}</h3>
            <div style="margin-top: 8px; display: flex; flex-wrap: wrap; gap: 16px; font-size: 0.85rem; color: #4B5563;">
                <span style="display: flex; align-items: center; gap: 6px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ $block->duration_minutes ?: 30 }} min
                </span>
                @if($mentorName)
                    <span style="display: flex; align-items: center; gap: 6px;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        with {{ $mentorName }}
                    </span>
                @endif
            </div>
        </div>

        @if(! $block->is_booking_enabled)
            <span style="flex-shrink: 0; padding: 5px 12px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: #F3F4F6; color: #4B5563;">Bookings closed</span>
        @endif
    </div>

    @if($block->description)
        <div style="margin-top: 16px; padding-top: 14px; border-top: 1px solid #EDE9FE; color: #374151; font-size: 0.95rem; line-height: 1.6;">
            {!! nl2br(e($block->description)) !!}
        </div>
    @endif

    @if($flash)
        <div style="margin-top: 16px; background-color: #ECFDF5; color: #065F46; padding: 11px 15px; border-radius: 8px; font-size: 13px; font-weight: 500; border: 1px solid #A7F3D0;">
            {{ $flash }}
        </div>
    @endif

    @if($this->isMentor)
        <!-- ============ Course owner: the requests students sent from this block ============ -->
        <div style="margin-top: 20px;">
            @if($this->openSlots->isNotEmpty())
                <div style="background: white; border: 1px solid #E5E7EB; border-radius: 10px; padding: 14px 16px; margin-bottom: 16px;">
                    <div style="font-size: 11px; font-weight: 700; color: #6B7280; text-transform: uppercase; margin-bottom: 8px;">Times students can book instantly</div>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        @foreach($this->openSlots as $slot)
                            <span style="background: #F5F3FF; border: 1px solid #DDD6FE; color: #5B21B6; border-radius: 8px; padding: 5px 11px; font-size: 12px; font-weight: 600;">
                                {{ $slot->format('D j M, H:i') }}
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 13px; font-weight: 700; color: #374151;">Requests from this session ({{ $this->incoming->count() }})</span>
                <a href="{{ route('sessions.index') }}" wire:navigate style="font-size: 12px; font-weight: 600; color: #4F46E5; text-decoration: none;">All my sessions &rarr;</a>
            </div>

            @forelse($this->incoming as $session)
                @php([$pillBg, $pillText] = $session->statusColors())
                <div wire:key="incoming-{{ $session->id }}" style="background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 18px; margin-bottom: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 14px;">
                        <div>
                            <h4 style="margin: 0 0 5px; font-size: 14px; font-weight: 700; color: #111827;">{{ $session->topic }}</h4>
                            <p style="margin: 0; font-size: 12px; color: #6B7280;">
                                from {{ $session->student?->name ?? 'student' }}
                                @if($session->displayAt())
                                    · {{ $session->displayAt()->format('D j M Y, H:i') }}@if(! $session->scheduled_at) <span style="color: #9CA3AF;">(requested)</span>@endif
                                @endif
                                · {{ $session->duration_minutes }} min
                            </p>
                        </div>
                        <span style="flex-shrink: 0; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: {{ $pillBg }}; color: {{ $pillText }};">
                            {{ $session->statusLabel() }}
                        </span>
                    </div>

                    @if($session->message)
                        <p style="margin: 12px 0 0; font-size: 13px; color: #4B5563; line-height: 1.6; white-space: pre-line;">{{ $session->message }}</p>
                    @endif

                    @if($session->isProposed())
                        <div style="margin-top: 12px; font-size: 12px; color: #6B7280;">
                            <span style="font-weight: 700; color: #5B21B6;">Waiting on {{ $session->student?->name ?? 'the student' }}</span>
                            · offered {{ $session->slots()->map(fn ($slot) => $slot->format('D j M, H:i'))->implode(' · ') ?: 'no times left' }}
                        </div>
                    @endif

                    <!-- Offer times, inline under the request it belongs to. -->
                    @if($respondingId === $session->id)
                        <form wire:submit="proposeSlots" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #F3F4F6; display: flex; flex-direction: column; gap: 14px;">
                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px;">Available times <span style="font-weight: 400; color: #9CA3AF;">(up to five — the student picks one)</span></label>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    @foreach($slotInputs as $index => $slot)
                                        <div wire:key="slot-{{ $index }}">
                                            <div style="display: flex; gap: 8px; align-items: center;">
                                                <input wire:model="slotInputs.{{ $index }}" type="datetime-local"
                                                       style="flex: 1; padding: 9px 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                                                @if(count($slotInputs) > 1)
                                                    <button type="button" wire:click="removeSlot({{ $index }})" title="Remove this time"
                                                            style="background: none; border: none; cursor: pointer; color: #9CA3AF; padding: 6px; display: flex;">
                                                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                        </svg>
                                                    </button>
                                                @endif
                                            </div>
                                            @error('slotInputs.' . $index) <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                                        </div>
                                    @endforeach
                                </div>
                                @error('slotInputs') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 6px;">{{ $message }}</span> @enderror

                                @if(count($slotInputs) < 5)
                                    <button type="button" wire:click="addSlot"
                                            style="margin-top: 9px; background: none; border: 1px dashed #93C5FD; color: #2563EB; border-radius: 8px; padding: 7px 12px; font-size: 12px; font-weight: 600; cursor: pointer;">
                                        + Add another time
                                    </button>
                                @endif
                            </div>

                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px;">Meeting link <span style="font-weight: 400; color: #9CA3AF;">(optional)</span></label>
                                <input wire:model="meetingLink" type="url" placeholder="https://meet.google.com/..."
                                       style="width: 100%; padding: 9px 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box;">
                                @error('meetingLink') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                            </div>

                            <div>
                                <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px;">Note to student <span style="font-weight: 400; color: #9CA3AF;">(optional)</span></label>
                                <textarea wire:model="mentorNote" rows="2" placeholder="What to prepare before the session."
                                          style="width: 100%; padding: 9px 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 13px; outline: none; box-sizing: border-box; font-family: inherit; resize: vertical;"></textarea>
                                @error('mentorNote') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                            </div>

                            <div style="display: flex; gap: 10px;">
                                <button type="submit" style="padding: 9px 18px; border: none; border-radius: 8px; background: #2563EB; color: white; font-size: 13px; font-weight: 600; cursor: pointer;">Send times</button>
                                <button type="button" wire:click="closeResponder" style="padding: 9px 18px; border: 1px solid #D1D5DB; border-radius: 8px; background: white; color: #374151; font-size: 13px; font-weight: 600; cursor: pointer;">Cancel</button>
                            </div>
                        </form>
                    @else
                        <div style="display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; padding-top: 14px; border-top: 1px solid #F3F4F6;">
                            @if($session->isOpen())
                                <button wire:click="respondTo({{ $session->id }})"
                                        style="padding: 7px 14px; border-radius: 8px; border: none; background: {{ $session->isPending() ? '#111827' : '#F3F4F6' }}; color: {{ $session->isPending() ? 'white' : '#374151' }}; font-size: 12px; font-weight: 600; cursor: pointer;">
                                    {{ $session->isPending() ? 'Offer times' : 'Change times' }}
                                </button>
                            @endif
                            @if($session->isPending() || $session->isProposed())
                                <button wire:click="declineSession({{ $session->id }})" wire:confirm="Decline this session request?"
                                        style="padding: 7px 14px; border-radius: 8px; border: 1px solid #FECACA; background: white; color: #B91C1C; font-size: 12px; font-weight: 600; cursor: pointer;">
                                    Decline
                                </button>
                            @endif
                            @if($session->isScheduled())
                                <button wire:click="completeSession({{ $session->id }})"
                                        style="padding: 7px 14px; border-radius: 8px; border: 1px solid #A7F3D0; background: white; color: #047857; font-size: 12px; font-weight: 600; cursor: pointer;">
                                    Mark completed
                                </button>
                                @if($session->meeting_link)
                                    <a href="{{ $session->meeting_link }}" target="_blank" rel="noopener"
                                       style="padding: 7px 14px; border-radius: 8px; background: #2563EB; color: white; font-size: 12px; font-weight: 600; text-decoration: none;">Join</a>
                                @endif
                            @endif
                        </div>
                    @endif
                </div>
            @empty
                <div style="background: white; border: 1px dashed #D1D5DB; border-radius: 12px; padding: 28px; text-align: center; font-size: 13px; color: #6B7280;">
                    No student has booked a session from this block yet. Each student who opens it gets their own.
                </div>
            @endforelse
        </div>
    @else
        <!-- ============ Student: my own session on this block ============ -->

        @foreach($this->mySessions as $session)
            @php([$pillBg, $pillText] = $session->statusColors())
            <div wire:key="mine-{{ $session->id }}"
                 style="margin-top: 18px; background: white; border: 1px solid {{ $session->isProposed() ? '#DDD6FE' : '#E5E7EB' }}; border-radius: 12px; padding: 20px; display: flex; flex-direction: column; gap: 14px;">

                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 14px;">
                        <div>
                            <h4 style="margin: 0 0 5px; font-size: 14px; font-weight: 700; color: #111827;">{{ $session->topic }}</h4>
                            <p style="margin: 0; font-size: 12px; color: #6B7280;">Your session with {{ $session->mentor?->name ?? 'the mentor' }}</p>
                        </div>
                        <span style="flex-shrink: 0; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: {{ $pillBg }}; color: {{ $pillText }};">
                            {{ $session->statusLabel() }}
                        </span>
                    </div>

                    <!-- Nothing for the student to do until the mentor comes back with times. -->
                    @if($session->isPending())
                        <div style="background: #FFFBEB; border: 1px solid #FEF3C7; border-radius: 10px; padding: 13px; font-size: 13px; color: #92400E;">
                            Waiting on {{ $session->mentor?->name ?? 'your mentor' }} to send times. They will show up here for you to pick from.
                        </div>
                    @endif

                    @if($session->mentor_note)
                        <div style="background: #F9FAFB; border-left: 3px solid #D1D5DB; padding: 10px 14px; border-radius: 0 8px 8px 0;">
                            <div style="font-size: 11px; font-weight: 700; color: #6B7280; text-transform: uppercase; margin-bottom: 4px;">Mentor note</div>
                            <p style="margin: 0; font-size: 13px; color: #374151; line-height: 1.6; white-space: pre-line;">{{ $session->mentor_note }}</p>
                        </div>
                    @endif

                    <!-- The mentor answered with times; the student books by picking one. -->
                    @if($session->isProposed())
                        @if($session->slots()->isEmpty())
                            <div style="background: #FFFBEB; border: 1px solid #FEF3C7; border-radius: 10px; padding: 13px; font-size: 13px; color: #92400E;">
                                Every time offered has now passed. Cancel this one and ask again, or wait for new times.
                            </div>
                        @else
                            <div style="background: #FAF5FF; border: 1px solid #EDE9FE; border-radius: 10px; padding: 16px;">
                                <div style="font-size: 12px; font-weight: 700; color: #5B21B6; text-transform: uppercase; margin-bottom: 12px;">Pick a time that works for you</div>
                                <div style="display: flex; flex-direction: column; gap: 8px;">
                                    @foreach($session->slots() as $slot)
                                        @php($value = $slot->format('Y-m-d H:i:00'))
                                        <label style="display: flex; align-items: center; gap: 10px; background: white; border: 1px solid {{ ($selectedSlots[$session->id] ?? null) === $value ? '#7C3AED' : '#E5E7EB' }}; border-radius: 8px; padding: 10px 13px; cursor: pointer;">
                                            <input type="radio" wire:model.live="selectedSlots.{{ $session->id }}" value="{{ $value }}" style="accent-color: #7C3AED; cursor: pointer;">
                                            <span style="font-size: 13px; font-weight: 600; color: #111827;">{{ $slot->format('D j M Y, H:i') }}</span>
                                            <span style="font-size: 12px; color: #9CA3AF;">· {{ $slot->diffForHumans() }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('selectedSlots.' . $session->id) <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 8px;">{{ $message }}</span> @enderror
                                <button wire:click="confirmSlot({{ $session->id }})"
                                        style="margin-top: 14px; padding: 9px 18px; border: none; border-radius: 8px; background: #7C3AED; color: white; font-size: 13px; font-weight: 600; cursor: pointer;">
                                    Book this time
                                </button>
                            </div>
                        @endif
                    @endif

                    <div style="display: flex; flex-wrap: wrap; gap: 18px; align-items: center; font-size: 12px; color: #6B7280;">
                        <span style="display: flex; align-items: center; gap: 5px;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            @if($session->displayAt())
                                {{ $session->displayAt()->format('D j M Y, H:i') }}
                                @if(! $session->scheduled_at) <span style="color: #9CA3AF;">(requested)</span> @endif
                            @else
                                No time set yet
                            @endif
                        </span>
                        <span style="display: flex; align-items: center; gap: 5px;">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            {{ $session->duration_minutes }} min
                        </span>
                    </div>

                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding-top: 14px; border-top: 1px solid #F3F4F6;">
                        @if($session->isScheduled() && $session->meeting_link)
                            <a href="{{ $session->meeting_link }}" target="_blank" rel="noopener"
                               style="padding: 8px 16px; border-radius: 8px; background: #2563EB; color: white; font-size: 13px; font-weight: 600; text-decoration: none;">
                                Join session
                            </a>
                        @endif

                        @if($session->isScheduled())
                            <a href="{{ $session->googleCalendarUrl() }}" target="_blank" rel="noopener"
                               style="padding: 8px 16px; border-radius: 8px; border: 1px solid #D1D5DB; background: white; color: #374151; font-size: 13px; font-weight: 600; text-decoration: none;">
                                Add to calendar
                            </a>
                        @endif

                        @if($session->isOpen())
                            <button wire:click="cancelSession({{ $session->id }})" wire:confirm="Cancel this session?"
                                    style="padding: 8px 16px; border-radius: 8px; border: 1px solid #D1D5DB; background: white; color: #4B5563; font-size: 13px; font-weight: 600; cursor: pointer;">
                                {{ $session->isScheduled() ? 'Cancel session' : 'Cancel request' }}
                            </button>
                        @endif

                        <a href="{{ route('sessions.index') }}" wire:navigate style="margin-left: auto; font-size: 12px; font-weight: 600; color: #4F46E5; text-decoration: none;">
                            All my sessions &rarr;
                        </a>
                    </div>
            </div>
        @endforeach

        <!-- Times always come from the mentor: pick one they published, or ask them to send some. -->
        @if($this->canBook)
            @if($this->openSlots->isNotEmpty())
                <div style="margin-top: 18px; background: white; border: 1px solid #DDD6FE; border-radius: 12px; padding: 20px;">
                    <h4 style="margin: 0 0 4px; font-size: 15px; font-weight: 700; color: #111827;">
                        {{ $this->mySessions->isEmpty() ? 'Book your session' : 'Book another session' }}
                    </h4>
                    <p style="margin: 0 0 14px; font-size: 12px; color: #6B7280;">
                        {{ $mentorName ?? 'Your mentor' }} is free at these times. Pick the one that suits you.
                    </p>

                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        @foreach($this->openSlots as $slot)
                            @php($value = $slot->format('Y-m-d H:i:00'))
                            <label wire:key="open-slot-{{ $loop->index }}"
                                   style="display: flex; align-items: center; gap: 10px; background: #FAF5FF; border: 1px solid {{ $chosenSlot === $value ? '#7C3AED' : '#EDE9FE' }}; border-radius: 8px; padding: 10px 13px; cursor: pointer;">
                                <input type="radio" wire:model.live="chosenSlot" value="{{ $value }}" style="accent-color: #7C3AED; cursor: pointer;">
                                <span style="font-size: 13px; font-weight: 600; color: #111827;">{{ $slot->format('D j M Y, H:i') }}</span>
                                <span style="font-size: 12px; color: #9CA3AF;">· {{ $slot->diffForHumans() }}</span>
                            </label>
                        @endforeach
                    </div>
                    @error('chosenSlot') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 8px;">{{ $message }}</span> @enderror

                    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 14px; margin-top: 16px;">
                        <button wire:click="bookSlot"
                                style="padding: 10px 20px; border: none; border-radius: 8px; background: #7C3AED; color: white; font-size: 13px; font-weight: 600; cursor: pointer;">
                            Book this time
                        </button>
                        <button wire:click="requestSession"
                                style="background: none; border: none; padding: 0; font-size: 12px; font-weight: 600; color: #4F46E5; cursor: pointer; text-decoration: underline;">
                            None of these work — ask for other times
                        </button>
                    </div>
                </div>
            @else
                <div style="margin-top: 18px; display: flex; flex-wrap: wrap; align-items: center; gap: 14px;">
                    <button wire:click="requestSession"
                            style="display: inline-flex; align-items: center; gap: 8px; background: #7C3AED; color: white; border: none; padding: 11px 22px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        {{ $this->mySessions->isEmpty() ? 'View session' : 'Ask for another session' }}
                    </button>
                    <span style="font-size: 12px; color: #6B7280; max-width: 380px;">
                        No times up yet — ask, and {{ $mentorName ?? 'your mentor' }} will send you times to choose from here.
                    </span>
                </div>
            @endif
        @elseif($this->mySessions->isEmpty())
            <div style="margin-top: 18px; background: white; border: 1px dashed #D1D5DB; border-radius: 12px; padding: 22px; text-align: center; font-size: 13px; color: #6B7280;">
                @if(! $block->is_booking_enabled)
                    Booking is closed for this session right now.
                @else
                    This session cannot be booked — the course has no mentor to book with.
                @endif
            </div>
        @elseif(! $block->allow_multiple)
            <p style="margin: 14px 0 0; font-size: 12px; color: #6B7280;">
                You have one session running on this block. Finish or cancel it to book another.
            </p>
        @endif
    @endif
</div>
