<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use App\Models\Classroom;
use App\Models\Course;

new #[Layout('layouts.app')] class extends Component {

    public Classroom $classroom;
    public string $search = '';

    public function mount(Classroom $classroom)
    {
        abort_unless($classroom->admin_id === auth()->id(), 403, 'You do not manage this class.');

        $this->classroom = $classroom;
    }

    #[Computed]
    public function courses()
    {
        $existingCourseIds = $this->classroom->courses()->pluck('courses.id')->toArray();

        return Course::managedBy(auth()->user())
            ->whereNotIn('id', $existingCourseIds)
            ->when($this->search, fn($q) => $q->where('title', 'like', '%' . $this->search . '%'))
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function addedCourses()
    {
        return $this->classroom->courses()->orderBy('title')->get();
    }

    public function add($courseId)
    {
        abort_unless(Course::managedBy(auth()->user())->whereKey($courseId)->exists(), 403, 'You do not own this course.');

        $this->classroom->courses()->attach($courseId);
        session()->flash('success', 'Course added to class successfully.');
    }

    public function remove($courseId)
    {
        $this->classroom->courses()->detach($courseId);
        session()->flash('success', 'Course removed from class.');
    }
}; ?>

<div style="display: flex; flex-direction: column; width: 100%; height: 100%; overflow-y: auto; background-color: #F9FAFB;">

    <!-- Header -->
    <div style="background: white; border-bottom: 1px solid #E5E7EB; padding: 25px 40px;">
        <a href="{{ route('classes.show', $classroom->id) }}" wire:navigate style="color: #4F46E5; text-decoration: none; font-weight: 500; font-size: 13px; display: inline-flex; align-items: center; margin-bottom: 12px;">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 4px;">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Back to {{ $classroom->title }}
        </a>
        <h1 style="margin: 0; font-size: 24px; font-weight: 800; color: #111827;">Add Courses to Class</h1>
        <p style="margin: 6px 0 0; font-size: 14px; color: #6B7280;">Add courses you own to <strong>{{ $classroom->title }}</strong>.</p>
    </div>

    @if (session('success'))
        <div style="margin: 20px 40px 0; background-color: #ECFDF5; color: #065F46; padding: 12px 15px; border-radius: 8px; font-size: 13px; font-weight: 500; border: 1px solid #A7F3D0;">
            {{ session('success') }}
        </div>
    @endif

    <div style="padding: 30px 40px; display: grid; grid-template-columns: 1fr 380px; gap: 30px; align-items: start;">

        <!-- Left: Available courses list -->
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h2 style="margin: 0; font-size: 16px; font-weight: 700; color: #111827;">Available Courses</h2>
                <span style="font-size: 12px; color: #6B7280;">{{ $this->courses->count() }} course(s) found</span>
            </div>

            <!-- Search -->
            <div style="position: relative; margin-bottom: 20px;">
                <svg width="16" height="16" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"></path>
                </svg>
                <input wire:model.live="search" type="text" placeholder="Search courses..."
                       style="width: 100%; padding: 10px 12px 10px 36px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 13px; background: white; outline: none;">
            </div>

            <!-- Course list -->
            <div style="display: flex; flex-direction: column; gap: 12px;">
                @forelse($this->courses as $course)
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 18px 20px; background: white; border: 1px solid #E5E7EB; border-radius: 12px; transition: box-shadow 0.2s;"
                         onmouseover="this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'"
                         onmouseout="this.style.boxShadow='none'">
                        <div style="display: flex; align-items: center; gap: 14px;">
                            <div style="width: 42px; height: 42px; border-radius: 10px; background: linear-gradient(135deg, #EEF2FF, #E0E7FF); display: flex; align-items: center; justify-content: center; color: #4338CA; flex-shrink: 0;">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                </svg>
                            </div>
                            <div>
                                <p style="margin: 0; font-weight: 600; font-size: 15px; color: #111827;">{{ $course->title }}</p>
                                <p style="margin: 4px 0 0; font-size: 12px; color: #6B7280;">{{ $course->modules()->count() }} modules</p>
                            </div>
                        </div>
                        <button wire:click="add({{ $course->id }})"
                                style="background-color: #10B981; color: white; border: none; border-radius: 8px; padding: 9px 18px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 5px; transition: background-color 0.2s;"
                                onmouseover="this.style.backgroundColor='#059669'"
                                onmouseout="this.style.backgroundColor='#10B981'">
                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                            Add
                        </button>
                    </div>
                @empty
                    <div style="text-align: center; padding: 60px 20px; background: white; border: 1px dashed #D1D5DB; border-radius: 12px;">
                        <svg width="40" height="40" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="margin: 0 auto 10px; display: block;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                        </svg>
                        <p style="font-size: 14px; color: #6B7280; margin: 0;">
                            @if($search) None of your courses match "{{ $search }}". @else All of your courses have been added to this class. @endif
                        </p>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Right: Already added courses -->
        <div style="position: sticky; top: 20px;">
            <h2 style="margin: 0 0 16px; font-size: 16px; font-weight: 700; color: #111827;">
                Added to Class
                <span style="font-size: 13px; font-weight: 500; color: #6B7280;">({{ $this->addedCourses->count() }})</span>
            </h2>
            <div style="background: white; border: 1px solid #E5E7EB; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                @forelse($this->addedCourses as $course)
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; {{ !$loop->last ? 'border-bottom: 1px solid #F3F4F6;' : '' }}">
                        <div>
                            <p style="margin: 0; font-size: 13px; font-weight: 600; color: #1F2937;">{{ $course->title }}</p>
                            <p style="margin: 3px 0 0; font-size: 11px; color: #6B7280;">{{ $course->modules()->count() }} modules</p>
                        </div>
                        <button wire:click="remove({{ $course->id }})" wire:confirm="Remove this course from the class?"
                                style="background: none; border: none; cursor: pointer; color: #EF4444; padding: 4px; opacity: 0.6; transition: opacity 0.2s;"
                                onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'">
                            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                @empty
                    <div style="padding: 30px 20px; text-align: center; color: #9CA3AF; font-size: 13px;">
                        No courses added yet.
                    </div>
                @endforelse
            </div>
        </div>

    </div>
</div>
