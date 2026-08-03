<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\WithPagination;
use App\Models\Course;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public bool $showCreateForm = false;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|string|max:255|unique:courses,slug')]
    public string $slug = '';

    public function updatedTitle($value)
    {
        $this->slug = \Illuminate\Support\Str::slug($value);
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    #[Computed]
    public function courses()
    {
        return Course::managedBy(auth()->user())
            ->withCount('modules')
            ->when($this->search, fn($q) => $q->where('title', 'like', '%' . $this->search . '%'))
            ->orderBy('title')
            ->paginate(12);
    }

    public function createCourse()
    {
        $this->validate();

        Course::create([
            'title' => $this->title,
            'slug'  => $this->slug,
            'created_by' => auth()->id(),
        ]);

        $this->reset(['title', 'slug', 'showCreateForm']);
        session()->flash('success', 'Course created successfully.');
    }

    public function deleteCourse($courseId)
    {
        Course::managedBy(auth()->user())->findOrFail($courseId)->delete();
        session()->flash('success', 'Course deleted.');
    }
}; ?>

<div style="display: flex; flex-direction: column; width: 100%; height: 100%; overflow-y: auto; background-color: #F9FAFB;">

    <!-- Header -->
    <div style="background: white; border-bottom: 1px solid #E5E7EB; padding: 28px 40px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 style="margin: 0 0 4px; font-size: 26px; font-weight: 800; color: #111827;">Courses</h1>
            <p style="margin: 0; font-size: 14px; color: #6B7280;">Manage all available courses on the platform.</p>
        </div>
        <button wire:click="$set('showCreateForm', true)"
                style="background-color: #2563EB; color: white; border: none; border-radius: 9px; padding: 11px 22px; font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 7px; transition: background-color 0.2s;"
                onmouseover="this.style.backgroundColor='#1D4ED8'" onmouseout="this.style.backgroundColor='#2563EB'">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            New Course
        </button>
    </div>

    <!-- Create form modal overlay -->
    @if($showCreateForm)
    <div style="position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 50; display: flex; align-items: center; justify-content: center;" wire:click.self="$set('showCreateForm', false)">
        <div style="background: white; border-radius: 16px; padding: 36px; width: 100%; max-width: 480px; box-shadow: 0 20px 60px rgba(0,0,0,0.2);" wire:click.stop>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <h2 style="margin: 0; font-size: 20px; font-weight: 700; color: #111827;">Create New Course</h2>
                <button wire:click="$set('showCreateForm', false)" style="background: none; border: none; cursor: pointer; color: #6B7280; padding: 4px;">
                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <form wire:submit="createCourse" style="display: flex; flex-direction: column; gap: 18px;">
                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Course Title</label>
                    <input wire:model.live="title" type="text" placeholder="e.g. Introduction to Web Development"
                           style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'">
                    @error('title') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Slug</label>
                    <input wire:model="slug" type="text" placeholder="auto-generated-from-title"
                           style="width: 100%; padding: 11px 14px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; outline: none; color: #6B7280; box-sizing: border-box;"
                           onfocus="this.style.borderColor='#2563EB'" onblur="this.style.borderColor='#D1D5DB'">
                    @error('slug') <span style="color: #EF4444; font-size: 12px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

                <div style="display: flex; gap: 12px; margin-top: 6px;">
                    <button type="button" wire:click="$set('showCreateForm', false)"
                            style="flex: 1; padding: 11px; border: 1px solid #D1D5DB; border-radius: 8px; background: white; font-size: 14px; font-weight: 600; color: #374151; cursor: pointer;">
                        Cancel
                    </button>
                    <button type="submit"
                            style="flex: 1; padding: 11px; border: none; border-radius: 8px; background: #2563EB; font-size: 14px; font-weight: 600; color: white; cursor: pointer;">
                        Create Course
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

        <!-- Search -->
        <div style="position: relative; max-width: 380px; margin-bottom: 24px;">
            <svg width="16" height="16" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="position: absolute; left: 13px; top: 50%; transform: translateY(-50%);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0"></path>
            </svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search courses..."
                   style="width: 100%; padding: 10px 14px 10px 38px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; background: white; outline: none; box-sizing: border-box;">
        </div>

        <!-- Grid -->
        @if($this->courses->isNotEmpty())
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px;">
                @foreach($this->courses as $course)
                    <div style="background: white; border: 1px solid #E5E7EB; border-radius: 14px; padding: 22px; display: flex; flex-direction: column; gap: 14px; transition: box-shadow 0.2s, border-color 0.2s;"
                         onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'; this.style.borderColor='#BFDBFE'"
                         onmouseout="this.style.boxShadow='none'; this.style.borderColor='#E5E7EB'">

                        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                            <div style="width: 44px; height: 44px; border-radius: 11px; background: linear-gradient(135deg, #EEF2FF, #E0E7FF); display: flex; align-items: center; justify-content: center; color: #4338CA;">
                                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                </svg>
                            </div>
                            <button wire:click="deleteCourse({{ $course->id }})" wire:confirm="Delete this course? This cannot be undone."
                                    style="background: none; border: none; cursor: pointer; color: #9CA3AF; padding: 4px; transition: color 0.2s;"
                                    onmouseover="this.style.color='#EF4444'" onmouseout="this.style.color='#9CA3AF'">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                </svg>
                            </button>
                        </div>

                        <div>
                            <h3 style="margin: 0 0 5px; font-size: 15px; font-weight: 700; color: #111827;">{{ $course->title }}</h3>
                            <p style="margin: 0; font-size: 12px; color: #6B7280;">Slug: {{ $course->slug }}</p>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #F3F4F6;">
                            <span style="font-size: 12px; color: #6B7280; display: flex; align-items: center; gap: 5px;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
                                {{ $course->modules_count }} module(s)
                            </span>
                            <a href="{{ route('course.show', $course->id) }}"
                               style="font-size: 12px; font-weight: 600; color: #2563EB; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                                View
                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination -->
            <div style="margin-top: 30px;">
                {{ $this->courses->links() }}
            </div>
        @else
            <div style="text-align: center; padding: 80px 20px; background: white; border: 1px dashed #D1D5DB; border-radius: 14px;">
                <svg width="48" height="48" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="margin: 0 auto 14px; display: block;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                </svg>
                <h3 style="font-size: 16px; font-weight: 600; color: #374151; margin: 0 0 8px;">No courses found</h3>
                <p style="font-size: 14px; color: #6B7280; margin: 0 0 20px;">
                    @if($search) No courses match "{{ $search }}". @else Get started by creating your first course. @endif
                </p>
                @if(!$search)
                    <button wire:click="$set('showCreateForm', true)"
                            style="background-color: #2563EB; color: white; border: none; border-radius: 8px; padding: 10px 20px; font-size: 14px; font-weight: 600; cursor: pointer;">
                        Create First Course
                    </button>
                @endif
            </div>
        @endif

    </div>
</div>
