<?php

use Livewire\Attributes\Computed;
use App\Models\Course;
use App\Models\Module;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public $selectedCourseId = null;
    public $selectedModuleId = null;
    
    public $showCreateCourseModal = false;
    public $newCourseTitle = '';

    public $showEditCourseModal = false;
    public $editCourseTitle = '';

    public $showCreateModuleModal = false;
    public $newModuleTitle = '';

    public $showEditModuleModal = false;
    public $editModuleId = null;
    public $editModuleTitle = '';

    public $showContentDateModal = false;
    public $dateContentId = null;
    public $contentStudyDate = '';

    public function mount($courseId = null, $moduleId = null)
    {
        $this->selectedCourseId = $courseId;
        $this->selectedModuleId = $moduleId;

        // Never land on a course outside the user's classes.
        if ($this->selectedCourseId && !Course::visibleTo(auth()->user())->whereKey($this->selectedCourseId)->exists()) {
            $this->selectedCourseId = null;
            $this->selectedModuleId = null;
        }

        if (!$this->selectedCourseId) {
            $firstCourse = Course::visibleTo(auth()->user())->orderBy('title')->first();
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

    #[Computed]
    public function canManageCourse()
    {
        return (bool) $this->currentCourse?->isManagedBy(auth()->user());
    }

    /** Structural edits (modules, contents, ordering) belong to the course owner. */
    private function authorizeManage()
    {
        abort_unless($this->canManageCourse, 403, 'Only the course owner can change this course.');
    }

    public function createCourse()
    {
        $this->validate([
            'newCourseTitle' => 'required|string|max:255',
        ]);

        $course = Course::create([
            'title' => $this->newCourseTitle,
            'slug' => \Illuminate\Support\Str::slug($this->newCourseTitle . '-' . time()),
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('course.show', $course->id);
    }

    public function editCourse()
    {
        $this->authorizeManage();

        $this->resetValidation('editCourseTitle');
        $this->editCourseTitle = $this->currentCourse->title;
        $this->showEditCourseModal = true;
    }

    public function updateCourse()
    {
        $this->authorizeManage();

        $this->validate([
            'editCourseTitle' => 'required|string|max:255',
        ]);

        $this->currentCourse->update(['title' => $this->editCourseTitle]);

        $this->reset('showEditCourseModal', 'editCourseTitle');
        unset($this->courses, $this->currentCourse);
    }

    public function createModule()
    {
        $this->authorizeManage();

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

        return redirect()->route('course.module.show', ['courseId' => $this->selectedCourseId, 'moduleId' => $module->id]);
    }

    public function editModule($moduleId)
    {
        $this->authorizeManage();

        $module = Module::where('course_id', $this->selectedCourseId)->find($moduleId);
        if (!$module) {
            return;
        }

        $this->resetValidation('editModuleTitle');
        $this->editModuleId = $module->id;
        $this->editModuleTitle = $module->title;
        $this->showEditModuleModal = true;
    }

    public function updateModule()
    {
        $this->authorizeManage();

        $this->validate([
            'editModuleTitle' => 'required|string|max:255',
        ]);

        $module = Module::where('course_id', $this->selectedCourseId)->find($this->editModuleId);
        if (!$module) {
            return;
        }

        $module->update(['title' => $this->editModuleTitle]);

        $this->reset('showEditModuleModal', 'editModuleId', 'editModuleTitle');
        unset($this->modules, $this->currentModule);
    }

    /** Opens the small planner used to say when a content should be started. */
    public function editContentDate($contentId)
    {
        $this->authorizeManage();

        $moduleContent = $this->ownedModuleContent($contentId);
        if (!$moduleContent) {
            return;
        }

        $this->resetValidation('contentStudyDate');
        $this->dateContentId = $moduleContent->id;
        $this->contentStudyDate = $moduleContent->study_at?->format('Y-m-d') ?? '';
        $this->showContentDateModal = true;
    }

    public function updateContentDate()
    {
        $this->authorizeManage();

        $this->validate([
            'contentStudyDate' => 'nullable|date',
        ]);

        $moduleContent = $this->ownedModuleContent($this->dateContentId);
        if (!$moduleContent) {
            return;
        }

        $moduleContent->update(['study_at' => $this->contentStudyDate ?: null]);

        $this->reset('showContentDateModal', 'dateContentId', 'contentStudyDate');
        unset($this->contents);
    }

    public function clearContentDate()
    {
        $this->contentStudyDate = '';
        $this->updateContentDate();
    }

    /** Content rows are only editable through the course they belong to. */
    private function ownedModuleContent($contentId)
    {
        return \App\Models\ModuleContent::whereHas(
            'module',
            fn ($query) => $query->where('course_id', $this->selectedCourseId)
        )->find($contentId);
    }

    /** Completion is per person, so ticking a lesson off only affects the current user. */
    public function toggleComplete($contentId)
    {
        $moduleContent = \App\Models\ModuleContent::find($contentId);

        if ($moduleContent) {
            $moduleContent->toggleCompletedFor(auth()->user());
            unset($this->contents);
        }
    }

    public function deleteContent($contentId)
    {
        $this->authorizeManage();

        $moduleContent = \App\Models\ModuleContent::find($contentId);
        if ($moduleContent) {
            foreach ($moduleContent->contents as $content) {
                $contentable = $content->contentable;
                if ($contentable) {
                    $contentable->delete();
                }
                $content->delete();
            }
            $moduleContent->delete();
        }
    }

    public function addContent()
    {
        $this->authorizeManage();

        if (!$this->selectedModuleId) {
            return;
        }

        $maxOrder = \App\Models\ModuleContent::where('module_id', $this->selectedModuleId)->max('sort_order') ?? 0;

        $moduleContent = \App\Models\ModuleContent::create([
            'module_id' => $this->selectedModuleId,
            'sort_order' => $maxOrder + 1,
        ]);

        return redirect()->route('content.show', $moduleContent->id);
    }

    public function deleteModule($moduleId)
    {
        $this->authorizeManage();

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
        $course = Course::managedBy(auth()->user())->find($courseId);
        if ($course) {
            foreach ($course->modules as $mod) {
                $this->deleteModule($mod->id);
            }
            $course->delete();
        }
        
        return redirect()->route('dashboard');
    }

    public function moveModuleUp($moduleId)
    {
        $this->authorizeManage();

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
        $this->authorizeManage();

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
        $this->authorizeManage();

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
        $this->authorizeManage();

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

    public function moveContentToModule($contentId, $targetModuleId)
    {
        $this->authorizeManage();

        $moduleContent = \App\Models\ModuleContent::find($contentId);
        $targetModule = Module::find($targetModuleId);

        if ($moduleContent && $targetModule && $moduleContent->module_id != $targetModuleId) {
            $maxOrder = \App\Models\ModuleContent::where('module_id', $targetModuleId)->max('sort_order') ?? 0;
            
            $moduleContent->module_id = $targetModuleId;
            $moduleContent->sort_order = $maxOrder + 1;
            $moduleContent->save();
        }
    }

    #[Computed]
    public function courses()
    {
        return Course::visibleTo(auth()->user())->with('classrooms')->orderBy('title')->get();
    }

    #[Computed]
    public function currentCourse()
    {
        return Course::visibleTo(auth()->user())->with('classrooms')->find($this->selectedCourseId);
    }

    #[Computed]
    public function modules()
    {
        if (!$this->selectedCourseId) {
            return collect();
        }
        return Module::where('course_id', $this->selectedCourseId)
            ->with('moduleContents')
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();
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
        $module = Module::with([
            'moduleContents.contents.contentable',
            // Only this user's progress, so the list reflects their own completions.
            'moduleContents.progress' => fn ($query) => $query->where('user_id', auth()->id()),
        ])->find($this->selectedModuleId);
        return $module ? $module->moduleContents : collect();
    }
};
?>

<div class="main-layout">
    <div class="panel-list p-2">



        <div class="course-selector group" x-data="{ open: false }" @click.outside="open = false" style="position: relative; display: flex; align-items: center; gap: 8px;">
            
            <div @click="open = !open" class="select-styled" style="flex: 1; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 8px; background-image: none; user-select: none;">
                <span style="min-width: 0; display: flex; flex-direction: column; gap: 2px;">
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $this->currentCourse ? $this->currentCourse->title : 'Select a course' }}</span>
                    {{-- The class this course is taught in, when it belongs to one. --}}
                    @if($this->currentCourse?->classLabel())
                        <span style="font-size: 11px; font-weight: 500; color: #6B7280; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            in {{ $this->currentCourse->classLabel() }}
                        </span>
                    @endif
                </span>
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#6B7280" style="flex-shrink: 0;" :style="open ? 'transform: rotate(180deg); transition: transform 0.2s;' : 'transition: transform 0.2s;'">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            
          
            <div class="custom-select-dropdown" x-show="open" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95" style="display: none;">
                @foreach($this->courses as $course)
                    <a href="{{ route('course.show', $course->id) }}" wire:navigate style="display: block; text-decoration: none;"
                         class="custom-select-option {{ $selectedCourseId == $course->id ? 'selected' : '' }}">
                        {{ $course->title }}
                        @if($course->classLabel())
                            <span style="display: block; margin-top: 2px; font-size: 11px; font-weight: 500; color: #6B7280;">
                                in {{ $course->classLabel() }}
                            </span>
                        @endif
                    </a>
                @endforeach
                @if($this->courses->isEmpty())
                    <div class="custom-select-option" style="cursor: default; color: #6B7280;">No courses available</div>
                @endif
            </div>

            @if($this->currentCourse && $this->canManageCourse)
                <button type="button" wire:click="editCourse" title="Edit Course" style="background: #F3F4F6; border: 1px solid #E5E7EB; border-radius: 6px; padding: 8px; cursor: pointer; color: #4B5563; display: flex; align-items: center; justify-content: center;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                </button>
            @endif

        </div>

        <div class="module-list" style="padding-bottom: 50px;">
            @foreach($this->modules as $module)
                <a  href="{{ route('course.module.show', ['courseId' => $this->selectedCourseId, 'moduleId' => $module->id]) }}" wire:navigate 
                     x-data="{ isOver: false }"
                     @if($this->canManageCourse)
                     @dragover.prevent="isOver = true"
                     @dragenter.prevent="isOver = true"
                     @dragleave.prevent="isOver = false"
                     @drop.prevent="isOver = false; const cId = event.dataTransfer.getData('contentId'); if(cId) { $wire.moveContentToModule(cId, {{ $module->id }}); }"
                     :style="isOver ? 'border: 2px dashed #4F46E5; background-color: #EEF2FF;' : ''"
                     @endif
                     class="module-card design group {{ $selectedModuleId == $module->id ? 'active' : '' }}" 
                     style="position: relative; cursor: pointer; user-select: none; transition: all 0.2s;">
                    <div class="module-header">
                        {{-- Mirrors the first date planned across this module's contents. --}}
                        <div class="module-date">{{ $module->startDate()->format('d F') }}</div>
                    </div>
                    <div class="module-body" style="display: flex; justify-content: space-between; align-items: center;">
                        <div class="module-title">{{ $module->title }}</div>
                        <div style="display: flex; align-items: center; gap: 4px;">
                            @if($this->canManageCourse)
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
                            <button type="button" @mousedown.stop wire:click.stop.prevent="editModule({{ $module->id }})" class="opacity-0 group-hover:opacity-100 transition-opacity" style="background: #F3F4F6; border: none; border-radius: 4px; padding: 4px; cursor: pointer; color: #4B5563; display: flex; align-items: center; justify-content: center;" title="Edit Module">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                            <button type="button" wire:click.stop="deleteModule({{ $module->id }})" wire:confirm="Are you sure you want to delete this module and its contents?" class="opacity-0 group-hover:opacity-100 transition-opacity" style="background: none; border: none; cursor: pointer; color: #EF4444; padding: 4px;" title="Delete Module">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach


            @if($selectedCourseId && $this->canManageCourse)
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
                @if($this->currentModule && $this->canManageCourse)
                    <button wire:click="addContent" style="background-color: #4F46E5; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: background-color 0.2s;">
                        + Add Content
                    </button>
                @elseif(!$this->canManageCourse && $this->currentCourse->created_by)
                    <a href="{{ route('sessions.index', ['course' => $this->currentCourse->id]) }}" wire:navigate
                       style="background-color: #4F46E5; color: white; text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; display: flex; align-items: center; gap: 0.5rem;">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        Request a session
                    </a>
                @endif
            </div>
        @else
            <div class="content-header">
                <h1 class="content-title">No course selected</h1>
            </div>
        @endif
    
        <div class="contents-list">
            @foreach($this->contents as $moduleContent)
                <div @if($this->canManageCourse) draggable="true"
                     @dragstart="event.dataTransfer.setData('contentId', '{{ $moduleContent->id }}'); event.dataTransfer.effectAllowed = 'move';" @endif
                     class="content-card group {{ $moduleContent->isCompletedFor(auth()->user()) ? 'completed' : '' }}" 
                     style="width:100%; cursor: {{ $this->canManageCourse ? 'grab' : 'default' }}; display: flex; align-items: center; gap: 10px;">
                    
                    @if($this->canManageCourse)
                    <div style="cursor: grab; color: #9CA3AF; display: flex; align-items: center; padding-right: 4px;" title="Drag to move to another module">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16" />
                        </svg>
                    </div>
                    @endif

                    <div class="content-info" style="flex: 1;">
                        <div class="content-name" style="{{ $moduleContent->isCompletedFor(auth()->user()) ? 'text-decoration: line-through; color: #6B7280;' : '' }}">
                            {{ $moduleContent->label ?? 'Unnamed Content' }}
                        </div>
                        <div class="content-details" style="margin-top: 4px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap;">
                            <span class="content-date {{ $moduleContent->study_at ? 'planned' : '' }}"
                                  title="{{ $moduleContent->study_at ? 'Planned start: ' . $moduleContent->study_at->format('l, j F Y') : 'Added ' . $moduleContent->created_at->format('l, j F Y') }}">
                                @if($moduleContent->study_at)
                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    Start {{ $moduleContent->study_at->format('d F') }}
                                @else
                                    {{ $moduleContent->created_at->format('d F') }}
                                @endif
                            </span>
                            @php
                                $firstContent = $moduleContent->contents->first();
                                $exerciseContents = $moduleContent->contents->filter(fn ($c) => $c->pivot->is_exercise);
                                $liveClass = $moduleContent->contents
                                    ->map(fn ($c) => $c->contentable)
                                    ->first(fn ($c) => $c instanceof \App\Models\LiveClassContent);
                            @endphp
                            @if($firstContent && $firstContent->contentable)
                                <span class="badge badge-medium">{{ str_replace('Content', '', class_basename($firstContent->contentable_type)) }}</span>
                            @else
                                <span class="badge badge-medium">Unknown</span>
                            @endif

                            @if($liveClass)
                                @php $liveStatus = $liveClass->status(); @endphp
                                <span title="{{ $liveClass->starts_at->format('D, j M Y H:i') }}"
                                      style="font-size: 0.75rem; padding: 2px 8px; border-radius: 9999px; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;
                                             @if($liveStatus === 'live') color: #B91C1C; background: #FEE2E2; border: 1px solid #FCA5A5;
                                             @elseif($liveStatus === 'ended') color: #4B5563; background: #F3F4F6; border: 1px solid #E5E7EB;
                                             @else color: #6D28D9; background: #EDE9FE; border: 1px solid #DDD6FE; @endif">
                                    @if($liveStatus === 'live')
                                        <span style="width: 7px; height: 7px; border-radius: 9999px; background: #DC2626; display: inline-block;"></span>
                                        Live now
                                    @elseif($liveStatus === 'ended')
                                        Live class ended
                                    @else
                                        🔴 Live {{ $liveClass->starts_at->format('j M, H:i') }}
                                    @endif
                                </span>
                            @endif

                            @if($exerciseContents->isNotEmpty())
                                <span style="font-size: 0.75rem; color: #D97706; background: #FEF3C7; border: 1px solid #FCD34D; padding: 2px 8px; border-radius: 9999px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                    📝 Exercise
                                </span>
                            @endif

                            @if($moduleContent->quizScoreFor(auth()->user()))
                                <span style="font-size: 0.75rem; color: #92400E; background: #FDE68A; border: 1px solid #F59E0B; padding: 2px 8px; border-radius: 9999px; font-weight: 700; display: inline-flex; align-items: center;">
                                    Score: {{ $moduleContent->quizScoreFor(auth()->user()) }}
                                </span>
                            @endif

                            @foreach($exerciseContents as $exerciseContent)
                                @php
                                    $exerciseAnswer = $exerciseContent->pivot->exerciseAnswerFor(auth()->user());
                                @endphp
                                @if($exerciseAnswer && $exerciseAnswer->score)
                                    <span style="font-size: 0.75rem; color: #92400E; background: #FDE68A; border: 1px solid #F59E0B; padding: 2px 8px; border-radius: 9999px; font-weight: 700; display: inline-flex; align-items: center;">
                                        Score: {{ $exerciseAnswer->score }}
                                    </span>
                                @endif
                            @endforeach
                            
                            @if($moduleContent->isCompletedFor(auth()->user()))
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
                            @if($this->canManageCourse)
                            <div class="opacity-0 group-hover:opacity-100 transition-opacity" style="display: flex; gap: 4px;">
                                <button wire:click.stop="editContentDate({{ $moduleContent->id }})" style="background: #EEF2FF; color: #4338CA; border: none; padding: 8px; border-radius: 0.375rem; cursor: pointer; display: flex; align-items: center; justify-content: center;" title="Set start date">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                </button>
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
                            @endif
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

    <!-- Edit Course Modal -->
    @if($showEditCourseModal)
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 50; display: flex; align-items: center; justify-content: center;">
        <div style="background-color: white; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); padding: 1.5rem; width: 100%; max-width: 28rem;">
            <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #111827;">Edit Course</h2>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 0.25rem;">Course Title</label>
                <input type="text" wire:model="editCourseTitle" wire:keydown.enter="updateCourse" placeholder="Enter course title" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 0.375rem; padding: 0.5rem 0.75rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); outline: none;">
                @error('editCourseTitle') <span style="color: #EF4444; font-size: 0.75rem; margin-top: 0.25rem; display: block;">{{ $message }}</span> @enderror
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button wire:click="$set('showEditCourseModal', false)" style="padding: 0.5rem 1rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; background-color: white; font-size: 0.875rem; font-weight: 500; color: #374151; cursor: pointer;">Cancel</button>
                <button wire:click="updateCourse" style="padding: 0.5rem 1rem; border: none; border-radius: 0.375rem; background-color: #4F46E5; color: white; font-size: 0.875rem; font-weight: 500; cursor: pointer;">Save Changes</button>
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

    <!-- Edit Module Modal -->
    @if($showEditModuleModal)
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 50; display: flex; align-items: center; justify-content: center;">
        <div style="background-color: white; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); padding: 1.5rem; width: 100%; max-width: 28rem;">
            <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 1rem; color: #111827;">Edit Module</h2>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 0.25rem;">Module Title</label>
                <input type="text" wire:model="editModuleTitle" wire:keydown.enter="updateModule" placeholder="Enter module title" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 0.375rem; padding: 0.5rem 0.75rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); outline: none;">
                @error('editModuleTitle') <span style="color: #EF4444; font-size: 0.75rem; margin-top: 0.25rem; display: block;">{{ $message }}</span> @enderror
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 0.75rem; margin-top: 1.5rem;">
                <button wire:click="$set('showEditModuleModal', false)" style="padding: 0.5rem 1rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; background-color: white; font-size: 0.875rem; font-weight: 500; color: #374151; cursor: pointer;">Cancel</button>
                <button wire:click="updateModule" style="padding: 0.5rem 1rem; border: none; border-radius: 0.375rem; background-color: #4F46E5; color: white; font-size: 0.875rem; font-weight: 500; cursor: pointer;">Save Changes</button>
            </div>
        </div>
    </div>
    @endif

    <!-- Content Start Date Modal -->
    @if($showContentDateModal)
    <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5); z-index: 50; display: flex; align-items: center; justify-content: center;">
        <div style="background-color: white; border-radius: 0.5rem; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); padding: 1.5rem; width: 100%; max-width: 28rem;">
            <h2 style="font-size: 1.25rem; font-weight: 600; margin-bottom: 0.25rem; color: #111827;">Start Date</h2>
            <p style="font-size: 0.875rem; color: #6B7280; margin-bottom: 1rem;">When should learners start reading or studying this content?</p>

            <div style="margin-bottom: 1rem;">
                <label style="display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 0.25rem;">Date</label>
                <input type="date" wire:model="contentStudyDate" wire:keydown.enter="updateContentDate" style="width: 100%; border: 1px solid #D1D5DB; border-radius: 0.375rem; padding: 0.5rem 0.75rem; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); outline: none;">
                @error('contentStudyDate') <span style="color: #EF4444; font-size: 0.75rem; margin-top: 0.25rem; display: block;">{{ $message }}</span> @enderror
            </div>

            <div style="display: flex; justify-content: space-between; gap: 0.75rem; margin-top: 1.5rem;">
                <button wire:click="clearContentDate" style="padding: 0.5rem 1rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; background-color: white; font-size: 0.875rem; font-weight: 500; color: #6B7280; cursor: pointer;">Clear date</button>
                <div style="display: flex; gap: 0.75rem;">
                    <button wire:click="$set('showContentDateModal', false)" style="padding: 0.5rem 1rem; border: 1px solid #D1D5DB; border-radius: 0.375rem; background-color: white; font-size: 0.875rem; font-weight: 500; color: #374151; cursor: pointer;">Cancel</button>
                    <button wire:click="updateContentDate" style="padding: 0.5rem 1rem; border: none; border-radius: 0.375rem; background-color: #4F46E5; color: white; font-size: 0.875rem; font-weight: 500; cursor: pointer;">Save Date</button>
                </div>
            </div>
        </div>
    </div>
    @endif
 </div>