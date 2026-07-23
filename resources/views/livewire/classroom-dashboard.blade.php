<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Course;
use App\Models\Module;

new class extends Component
{
    public $selectedCourseId = null;
    public $selectedModuleId = null;
    
    public $showCreateCourseModal = false;
    public $newCourseTitle = '';

    public $showCreateModuleModal = false;
    public $newModuleTitle = '';

    public function mount()
    {

        
    }

    public function updatedSelectedCourseId($value)
    {
        $course = Course::find($value);

        if ($course) {
            $firstModule = $course->modules()->first();
            $this->selectedModuleId = $firstModule ? $firstModule->id : null;
        } else {
            $this->selectedModuleId = null;
        }
    }

    public function selectModule($moduleId)
    {
        $this->selectedModuleId = $moduleId;
    }

    public function createCourse()
    {
        $this->validate([
            'newCourseTitle' => 'required|string|max:255',
        ]);

        $course = Course::create([
            'title' => $this->newCourseTitle,
            'slug' => \Illuminate\Support\Str::slug($this->newCourseTitle . '-' . time()),
        ]);

        $this->selectedCourseId = $course->id;
        $this->selectedModuleId = null;
        
        $this->showCreateCourseModal = false;
        $this->newCourseTitle = '';
    }

    public function createModule()
    {
        $this->validate([
            'newModuleTitle' => 'required|string|max:255',
        ]);

        if (!$this->selectedCourseId) {
            return;
        }

        $module = Module::create([
            'course_id' => $this->selectedCourseId,
            'title' => $this->newModuleTitle,
            'slug' => \Illuminate\Support\Str::slug($this->newModuleTitle . '-' . time()),
        ]);

        $this->selectedModuleId = $module->id;
        $this->showCreateModuleModal = false;
        $this->newModuleTitle = '';
    }

    #[Computed]
    public function courses()
    {
        return Course::all();
    }

    #[Computed]
    public function currentCourse()
    {
        return Course::find($this->selectedCourseId);
    }

    #[Computed]
    public function modules()
    {
        if (!$this->selectedCourseId) {
            return collect();
        }
        return Module::where('course_id', $this->selectedCourseId)->get();
    }

    #[Computed]
    public function currentModule()
    {
        return Module::find($this->selectedModuleId);
    }

    #[Computed]
    public function contents()
    {
        if (!$this->selectedModuleId) {
            return collect();
        }
        $module = Module::with('moduleContents.content.contentable')->find($this->selectedModuleId);
        return $module ? $module->moduleContents : collect();
    }
};
?>

