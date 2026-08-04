<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Attributes\Url;
use App\Models\Classroom;

new #[Layout('layouts.app')] class extends Component {

    #[Validate('required|string|max:255')]
    public string $title = '';

    /** Which list is on screen: 'mine' (classes I administer) or 'attending'. */
    #[Url]
    public string $tab = 'mine';

    public function selectTab(string $tab)
    {
        $this->tab = in_array($tab, ['mine', 'attending']) ? $tab : 'mine';
    }

    #[Computed]
    public function classrooms()
    {
        return Classroom::with('admin')
            ->withCount(['courses', 'users'])
            ->where('admin_id', auth()->id())
            ->latest()
            ->get();
    }

    /** Classes someone else administers that I was added to as an attendee. */
    #[Computed]
    public function attendingClassrooms()
    {
        return Classroom::with(['admin', 'courses'])
            ->whereHas('users', fn ($q) => $q->where('users.id', auth()->id()))
            ->where('admin_id', '!=', auth()->id())
            ->latest()
            ->get();
    }

    public function createClassroom()
    {
        $this->validate();

        Classroom::create([
            'title' => $this->title,
            'admin_id' => auth()->id(),
        ]);

        $this->reset(['title']);
        $this->tab = 'mine';
        unset($this->classrooms);
        session()->flash('success', 'Classroom created successfully.');
    }

    /** Soft delete a class I administer; attendees and courses stay attached. */
    public function deleteClassroom(int $classroomId)
    {
        $classroom = Classroom::findOrFail($classroomId);

        abort_unless($classroom->isAdministeredBy(auth()->user()), 403, 'You do not manage this class.');

        $classroom->delete();

        unset($this->classrooms);
        session()->flash('success', 'Class deleted successfully.');
    }

    /** Step out of a class someone else administers. */
    public function leaveClassroom(int $classroomId)
    {
        $classroom = Classroom::findOrFail($classroomId);

        abort_if($classroom->isAdministeredBy(auth()->user()), 403, 'Admins cannot leave their own class.');
        abort_unless($classroom->users()->where('users.id', auth()->id())->exists(), 403, 'You are not in this class.');

        $classroom->users()->detach(auth()->id());

        unset($this->attendingClassrooms);
        session()->flash('success', 'You have left the class.');
    }
}; ?>

