<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use App\Models\Classroom;
use App\Models\User;

new #[Layout('layouts.app')] class extends Component {
    
    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|exists:users,id')]
    public ?int $admin_id = null;

    #[Computed]
    public function users()
    {
        return User::orderBy('name')->get();
    }

    #[Computed]
    public function classrooms()
    {
        return Classroom::with('admin')->latest()->get();
    }

    public function createClassroom()
    {
        $this->validate();

        Classroom::create([
            'title' => $this->title,
            'admin_id' => $this->admin_id,
        ]);

        $this->reset(['title', 'admin_id']);
        session()->flash('success', 'Classroom created successfully.');
    }
}; ?>

<div style="display: flex; width: 100%; height: 100%; overflow: hidden;">
    <!-- Form Panel -->
    <div class="panel-list" style="width: 350px; padding: 40px 20px; overflow-y: auto; background-color: #F9FAFB; border-right: 1px solid #E5E7EB; flex-shrink: 0;">
        <div class="content-header" style="margin-bottom: 30px;">
            <h1 class="content-title" style="font-size: 20px;">Classes Management</h1>
            <p style="color: var(--text-secondary); font-size: 13px; margin-top: 5px;">Create and manage classrooms.</p>
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

                <div>
                    <label style="display: block; font-size: 12px; font-weight: 600; color: #4B5563; margin-bottom: 5px;">Assign Admin</label>
                    <select wire:model="admin_id" class="select-styled" style="width: 100%;">
                        <option value="">Select an admin...</option>
                        @foreach($this->users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                    @error('admin_id') <span style="color: #EF4444; font-size: 11px; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                </div>

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
        <h2 style="font-size: 18px; font-weight: 700; color: #111827; margin-top: 0; margin-bottom: 20px;">Existing Classes ({{ $this->classrooms->count() }})</h2>
        
        @if($this->classrooms->isEmpty())
            <div style="text-align: center; padding: 60px 20px; background: #F9FAFB; border-radius: 12px; border: 1px dashed #D1D5DB;">
                <svg width="40" height="40" fill="none" stroke="#9CA3AF" viewBox="0 0 24 24" style="margin: 0 auto 10px;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
                <h3 style="font-size: 14px; font-weight: 600; color: #4B5563; margin-bottom: 5px;">No Classes Found</h3>
                <p style="font-size: 13px; color: #6B7280; margin: 0;">Use the form on the left to create your first class.</p>
            </div>
        @else
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                @foreach($this->classrooms as $classroom)
                    <a href="{{ route('classes.show', $classroom->id) }}" wire:navigate style="display: block; text-decoration: none; border: 1px solid #E5E7EB; border-radius: 12px; padding: 20px; transition: box-shadow 0.2s, border-color 0.2s; background: white;" 
                         onmouseover="this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.1)'; this.style.borderColor='#93C5FD';" 
                         onmouseout="this.style.boxShadow='none'; this.style.borderColor='#E5E7EB';">
                        
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
                            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: #111827;">{{ $classroom->title }}</h3>
                            <span class="badge" style="background-color: #EFF6FF; color: #2563EB; border: 1px solid #BFDBFE;">Active</span>
                        </div>
                        
                        <div style="display: flex; align-items: center; gap: 10px; margin-top: 15px; padding-top: 15px; border-top: 1px solid #F3F4F6;">
                            <div style="width: 32px; height: 32px; border-radius: 50%; background-color: #E0E7FF; display: flex; align-items: center; justify-content: center; color: #4338CA; font-weight: 700; font-size: 12px;">
                                {{ strtoupper(substr($classroom->admin->name ?? '?', 0, 1)) }}
                            </div>
                            <div>
                                <p style="margin: 0; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #6B7280; font-weight: 600;">Class Admin</p>
                                <p style="margin: 0; font-size: 13px; font-weight: 500; color: #374151;">{{ $classroom->admin->name ?? 'Unknown' }}</p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
