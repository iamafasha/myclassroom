<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use App\Models\Course;
use App\Models\MentorSession;

new #[Layout('layouts.app')] class extends Component {
    /** 'mine' = sessions I asked for, 'incoming' = sessions asked of me. */
    #[Url]
    public string $tab = 'mine';

    public bool $showRequestForm = false;

    // Request form (student side).
    public $courseId = '';
    public string $topic = '';
    public string $message = '';
    public string $preferredAt = '';
    public int $durationMinutes = 30;

    // Reply form (mentor side): a shortlist of times for the student to choose from.
    public ?int $respondingId = null;
    public array $slotInputs = [''];
    public string $meetingLink = '';
    public string $mentorNote = '';

    /** Slot the student has picked in the UI but not confirmed yet, keyed by session id. */
    public array $selectedSlots = [];

    /** ?course=12 opens the page with the request form ready for that course. */
    public function mount($course = null)
    {
        if ($course && Course::sessionRequestableBy(auth()->user())->whereKey($course)->exists()) {
            $this->courseId = (int) $course;
            $this->showRequestForm = true;
        }
    }

    #[Computed]
    public function requestableCourses()
    {
        return Course::sessionRequestableBy(auth()->user())->with('creator')->orderBy('title')->get();
    }

    #[Computed]
    public function myRequests()
    {
        return MentorSession::forStudent(auth()->user())
            ->with(['course', 'mentor'])
            ->orderByRaw("CASE WHEN status IN ('proposed', 'pending', 'scheduled') THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(scheduled_at, preferred_at, created_at) DESC')
            ->get();
    }

    #[Computed]
    public function incoming()
    {
        return MentorSession::forMentor(auth()->user())
            ->with(['course', 'student'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status IN ('proposed', 'scheduled') THEN 1 ELSE 2 END")
            ->orderByRaw('COALESCE(scheduled_at, preferred_at, created_at) ASC')
            ->get();
    }

    #[Computed]
    public function pendingIncomingCount()
    {
        return MentorSession::forMentor(auth()->user())->pending()->count();
    }

    /** Sessions where the mentor has offered times and the student still has to choose. */
    #[Computed]
    public function awaitingMyChoiceCount()
    {
        return MentorSession::forStudent(auth()->user())->awaitingStudent()->count();
    }

    public function openRequestForm()
    {
        $this->resetValidation();
        $this->reset(['topic', 'message', 'preferredAt', 'durationMinutes']);
        $this->showRequestForm = true;
    }

    public function requestSession()
    {
        $this->validate([
            'courseId' => 'required|integer',
            'topic' => 'required|string|max:255',
            'message' => 'nullable|string|max:2000',
            'preferredAt' => 'nullable|date|after:now',
            'durationMinutes' => 'required|integer|min:15|max:240',
        ], [
            'courseId.required' => 'Pick the course you want the session for.',
            'preferredAt.after' => 'Pick a time in the future.',
        ]);

        // Re-check ownership at write time: the picker is only a hint.
        $course = Course::sessionRequestableBy(auth()->user())->findOrFail($this->courseId);

        MentorSession::create([
            'course_id' => $course->id,
            'student_id' => auth()->id(),
            'mentor_id' => $course->created_by,
            'topic' => $this->topic,
            'message' => $this->message ?: null,
            'preferred_at' => $this->preferredAt ?: null,
            'duration_minutes' => $this->durationMinutes,
            'status' => MentorSession::STATUS_PENDING,
        ]);

        $this->reset(['showRequestForm', 'topic', 'message', 'preferredAt', 'durationMinutes']);
        unset($this->myRequests);
        $this->tab = 'mine';
        session()->flash('success', 'Session requested. ' . $course->creator?->name . ' will get back to you with times.');
    }

    public function cancelRequest($sessionId)
    {
        $session = MentorSession::forStudent(auth()->user())->findOrFail($sessionId);

        abort_unless($session->isOpen(), 403, 'This session can no longer be cancelled.');

        $session->update(['status' => MentorSession::STATUS_CANCELLED]);

        unset($this->myRequests, $this->awaitingMyChoiceCount);
        session()->flash('success', 'Session cancelled.');
    }

    /** The student books the session by picking one of the times the mentor offered. */
    public function confirmSlot($sessionId)
    {
        $session = MentorSession::forStudent(auth()->user())->findOrFail($sessionId);

        abort_unless($session->isProposed(), 403, 'There are no times to pick for this session.');

        $chosen = $this->selectedSlots[$sessionId] ?? null;

        if (!$chosen || !$session->hasSlot($chosen)) {
            $this->addError('selectedSlots.' . $sessionId, 'Pick one of the times offered.');

            return;
        }

        $session->update([
            'status' => MentorSession::STATUS_SCHEDULED,
            'scheduled_at' => $chosen,
        ]);

        unset($this->selectedSlots[$sessionId]);
        unset($this->myRequests, $this->awaitingMyChoiceCount);
        session()->flash('success', 'Session booked for ' . $session->scheduled_at->format('D j M, H:i') . '.');
    }

    /** Opens the panel where the mentor offers times, prefilled with what is known so far. */
    public function respondTo($sessionId)
    {
        $session = MentorSession::forMentor(auth()->user())->findOrFail($sessionId);

        $this->resetValidation();
        $this->respondingId = $session->id;
        $this->meetingLink = (string) $session->meeting_link;
        $this->mentorNote = (string) $session->mentor_note;

        $existing = $session->slots()->map(fn ($slot) => $slot->format('Y-m-d\TH:i'))->all();

        // Nothing offered yet: start from the time the student asked for.
        $this->slotInputs = $existing ?: [optional($session->preferred_at)->format('Y-m-d\TH:i') ?? ''];
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
        $session = MentorSession::forMentor(auth()->user())->findOrFail($this->respondingId);

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
            // Rescheduling clears the old booking; the student picks again.
            'scheduled_at' => null,
            'meeting_link' => $this->meetingLink ?: null,
            'mentor_note' => $this->mentorNote ?: null,
        ]);

        $this->reset(['respondingId', 'slotInputs', 'meetingLink', 'mentorNote']);
        unset($this->incoming, $this->pendingIncomingCount);
        session()->flash('success', 'Times sent. The student will pick one of them.');
    }

    public function declineSession($sessionId = null)
    {
        $session = MentorSession::forMentor(auth()->user())->findOrFail($sessionId ?? $this->respondingId);

        abort_unless($session->isOpen(), 403, 'This session is closed.');

        $session->update([
            'status' => MentorSession::STATUS_DECLINED,
            'mentor_note' => ($sessionId === null && $this->mentorNote !== '') ? $this->mentorNote : $session->mentor_note,
        ]);

        $this->reset(['respondingId', 'slotInputs', 'meetingLink', 'mentorNote']);
        unset($this->incoming, $this->pendingIncomingCount);
        session()->flash('success', 'Session declined.');
    }

    public function completeSession($sessionId)
    {
        $session = MentorSession::forMentor(auth()->user())->findOrFail($sessionId);

        abort_unless($session->isScheduled(), 403, 'Only a booked session can be marked done.');

        $session->update(['status' => MentorSession::STATUS_COMPLETED]);

        unset($this->incoming, $this->pendingIncomingCount);
        session()->flash('success', 'Session marked as completed.');
    }
}; ?>