<div style="display: flex; width: 100%; height: 100%; overflow: hidden;">
    <!-- Form Panel -->
    <div class="panel-list" style="width: 350px; padding: 40px 20px; overflow-y: auto; background-color: #F9FAFB; border-right: 1px solid #E5E7EB; flex-shrink: 0;">
        <div class="content-header" style="margin-bottom: 30px;">
            <h1 class="content-title" style="font-size: 20px;">Classes Management</h1>
            <p style="color: var(--text-secondary); font-size: 13px; margin-top: 5px;">Create and manage the classrooms you administer.</p>
        </div>

        @if (session('success'))
            <div style="background-color: #ECFDF5; color: #065F46; padding: 12px 15px; border-radius: 8px; font-size: 13px; font-weight: 500; margin-bottom: 20px; border: 1px solid #A7F3D0;">
                {{ session('success') }}
            </div>
        @endif

        <div class="progress-card" style="padding: 20px;">
            <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 14px; color: #111827;">Add New Class</h3>
            
            <form wire:submit="createClassroom" style="display: flex; flex-direction: column; gap: 15px;">
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #4B5563; margin-bottom: 5px;">Class Title</label>
                    <input type="text" wire:model="title" placeholder="e.g. Computer Science 101" 
                           style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #D1D5DB; font-size: 13px; outline: none; transition: border-color 0.2s;">
                    @error('title') <span style="color: #EF4444; font-size: 11px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

                <p style="margin: 0; font-size: 12px; color: #6B7280;">
                    You will be the admin of this class.
                </p>

                <button type="submit" class="btn-solve" style="justify-content: center; width: 100%; margin-top: 10px; padding: 12px; background-color: #2563EB;">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin-right: 4px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    Create Class
                </button>
            </form>
        </div>
    </div>

    <!-- List Panel -->
    <div style="flex: 1; padding: 40px; overflow-y: auto; background-color: #ffffff;">
        <!-- Tabs -->
        <div style="display: flex; gap: 4px; border-bottom: 1px solid #E5E7EB; margin-bottom: 24px;">
            <button type="button" wire:click="selectTab('mine')"
                    style="background: none; border: none; border-bottom: 2px solid {{ $tab === 'mine' ? '#2563EB' : 'transparent' }}; padding: 10px 16px; margin-bottom: -1px; font-size: 14px; font-weight: 600; cursor: pointer; color: {{ $tab === 'mine' ? '#2563EB' : '#6B7280' }}; display: flex; align-items: center; gap: 8px; transition: color 0.2s;">
                My Classes
                <span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: {{ $tab === 'mine' ? '#EFF6FF' : '#F3F4F6' }}; color: {{ $tab === 'mine' ? '#2563EB' : '#6B7280' }};">{{ $this->classrooms->count() }}</span>
            </button>
            <button type="button" wire:click="selectTab('attending')"
                    style="background: none; border: none; border-bottom: 2px solid {{ $tab === 'attending' ? '#2563EB' : 'transparent' }}; padding: 10px 16px; margin-bottom: -1px; font-size: 14px; font-weight: 600; cursor: pointer; color: {{ $tab === 'attending' ? '#2563EB' : '#6B7280' }}; display: flex; align-items: center; gap: 8px; transition: color 0.2s;">
                Classes I'm In
                <span style="font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 9999px; background: {{ $tab === 'attending' ? '#EFF6FF' : '#F3F4F6' }}; color: {{ $tab === 'attending' ? '#2563EB' : '#6B7280' }};">{{ $this->attendingClassrooms->count() }}</span>
            </button>
        </div>

        @if($tab === 'attending')
            <!-- Classes I am an attendee of -->
            @if($this->attendingClassrooms->isEmpty())
                <div style="text-align: center; padding: 60px 20px; background: #F9FAFB; border-radius: 12px; border: 1px dashed #D1D5DB;">
                    <svg width="40" height="40" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="margin: 0 auto 10px;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                    </svg>
                    <h3 style="font-size: 14px; font-weight: 600; color: #4B5563; margin-bottom: 5px;">Not In Any Class</h3>
                    <p style="font-size: 13px; color: #6B7280; margin: 0;">When a class admin adds you as an attendee, the class shows up here.</p>
                </div>
            @else
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                    @foreach($this->attendingClassrooms as $classroom)
                        <div style="border: 1px solid #E5E7EB; border-radius: 12px; padding: 20px; background: white;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
                                <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #111827;">{{ $classroom->title }}</h3>
                                <span class="badge" style="background-color: #F5F3FF; color: #6D28D9; border: 1px solid #DDD6FE;">Attendee</span>
                            </div>

                            <div style="display: flex; flex-direction: column; gap: 6px;">
                                @forelse($classroom->courses as $course)
                                    <a href="{{ route('course.show', $course->id) }}" wire:navigate
                                       style="display: flex; align-items: center; gap: 8px; text-decoration: none; font-size: 13px; font-weight: 500; color: #2563EB; padding: 8px 10px; border-radius: 8px; background: #F9FAFB; border: 1px solid #F3F4F6;">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink: 0;">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                        </svg>
                                        {{ $course->title }}
                                    </a>
                                @empty
                                    <p style="margin: 0; font-size: 13px; color: #9CA3AF;">No courses in this class yet.</p>
                                @endforelse
                            </div>

                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 15px; padding-top: 15px; border-top: 1px solid #F3F4F6;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <div style="width: 32px; height: 32px; border-radius: 50%; background-color: #E0E7FF; display: flex; align-items: center; justify-content: center; color: #4338CA; font-weight: 700; font-size: 12px;">
                                        {{ strtoupper(substr($classroom->admin->name ?? '?', 0, 1)) }}
                                    </div>
                                    <div>
                                        <p style="margin: 0; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #6B7280; font-weight: 600;">Class Admin</p>
                                        <p style="margin: 0; font-size: 13px; font-weight: 500; color: #374151;">{{ $classroom->admin->name ?? 'Unknown' }}</p>
                                    </div>
                                </div>
                                <button type="button" wire:click="leaveClassroom({{ $classroom->id }})"
                                        wire:confirm="Leave &quot;{{ $classroom->title }}&quot;? You will lose access to its courses."
                                        style="display: inline-flex; align-items: center; gap: 6px; background: white; border: 1px solid #FECACA; color: #DC2626; font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 8px; cursor: pointer; transition: background-color 0.2s;"
                                        onmouseover="this.style.backgroundColor='#FEF2F2'" onmouseout="this.style.backgroundColor='white'">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                                    </svg>
                                    Leave
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @elseif($this->classrooms->isEmpty())
            <div style="text-align: center; padding: 60px 20px; background: #F9FAFB; border-radius: 12px; border: 1px dashed #D1D5DB;">
                <svg width="40" height="40" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="margin: 0 auto 10px;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                <h3 style="font-size: 14px; font-weight: 600; color: #4B5563; margin-bottom: 5px;">No Classes Yet</h3>
                <p style="font-size: 13px; color: #6B7280; margin: 0;">Use the form on the left to create your first class.</p>
            </div>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                @foreach($this->classrooms as $classroom)
                    <div style="border: 1px solid #E5E7EB; border-radius: 12px; padding: 20px; transition: box-shadow 0.2s, border-color 0.2s; background: white;"
                         onmouseover="this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.1)'; this.style.borderColor='#93C5FD';"
                         onmouseout="this.style.boxShadow='none'; this.style.borderColor='#E5E7EB';">

                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; margin-bottom: 15px;">
                            <a href="{{ route('classes.show', $classroom->id) }}" wire:navigate style="text-decoration: none; flex: 1; min-width: 0;">
                                <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #111827;">{{ $classroom->title }}</h3>
                            </a>
                            <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                                <span class="badge" style="background-color: #EFF6FF; color: #2563EB; border: 1px solid #BFDBFE;">Active</span>
                                <button type="button" wire:click="deleteClassroom({{ $classroom->id }})"
                                        wire:confirm="Delete &quot;{{ $classroom->title }}&quot;? Attendees will lose access to its courses."
                                        title="Delete class"
                                        style="background: none; border: none; cursor: pointer; color: #EF4444; padding: 4px; line-height: 0; opacity: 0.6; transition: opacity 0.2s;"
                                        onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.6'">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <a href="{{ route('classes.show', $classroom->id) }}" wire:navigate style="display: flex; text-decoration: none; align-items: center; gap: 16px; margin-top: 15px; padding-top: 15px; border-top: 1px solid #F3F4F6;">
                            <span style="font-size: 12px; color: #6B7280; display: flex; align-items: center; gap: 5px;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                </svg>
                                {{ $classroom->users_count }} attendee(s)
                            </span>
                            <span style="font-size: 12px; color: #6B7280; display: flex; align-items: center; gap: 5px;">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.232.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
                                </svg>
                                {{ $classroom->courses_count }} course(s)
                            </span>
                        </a>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