<div class="main-layout">
    <div class="panel-list p-2">

        <div class="w-full p-1" style="display: flex; justify-content: flex-end;">
            <button wire:click="$set('showCreateCourseModal', true)" class="bg-blue-500 text-white p-2 rounded cursor-pointer" >+ Add Course</button>
        </div>


        <div class="course-selector" x-data="{ open: false }" @click.outside="open = false" style="position: relative;">
            
            <div @click="open = !open" class="select-styled" style="cursor: pointer; display: flex; justify-content: space-between; align-items: center; background-image: none; user-select: none;">
                <span>{{ $this->currentCourse ? $this->currentCourse->title : 'Select a course' }}</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6B7280" :style="open ? 'transform: rotate(180deg); transition: transform 0.2s;' : 'transition: transform 0.2s;'">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>


            <div class="custom-select-dropdown" x-show="open" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" style="display: none;">
                @foreach($this->courses as $course)
                    <div wire:click="$set('selectedCourseId', {{ $course->id }}); open = false" 
                         class="custom-select-option {{ $selectedCourseId == $course->id ? 'selected' : '' }}">
                        {{ $course->title }}
                    </div>
                @endforeach
                @if($this->courses->isEmpty())
                    <div class="custom-select-option" style="cursor: default; color: #6B7280;">No courses available</div>
                @endif
            </div>

        </div>

        <div class="module-list" style="padding-bottom: 50px;">
            @foreach($this->modules as $module)
                <div class="module-card design {{ $selectedModuleId == $module->id ? 'active' : '' }}" wire:click="selectModule({{ $module->id }})" style="cursor: pointer;">
                    <div class="module-header">
                        <div class="module-date">{{ $module->created_at->format('d F') }}</div>
                    </div>
                    <div class="module-body">
                        <div class="module-title">{{ $module->title }}</div>
                    </div>
                </div>
            @endforeach


            @if($selectedCourseId)
            <div class="mt-4 p-2 w-full flex justify-center">
                <button wire:click="$set('showCreateModuleModal', true)" class="w-full bg-blue-100 text-blue-600 border border-blue-200 rounded p-2 hover:bg-blue-200 transition-colors cursor-pointer" style="font-weight: 500; border: 1px dashed #3b82f6; background: transparent; color: #3b82f6; width: 100%; border-radius: 6px; padding: 8px;">+ Add Module</button>
            </div>
            @endif

        </div>
    </div>

    <div class="panel-content" style="padding:2rem;width:100%">
        @if($this->currentCourse)
            <div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <div class="content-breadcrumb">{{ $this->currentCourse->title }}</div>
                    <h1 class="content-title">{{ $this->currentModule ? $this->currentModule->title : 'No topic selected' }}</h1>
                </div>
                @if($this->currentModule)
                    <div x-data="{ open: false }" style="position: relative; display: inline-block;">
                        <button @click="open = !open" style="background-color: #4F46E5; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: background-color 0.2s;">
                            + Add Content
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" :style="open ? 'transform: rotate(180deg); transition: transform 0.2s;' : 'transition: transform 0.2s;'">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div x-show="open" @click.outside="open = false" x-transition style="display: none; position: absolute; right: 0; margin-top: 0.5rem; width: 12rem; background-color: white; border-radius: 0.375rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); border: 1px solid #E5E7EB; z-index: 50;">
                            <a href="/modules/{{ $this->currentModule->id }}/content/create?type=note" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;">Text Note</a>
                            <a href="/modules/{{ $this->currentModule->id }}/content/create?type=pdf" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none;">PDF Document</a>
                        </div>
                    </div>
                @endif
            </div>
        @else
            <div class="content-header">
                <h1 class="content-title">No course selected</h1>
            </div>
        @endif
    
        <div class="contents-list">
            @foreach($this->contents as $moduleContent)
                <div class="content-card" style="width:100%">
                    <div class="content-info">
                        <div class="content-name">{{ $moduleContent->label ?? 'Unnamed Content' }}</div>
                        <div class="content-details">
                            @if($moduleContent->content && $moduleContent->content->contentable)
                                <span class="badge badge-medium">{{ str_replace('Content', '', class_basename($moduleContent->content->contentable_type)) }}</span>
                            @else
                                <span class="badge badge-medium">Unknown</span>
                            @endif
                        </div>
                    </div>
                    <div class="action-area">
                            <a href="{{ route('content.show', $moduleContent->id) }}" class="btn-solve" style="text-decoration: none;">
                               View
                            </a>
                    </div>
                </div>
            @endforeach
            
            @if($this->contents->isEmpty())
                <div style="padding: 1rem; color: #6b7280;">
                    No contents available for this module.
                </div>
            @endif
        </div>
        
    </div>

    <!-- Create Course Modal -->
    @if($showCreateCourseModal)
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 50; display: flex; align-items: center; justify-content: center;">
        <div style="background-color: white; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); padding: 1.5rem; width: 100%; max-width: 28rem;">
            <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #111827;">Create New Course</h2>
            
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 0.25rem;">Course Title</label>
                <input type="text" wire:model="newCourseTitle" placeholder="Enter course title" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 0.375rem; padding: 0.5rem 0.75rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); outline: none;">
                @error('newCourseTitle') <span style="color: #EF4444; font-size: 0.75rem; margin-top: 0.25rem; display: block;">{{ $message }}</span> @enderror
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button wire:click="$set('showCreateCourseModal', false)" style="padding: 0.5rem 1rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; background-color: white; font-size: 0.875rem; font-weight: 500; color: #374151; cursor: pointer;">Cancel</button>
                <button wire:click="createCourse" style="padding: 0.5rem 1rem; border: none; border-radius: 0.375rem; background-color: #4F46E5; color: white; font-size: 0.875rem; font-weight: 500; cursor: pointer;">Create Course</button>
            </div>
        </div>
    </div>
    @endif

    <!-- Create Module Modal -->
    @if($showCreateModuleModal)
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 50; display: flex; align-items: center; justify-content: center;">
        <div style="background-color: white; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); padding: 1.5rem; width: 100%; max-width: 28rem;">
            <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #111827;">Create New Module</h2>
            
            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 0.25rem;">Module Title</label>
                <input type="text" wire:model="newModuleTitle" placeholder="Enter module title" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 0.375rem; padding: 0.5rem 0.75rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); outline: none;">
                @error('newModuleTitle') <span style="color: #EF4444; font-size: 0.75rem; margin-top: 0.25rem; display: block;">{{ $message }}</span> @enderror
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button wire:click="$set('showCreateModuleModal', false)" style="padding: 0.5rem 1rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; background-color: white; font-size: 0.875rem; font-weight: 500; color: #374151; cursor: pointer;">Cancel</button>
                <button wire:click="createModule" style="padding: 0.5rem 1rem; border: none; border-radius: 0.375rem; background-color: #4F46E5; color: white; font-size: 0.875rem; font-weight: 500; cursor: pointer;">Create Module</button>
            </div>
        </div>
    </div>
    @endif
 </div>