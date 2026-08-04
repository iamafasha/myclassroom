<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use App\Models\Classroom;
use App\Models\User;
use App\Models\Course;

new #[Layout('layouts.app')] class extends Component {
    
    public Classroom $classroom;

    #[Validate('required|exists:users,id')]
    public ?int $attendee_id = null;

    public function mount(Classroom $classroom)
    {
        abort_unless($classroom->admin_id === auth()->id(), 403, 'You do not manage this class.');

        $this->classroom = $classroom;
    }

    #[Computed]
    public function availableUsers()
    {
        $existingUserIds = $this->classroom->users()->pluck('users.id')->toArray();
        return User::whereNotIn('id', $existingUserIds)->orderBy('name')->get();
    }

    #[Computed]
    public function attendees()
    {
        return $this->classroom->users()->orderBy('name')->get();
    }

    #[Computed]
    public function classCourses()
    {
        return $this->classroom->courses()->orderBy('title')->get();
    }

    public function addAttendee()
    {
        $this->validateOnly('attendee_id');
        $this->classroom->users()->attach($this->attendee_id);
        $this->reset('attendee_id');
        session()->flash('success_attendee', 'Attendee added successfully.');
    }

    public function removeAttendee($userId)
    {
        $this->classroom->users()->detach($userId);
        session()->flash('success_attendee', 'Attendee removed successfully.');
    }

    public function removeCourse($courseId)
    {
        $this->classroom->courses()->detach($courseId);
        session()->flash('success_course', 'Course removed successfully.');
    }

    /** Public classes are searchable and joinable by anyone under Discover. */
    public function toggleVisibility()
    {
        abort_unless($this->classroom->isAdministeredBy(auth()->user()), 403, 'You do not manage this class.');

        $this->classroom->is_public = ! $this->classroom->is_public;
        $this->classroom->save();

        session()->flash('success_visibility', $this->classroom->is_public
            ? 'Class is now public — anyone can find and join it.'
            : 'Class is now private — only people you add can join.');
    }

    /** Soft delete the class; attendees and courses stay attached. */
    public function deleteClassroom()
    {
        abort_unless($this->classroom->isAdministeredBy(auth()->user()), 403, 'You do not manage this class.');

        $this->classroom->delete();

        session()->flash('success', 'Class deleted successfully.');

        return $this->redirect(route('classes.index'), navigate: true);
    }
}; ?>

