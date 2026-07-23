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

    public function mount($courseId = null, $moduleId = null)
    {
        $this->selectedCourseId = $courseId;
        $this->selectedModuleId = $moduleId;
        
        if (!$this->selectedCourseId) {
            $firstCourse = Course::first();
            if ($firstCourse) {
                $this->selectedCourseId = $firstCourse->id;
                
                // If a course was automatically selected, try to select its first module
                if (!$this->selectedModuleId) {
                    $firstModule = Module::where('course_id', $this->selectedCourseId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->first();
                    if ($firstModule) {
                        $this->selectedModuleId = $firstModule->id;
                    }
                }
            }
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

        return redirect()->route('course.show', $course->id);
    }

    public function createModule()
    {
        $this->validate([
            'newModuleTitle' => 'required|string|max:255',
        ]);

        if (!$this->selectedCourseId) {
            return;
        }

        $maxOrder = Module::where('course_id', $this->selectedCourseId)->max('sort_order') ?? 0;

        $module = Module::create([
            'course_id' => $this->selectedCourseId,
            'title' => $this->newModuleTitle,
            'slug' => \Illuminate\Support\Str::slug($this->newModuleTitle . '-' . time()),
            'sort_order' => $maxOrder + 1,
        ]);

        return redirect()->route('course.module.show', ['course' => $this->selectedCourseId, 'module' => $module->id]);
    }

    public function toggleComplete($contentId)
    {
        $moduleContent = \App\Models\ModuleContent::find($contentId);
        if ($moduleContent) {
            $moduleContent->is_completed = !$moduleContent->is_completed;
            $moduleContent->save();
        }
    }

    public function deleteContent($contentId)
    {
        $moduleContent = \App\Models\ModuleContent::find($contentId);
        if ($moduleContent) {
            $content = $moduleContent->content;
            if ($content) {
                $contentable = $content->contentable;
                if ($contentable) {
                    $contentable->delete();
                }
                $content->delete();
            }
            $moduleContent->delete();
        }
    }

    public function deleteModule($moduleId)
    {
        $module = Module::find($moduleId);
        if ($module) {
            foreach ($module->moduleContents as $mc) {
                $this->deleteContent($mc->id);
            }
            $module->delete();
        }
        
        return redirect()->route('course.show', $this->selectedCourseId);
    }

    public function deleteCourse($courseId)
    {
        $course = Course::find($courseId);
        if ($course) {
            foreach ($course->modules as $mod) {
                $this->deleteModule($mod->id);
            }
            $course->delete();
        }
        
        return redirect()->route('home');
    }

    public function moveModuleUp($moduleId)
    {
        $modules = Module::where('course_id', $this->selectedCourseId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        foreach ($modules as $i => $m) {
            $m->update(['sort_order' => $i]);
        }
        $modules = Module::where('course_id', $this->selectedCourseId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        $index = $modules->search(fn($m) => $m->id == $moduleId);

        if ($index !== false && $index > 0) {
            $current = $modules[$index];
            $previous = $modules[$index - 1];

            $current->update(['sort_order' => $index - 1]);
            $previous->update(['sort_order' => $index]);
        }
    }

    public function moveModuleDown($moduleId)
    {
        $modules = Module::where('course_id', $this->selectedCourseId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        foreach ($modules as $i => $m) {
            $m->update(['sort_order' => $i]);
        }
        $modules = Module::where('course_id', $this->selectedCourseId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        $index = $modules->search(fn($m) => $m->id == $moduleId);

        if ($index !== false && $index < count($modules) - 1) {
            $current = $modules[$index];
            $next = $modules[$index + 1];

            $current->update(['sort_order' => $index + 1]);
            $next->update(['sort_order' => $index]);
        }
    }

    public function moveContentUp($contentId)
    {
        if (!$this->selectedModuleId) return;
        $contents = \App\Models\ModuleContent::where('module_id', $this->selectedModuleId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        foreach ($contents as $i => $c) {
            $c->update(['sort_order' => $i]);
        }
        $contents = \App\Models\ModuleContent::where('module_id', $this->selectedModuleId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        $index = $contents->search(fn($c) => $c->id == $contentId);

        if ($index !== false && $index > 0) {
            $current = $contents[$index];
            $previous = $contents[$index - 1];

            $current->update(['sort_order' => $index - 1]);
            $previous->update(['sort_order' => $index]);
        }
    }

    public function moveContentDown($contentId)
    {
        if (!$this->selectedModuleId) return;
        $contents = \App\Models\ModuleContent::where('module_id', $this->selectedModuleId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        foreach ($contents as $i => $c) {
            $c->update(['sort_order' => $i]);
        }
        $contents = \App\Models\ModuleContent::where('module_id', $this->selectedModuleId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
        $index = $contents->search(fn($c) => $c->id == $contentId);

        if ($index !== false && $index < count($contents) - 1) {
            $current = $contents[$index];
            $next = $contents[$index + 1];

            $current->update(['sort_order' => $index + 1]);
            $next->update(['sort_order' => $index]);
        }
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
        return Module::where('course_id', $this->selectedCourseId)->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();
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


        <div class="course-selector group" x-data="{ open: false }" @click.outside="open = false" style="position: relative; display: flex; align-items: center; gap: 8px;">
            
            <div @click="open = !open" class="select-styled" style="flex: 1; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background-image: none; user-select: none;">
                <span>{{ $this->currentCourse ? $this->currentCourse->title : 'Select a course' }}</span>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6B7280" :style="open ? 'transform: rotate(180deg); transition: transform 0.2s;' : 'transition: transform 0.2s;'">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            
            @if($this->currentCourse)
                <button wire:click.stop="deleteCourse({{ $this->currentCourse->id }})" wire:confirm="Are you sure you want to delete this course and all its modules/contents?" class="opacity-0 group-hover:opacity-100 transition-opacity" style="background: none; border: none; cursor: pointer; color: #EF4444; padding: 4px;" title="Delete Course">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                </button>
            @endif


            <div class="custom-select-dropdown" x-show="open" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" style="display: none;">
                @foreach($this->courses as $course)
                    <a href="{{ route('course.show', $course->id) }}" wire:navigate style="display: block; text-decoration: none;"
                         class="custom-select-option {{ $selectedCourseId == $course->id ? 'selected' : '' }}">
                        {{ $course->title }}
                    </a>
                @endforeach
                @if($this->courses->isEmpty())
                    <div class="custom-select-option" style="cursor: default; color: #6B7280;">No courses available</div>
                @endif
            </div>

        </div>

        <div class="module-list" style="padding-bottom: 50px;">
            @foreach($this->modules as $module)
                <div wire:click="selectModule({{ $module->id }})" class="module-card design group {{ $selectedModuleId == $module->id ? 'active' : '' }}" style="position: relative; cursor: pointer; user-select: none;">
                    <div class="module-header">
                        <div class="module-date">{{ $module->created_at->format('d F') }}</div>
                    </div>
                    <div class="module-body" style="display: flex; justify-content: space-between; align-items: center;">
                        <div class="module-title">{{ $module->title }}</div>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            <button type="button" wire:click.stop="moveModuleUp({{ $module->id }})" class="opacity-0 group-hover:opacity-100 transition-opacity" style="background: #F3F4F6; border: none; border-radius: 4px; padding: 4px; cursor: pointer; color: #4B5563; display: flex; align-items: center; justify-content: center;" title="Move Module Up">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                </svg>
                            </button>
                            <button type="button" wire:click.stop="moveModuleDown({{ $module->id }})" class="opacity-0 group-hover:opacity-100 transition-opacity" style="background: #F3F4F6; border: none; border-radius: 4px; padding: 4px; cursor: pointer; color: #4B5563; display: flex; align-items: center; justify-content: center;" title="Move Module Down">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            <button type="button" wire:click.stop="deleteModule({{ $module->id }})" wire:confirm="Are you sure you want to delete this module and its contents?" class="opacity-0 group-hover:opacity-100 transition-opacity" style="background: none; border: none; cursor: pointer; color: #EF4444; padding: 4px;" title="Delete Module">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
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
                            <a href="/modules/{{ $this->currentModule->id }}/content/create?type=pdf" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;">PDF Document</a>
                            <a href="/modules/{{ $this->currentModule->id }}/content/create?type=video" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none;">Video Content</a>
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
                <div class="content-card group {{ $moduleContent->is_completed ? 'completed' : '' }}" style="width:100%">
                    <div class="content-info">
                        <div class="content-name" style="{{ $moduleContent->is_completed ? 'text-decoration: line-through; color: #6B7280;' : '' }}">
                            {{ $moduleContent->label ?? 'Unnamed Content' }}
                        </div>
                        <div class="content-details" style="margin-top: 4px;">
                            @if($moduleContent->content && $moduleContent->content->contentable)
                                <span class="badge badge-medium">{{ str_replace('Content', '', class_basename($moduleContent->content->contentable_type)) }}</span>
                            @else
                                <span class="badge badge-medium">Unknown</span>
                            @endif
                            
                            @if($moduleContent->is_completed)
                                <span style="font-size: 0.75rem; color: #059669; background: #ECFDF5; border: 1px solid #A7F3D0; padding: 2px 8px; border-radius: 9999px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    Completed
                                </span>
                            @endif
                        </div>
                    </div>
                    <div class="action-area" style="display: flex; gap: 6px; align-items: center;">
                            <div class="opacity-0 group-hover:opacity-100 transition-opacity" style="display: flex; gap: 4px;">
                                <button wire:click.stop="moveContentUp({{ $moduleContent->id }})" style="background: #F3F4F6; color: #4B5563; border: none; padding: 8px; border-radius: 0.375rem; cursor: pointer; display: flex; align-items: center; justify-content: center;" title="Move Content Up">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                    </svg>
                                </button>
                                <button wire:click.stop="moveContentDown({{ $moduleContent->id }})" style="background: #F3F4F6; color: #4B5563; border: none; padding: 8px; border-radius: 0.375rem; cursor: pointer; display: flex; align-items: center; justify-content: center;" title="Move Content Down">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </button>
                            </div>
                            <button wire:click.stop="deleteContent({{ $moduleContent->id }})" wire:confirm="Are you sure you want to delete this content?" class="opacity-0 group-hover:opacity-100 transition-opacity" style="background: #FEE2E2; color: #EF4444; border: none; padding: 8px; border-radius: 0.375rem; cursor: pointer; display: flex; align-items: center; justify-content: center;" title="Delete Content">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
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