<div style="display: flex; flex-direction: column; width: 100%; height: 100%; overflow-y: auto; background-color: #F9FAFB;">

    <!-- Header -->
    <div style="background: white; border-bottom: 1px solid #E5E7EB; padding: 28px 40px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="margin: 0 0 4px; font-size: 26px; font-weight: 800; color: #111827;">Sessions</h1>
            <p style="margin: 0; font-size: 14px; color: #6B7280;">One-to-one time with the mentor who owns a course.</p>
        </div>
        <button wire:click="openRequestForm"
                style="background-color: #2563EB; color: white; border: none; border-radius: 9px; padding: 11px 22px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 7px; transition: background-color 0.2s;"
                onmouseover="this.style.backgroundColor='#1D4ED8'" onmouseout="this.style.backgroundColor='#2563EB'">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Request Session
        </button>
    </div>

    <!-- Request form modal (student) -->
    @if($showRequestForm)
    <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 50; display: flex; align-items: center; justify-content: center; padding: 20px;" wire:click.self="$set('showRequestForm', false)">
        <div style="background: white; border-radius: 16px; padding: 36px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2);" wire:click.stop>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #111827;">Request a Session</h2>
                <button wire:click="$set('showRequestForm', false)" style="background: none; border: none; cursor: pointer; color: #6B7280; padding: 4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            @if($this->requestableCourses->isEmpty())
                <p style="margin: 0 0 22px; font-size: 14px; color: #6B7280; line-height: 1.6;">
                    You are not enrolled in any course taught by someone else yet. Join a class first, then come back to book time with its mentor.
                </p>
                <a href="{{ route('classes.index') }}" wire:navigate
                   style="display: inline-block; padding: 11px 20px; border-radius: 8px; background: #2563EB; font-size: 14px; font-weight: 600; color: white; text-decoration: none;">
                    Browse classes
                </a>
            @else
                <form wire:submit="requestSession" style="display: flex; flex-direction: column; gap: 18px;">
                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Course</label>
                        <select wire:model="courseId" class="select-styled" style="background: white;">
                            <option value="">Select a course...</option>
                            @foreach($this->requestableCourses as $course)
                                <option value="{{ $course->id }}">{{ $course->title }} — {{ $course->creator?->name }}</option>
                            @endforeach
                        </select>
                        @error('courseId') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">What do you want to cover?</label>
                        <input wire:model="topic" type="text" placeholder="e.g. Review my quiz answers on loops"
                               style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;"
                               onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'">
                        @error('topic') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Details <span style="font-weight: 400; color: #9CA3AF;">(optional)</span></label>
                        <textarea wire:model="message" rows="3" placeholder="Anything the mentor should prepare or look at beforehand."
                                  style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; font-family: inherit; resize: vertical;"
                                  onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'"></textarea>
                        @error('message') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                    </div>

                    <div style="display: flex; gap: 14px;">
                        <div style="flex: 2;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Preferred time <span style="font-weight: 400; color: #9CA3AF;">(optional)</span></label>
                            <input wire:model="preferredAt" type="datetime-local"
                                   style="width: 100%; padding: 10px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                            @error('preferredAt') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                        </div>
                        <div style="flex: 1;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Length</label>
                            <select wire:model="durationMinutes" class="select-styled" style="background: white;">
                                <option value="15">15 min</option>
                                <option value="30">30 min</option>
                                <option value="45">45 min</option>
                                <option value="60">1 hour</option>
                                <option value="90">1.5 hours</option>
                            </select>
                            @error('durationMinutes') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <p style="margin: 0; font-size: 12px; color: #9CA3AF; line-height: 1.5;">
                        The mentor will reply with a few times to choose from. You pick the one that suits you.
                    </p>

                    <div style="display: flex; gap: 12px;">
                        <button type="button" wire:click="$set('showRequestForm', false)"
                                style="flex: 1; padding: 11px; border: 1px solid #D1D5DB; border-radius: 8px; background: white; font-size: 14px; font-weight: 600; color: #374151; cursor: pointer;">
                            Cancel
                        </button>
                        <button type="submit"
                                style="flex: 1; padding: 11px; border: none; border-radius: 8px; background: #2563EB; font-size: 14px; font-weight: 600; color: white; cursor: pointer;">
                            Send Request
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>
    @endif

    <!-- Offer-times modal (mentor) -->
    @if($respondingId)
    @php($responding = $this->incoming->firstWhere('id', $respondingId))
    <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 50; display: flex; align-items: center; justify-content: center; padding: 20px;" wire:click.self="$set('respondingId', null)">
        <div style="background: white; border-radius: 16px; padding: 36px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,0.2);" wire:click.stop>
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
                <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #111827;">Offer Times</h2>
                <button wire:click="$set('respondingId', null)" style="background: none; border: none; cursor: pointer; color: #6B7280; padding: 4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            @if($responding)
                <p style="margin: 0 0 6px; font-size: 13px; color: #6B7280;">
                    {{ $responding->student?->name }} · {{ $responding->course?->title }} · {{ $responding->duration_minutes }} min
                    @if($responding->preferred_at) · asked for {{ $responding->preferred_at->format('D j M, H:i') }} @endif
                </p>
            @endif
            <p style="margin: 0 0 24px; font-size: 12px; color: #9CA3AF;">Give up to five slots. The student picks one and that books the session.</p>

            <form wire:submit="proposeSlots" style="display: flex; flex-direction: column; gap: 18px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Available times</label>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        @foreach($slotInputs as $index => $slot)
                            <div wire:key="slot-{{ $index }}">
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input wire:model="slotInputs.{{ $index }}" type="datetime-local"
                                           style="flex: 1; padding: 10px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;">
                                    @if(count($slotInputs) > 1)
                                        <button type="button" wire:click="removeSlot({{ $index }})" title="Remove this time"
                                                style="background: none; border: none; cursor: pointer; color: #9CA3AF; padding: 6px; display: flex;">
                                            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
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
                                style="margin-top: 10px; background: none; border: 1px dashed #93C5FD; color: #2563EB; border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer;">
                            + Add another time
                        </button>
                    @endif
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Meeting link <span style="font-weight: 400; color: #9CA3AF;">(optional)</span></label>
                    <input wire:model="meetingLink" type="url" placeholder="https://meet.google.com/..."
                           style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'">
                    @error('meetingLink') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Note to student <span style="font-weight: 400; color: #9CA3AF;">(optional)</span></label>
                    <textarea wire:model="mentorNote" rows="3" placeholder="Bring your latest exercise, or tell them what to prepare."
                              style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; box-sizing: border-box; font-family: inherit; resize: vertical;"
                              onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'"></textarea>
                    @error('mentorNote') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

                <div style="display: flex; gap: 12px; margin-top: 6px;">
                    <button type="button" wire:click="declineSession" wire:confirm="Decline this session request?"
                            style="flex: 1; padding: 11px; border: 1px solid #FECACA; border-radius: 8px; background: white; font-size: 14px; font-weight: 600; color: #B91C1C; cursor: pointer;">
                        Decline
                    </button>
                    <button type="submit"
                            style="flex: 1; padding: 11px; border: none; border-radius: 8px; background: #2563EB; font-size: 14px; font-weight: 600; color: white; cursor: pointer;">
                        Send Times
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <div style="padding: 30px 40px;">

        @if (session('success'))
            <div style="background-color: #ECFDF5; color: #065F46; padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 500; margin-bottom: 20px; border: 1px solid #A7F3D0;">
                {{ session('success') }}
            </div>
        @endif

        <!-- Tabs -->
        <div class="tabs-container" style="margin-top: 0;">
            <div wire:click="$set('tab', 'mine')" class="tab-item {{ $tab === 'mine' ? 'active' : '' }}" style="display: flex; align-items: center; gap: 7px;">
                My Requests ({{ $this->myRequests->count() }})
                @if($this->awaitingMyChoiceCount > 0)
                    <span style="min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px; background: #7C3AED; color: white; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;">
                        {{ $this->awaitingMyChoiceCount }}
                    </span>
                @endif
            </div>
            <div wire:click="$set('tab', 'incoming')" class="tab-item {{ $tab === 'incoming' ? 'active' : '' }}" style="display: flex; align-items: center; gap: 7px;">
                Requests To Me ({{ $this->incoming->count() }})
                @if($this->pendingIncomingCount > 0)
                    <span style="min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px; background: #EF4444; color: white; font-size: 10px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;">
                        {{ $this->pendingIncomingCount }}
                    </span>
                @endif
            </div>
        </div>

        @php($sessions = $tab === 'mine' ? $this->myRequests : $this->incoming)

        @if($sessions->isNotEmpty())
            <div style="display: flex; flex-direction: column; gap: 14px;">
                @foreach($sessions as $session)
                    @php([$pillBg, $pillText] = $session->statusColors())
                    <div wire:key="session-{{ $session->id }}" style="background: white; border: 1px solid {{ $tab === 'mine' && $session->isProposed() ? '#DDD6FE' : '#E5E7EB' }}; border-radius: 14px; padding: 22px; display: flex; flex-direction: column; gap: 14px;">

                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
                            <div>
                                <h3 style="margin: 0 0 6px; font-size: 15px; font-weight: 700; color: #111827;">{{ $session->topic }}</h3>
                                <p style="margin: 0; font-size: 12px; color: #6B7280;">
                                    {{ $session->course?->title ?? 'Course removed' }}
                                    ·
                                    @if($tab === 'mine')
                                        with {{ $session->mentor?->name ?? 'mentor' }}
                                    @else
                                        from {{ $session->student?->name ?? 'student' }}
                                    @endif
                                </p>
                            </div>
                            <span style="flex-shrink: 0; padding: 4px 10px; border-radius: 9999px; font-size: 11px; font-weight: 700; background: {{ $pillBg }}; color: {{ $pillText }};">
                                {{ $session->statusLabel() }}
                            </span>
                        </div>

                        @if($session->message)
                            <p style="margin: 0; font-size: 13px; color: #4B5563; line-height: 1.6; white-space: pre-line;">{{ $session->message }}</p>
                        @endif

                        @if($session->mentor_note)
                            <div style="background: #F9FAFB; border-left: 3px solid #D1D5DB; padding: 10px 14px; border-radius: 0 8px 8px 0;">
                                <div style="font-size: 11px; font-weight: 700; color: #6B7280; text-transform: uppercase; margin-bottom: 4px;">Mentor note</div>
                                <p style="margin: 0; font-size: 13px; color: #374151; line-height: 1.6; white-space: pre-line;">{{ $session->mentor_note }}</p>
                            </div>
                        @endif

                        <!-- Student picks one of the offered times. -->
                        @if($tab === 'mine' && $session->isProposed())
                            @if($session->slots()->isEmpty())
                                <div style="background: #FFFBEB; border: 1px solid #FEF3C7; border-radius: 10px; padding: 14px; font-size: 13px; color: #92400E;">
                                    Every time {{ $session->mentor?->name ?? 'the mentor' }} offered has now passed. Cancel and request again, or wait for new times.
                                </div>
                            @else
                                <div style="background: #FAF5FF; border: 1px solid #EDE9FE; border-radius: 10px; padding: 16px;">
                                    <div style="font-size: 12px; font-weight: 700; color: #5B21B6; text-transform: uppercase; margin-bottom: 12px;">Pick a time that works for you</div>
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        @foreach($session->slots() as $slot)
                                            @php($value = $slot->format('Y-m-d H:i:00'))
                                            <label style="display: flex; align-items: center; gap: 10px; background: white; border: 1px solid {{ ($selectedSlots[$session->id] ?? null) === $value ? '#7C3AED' : '#E5E7EB' }}; border-radius: 8px; padding: 11px 14px; cursor: pointer;">
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

                        <!-- Mentor sees the times still on the table. -->
                        @if($tab === 'incoming' && $session->isProposed())
                            <div style="font-size: 12px; color: #6B7280;">
                                <span style="font-weight: 700; color: #5B21B6;">Waiting on {{ $session->student?->name ?? 'the student' }}</span>
                                · offered {{ $session->slots()->map(fn ($slot) => $slot->format('D j M, H:i'))->implode(' · ') ?: 'no times left' }}
                            </div>
                        @endif

                        <div style="display: flex; flex-wrap: wrap; gap: 18px; align-items: center; font-size: 12px; color: #6B7280;">
                            <span style="display: flex; align-items: center; gap: 5px;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                @if($session->displayAt())
                                    {{ $session->displayAt()->format('D j M Y, H:i') }}
                                    @if(!$session->scheduled_at) <span style="color: #9CA3AF;">(requested)</span> @endif
                                @else
                                    No time proposed
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

                            @if($tab === 'incoming')
                                @if($session->isOpen())
                                    <button wire:click="respondTo({{ $session->id }})"
                                            style="padding: 8px 16px; border-radius: 8px; border: none; background: {{ $session->isPending() ? '#111827' : '#F3F4F6' }}; color: {{ $session->isPending() ? 'white' : '#374151' }}; font-size: 13px; font-weight: 600; cursor: pointer;">
                                        {{ $session->isPending() ? 'Offer times' : 'Change times' }}
                                    </button>
                                @endif
                                @if($session->isPending() || $session->isProposed())
                                    <button wire:click="declineSession({{ $session->id }})" wire:confirm="Decline this session request?"
                                            style="padding: 8px 16px; border-radius: 8px; border: 1px solid #FECACA; background: white; color: #B91C1C; font-size: 13px; font-weight: 600; cursor: pointer;">
                                        Decline
                                    </button>
                                @endif
                                @if($session->isScheduled())
                                    <button wire:click="completeSession({{ $session->id }})"
                                            style="padding: 8px 16px; border-radius: 8px; border: 1px solid #A7F3D0; background: white; color: #047857; font-size: 13px; font-weight: 600; cursor: pointer;">
                                        Mark completed
                                    </button>
                                @endif
                            @elseif($session->isOpen())
                                <button wire:click="cancelRequest({{ $session->id }})" wire:confirm="Cancel this session?"
                                        style="padding: 8px 16px; border-radius: 8px; border: 1px solid #D1D5DB; background: white; color: #4B5563; font-size: 13px; font-weight: 600; cursor: pointer;">
                                    Cancel request
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div style="text-align: center; padding: 80px 20px; background: white; border: 1px dashed #D1D5DB; border-radius: 14px;">
                <svg width="48" height="48" fill="none" stroke="#9CA3AF" stroke-width="1.5" viewBox="0 0 24 24" style="margin: 0 auto 14px; display: block;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <h3 style="font-size: 16px; font-weight: 600; color: #374151; margin: 0 0 8px;">
                    {{ $tab === 'mine' ? 'No sessions requested yet' : 'No one has asked for a session yet' }}
                </h3>
                <p style="font-size: 14px; color: #6B7280; margin: 0 0 20px;">
                    {{ $tab === 'mine'
                        ? 'Book one-to-one time with the mentor of any course you are taking.'
                        : 'When a student on one of your courses requests a session, it lands here.' }}
                </p>
                @if($tab === 'mine')
                    <button wire:click="openRequestForm"
                            style="background-color: #2563EB; color: white; border: none; border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer;">
                        Request a Session
                    </button>
                @endif
            </div>
        @endif

    </div>
</div>