<div style="display: flex; flex-direction: column; width: 100%; height: 100%; overflow-y: auto; background-color: #F9FAFB;">
    
    <!-- Header -->
    <div style="background: white; border-bottom: 1px solid #E5E7EB; padding: 30px 40px;">
        <a href="{{ route('classes.index') }}" wire:navigate style="color: #4F46E5; text-decoration: none; font-weight: 500; font-size: 13px; display: inline-flex; align-items: center; margin-bottom: 15px;">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 4px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Back to Classes
        </a>
        <div style="display: flex; justify-content: space-between; align-items: flex-end;">
            <div>
                <h1 style="margin: 0 0 8px 0; font-size: 28px; font-weight: 800; color: #111827;">{{ $classroom->title }}</h1>
                <div style="display: flex; gap: 15px; font-size: 13px; color: #6B7280; align-items: center;">
                    <span style="display: flex; align-items: center; gap: 6px;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        Admin: <strong>{{ $classroom->admin->name ?? 'None' }}</strong>
                    </span>
                    <span style="display: flex; align-items: center; gap: 6px;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Created {{ $classroom->created_at->format('M j, Y') }}
                    </span>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <button type="button" wire:click="toggleVisibility"
                        title="{{ $classroom->is_public ? 'Make this class private' : 'Make this class public' }}"
                        class="badge"
                        style="display: inline-flex; align-items: center; gap: 6px; cursor: pointer; font-size: 12px; padding: 6px 12px;
                               background-color: {{ $classroom->is_public ? '#ECFDF5' : '#F3F4F6' }};
                               color: {{ $classroom->is_public ? '#047857' : '#4B5563' }};
                               border: 1px solid {{ $classroom->is_public ? '#A7F3D0' : '#E5E7EB' }};">
                    @if($classroom->is_public)
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0zM3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18a15 15 0 010-18z"></path>
                        </svg>
                        Public Class
                    @else
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        Private Class
                    @endif
                </button>
                <button type="button" wire:click="deleteClassroom"
                        wire:confirm="Delete &quot;{{ $classroom->title }}&quot;? Attendees will lose access to its courses."
                        style="display: inline-flex; align-items: center; gap: 6px; background: white; border: 1px solid #FECACA; color: #DC2626; font-size: 13px; font-weight: 600; padding: 8px 14px; border-radius: 8px; cursor: pointer; transition: background-color 0.2s;"
                        onmouseover="this.style.backgroundColor='#FEF2F2'" onmouseout="this.style.backgroundColor='white'">
                    <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    Delete Class
                </button>
            </div>
        </div>
    </div>

    @if (session('success_visibility'))
        <div style="margin: 20px 40px -20px; background-color: #ECFDF5; color: #065F46; padding: 12px 15px; border-radius: 8px; font-size: 13px; font-weight: 500; border: 1px solid #A7F3D0;">
            {{ session('success_visibility') }}
        </div>
    @endif

    <div style="padding: 40px; display: grid; grid-template-columns: 1fr 1fr; gap: 40px;">
        
        <!-- Attendees Section -->
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 18px; font-weight: 700; color: #111827;">Class Attendees ({{ $this->attendees->count() }})</h2>
            </div>
            
            <div style="background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 25px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                @if (session('success_attendee'))
                    <div style="background-color: #ECFDF5; color: #065F46; padding: 12px 15px; border-radius: 8px; font-size: 13px; font-weight: 500; margin-bottom: 20px; border: 1px solid #A7F3D0;">
                        {{ session('success_attendee') }}
                    </div>
                @endif
                
                <form wire:submit="addAttendee" style="display: flex; gap: 10px; margin-bottom: 30px;">
                    <div style="flex: 1;">
                        <select wire:model="attendee_id" class="select-styled" style="width: 100%;">
                            <option value="">Select a user to add...</option>
                            @foreach($this->availableUsers as $user)
                                <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                            @endforeach
                        </select>
                        @error('attendee_id') <span style="color: #EF4444; font-size: 11px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" class="btn-solve" style="background-color: #4F46E5; padding: 10px 20px;">Add</button>
                </form>

                <div style="display: flex; flex-direction: column; gap: 10px; max-height: 400px; overflow-y: auto;">
                    @forelse($this->attendees as $attendee)
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px; background: #F9FAFB; border: 1px solid #F3F4F6; border-radius: 8px;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="width: 36px; height: 36px; border-radius: 50%; background-color: #E0E7FF; display: flex; align-items: center; justify-content: center; color: #4338CA; font-weight: 700; font-size: 14px;">
                                    {{ strtoupper(substr($attendee->name, 0, 1)) }}
                                </div>
                                <div>
                                    <p style="margin: 0; font-weight: 600; font-size: 14px; color: #1F2937;">{{ $attendee->name }}</p>
                                    <p style="margin: 0; font-size: 12px; color: #6B7280;">{{ $attendee->email }}</p>
                                </div>
                            </div>
                            <button wire:click="removeAttendee({{ $attendee->id }})" wire:confirm="Are you sure you want to remove this attendee?" style="background: none; border: none; cursor: pointer; color: #EF4444; padding: 5px; opacity: 0.6; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>
                    @empty
                        <div style="text-align: center; padding: 30px; color: #9CA3AF; font-size: 13px;">
                            No attendees added yet.
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        <!-- Courses Section -->
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="margin: 0; font-size: 18px; font-weight: 700; color: #111827;">Class Courses ({{ $this->classCourses->count() }})</h2>
                <a href="{{ route('classes.courses.add', $classroom->id) }}" wire:navigate class="btn-solve" style="background-color: #10B981; text-decoration: none; padding: 10px 18px; font-size: 13px; display: inline-flex; align-items: center; gap: 6px; border-radius: 8px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    Add Course
                </a>
            </div>

            @if (session('success_course'))
                <div style="background-color: #ECFDF5; color: #065F46; padding: 12px 15px; border-radius: 8px; font-size: 13px; font-weight: 500; margin-bottom: 15px; border: 1px solid #A7F3D0;">
                    {{ session('success_course') }}
                </div>
            @endif
            
            <div style="background: white; border: 1px solid #E5E7EB; border-radius: 12px; padding: 25px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                <div style="display: flex; flex-direction: column; gap: 10px; max-height: 400px; overflow-y: auto;">
                    @forelse($this->classCourses as $course)
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 15px; background: #F9FAFB; border: 1px solid #F3F4F6; border-radius: 8px;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div style="width: 36px; height: 36px; border-radius: 8px; background-color: #D1FAE5; display: flex; align-items: center; justify-content: center; color: #059669;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                    </svg>
                                </div>
                                <div>
                                    <p style="margin: 0; font-weight: 600; font-size: 14px; color: #1F2937;">{{ $course->title }}</p>
                                    <p style="margin: 0; font-size: 12px; color: #6B7280;">{{ $course->modules()->count() }} Modules</p>
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <a href="{{ route('course.show', $course->id) }}" style="color: #4F46E5; font-size: 12px; font-weight: 600; text-decoration: none;">View</a>
                                <button wire:click="removeCourse({{ $course->id }})" wire:confirm="Are you sure you want to remove this course from the class?" style="background: none; border: none; cursor: pointer; color: #EF4444; padding: 5px; opacity: 0.6; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    @empty
                        <div style="text-align: center; padding: 30px; color: #9CA3AF; font-size: 13px;">
                            No courses added yet. <a href="{{ route('classes.courses.add', $classroom->id) }}" wire:navigate style="color: #10B981; font-weight: 600;">Add one now →</a>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

    </div>
</div>
