<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Course;
use App\Models\Module;

new class extends Component
{
    public $selectedCourseId = null;
    public $selectedModuleId = null;

    public function mount()
    {
        $firstCourse = Course::first();
        if ($firstCourse) {
            $this->selectedCourseId = $firstCourse->id;
            
            $firstModule = $firstCourse->modules()->first();
            if ($firstModule) {
                $this->selectedModuleId = $firstModule->id;
            }
        }
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
    <div class="panel-list">
        <div class="course-selector">
            <select class="select-styled" wire:model.live="selectedCourseId">
                @foreach($this->courses as $course)
                    <option value="{{ $course->id }}">{{ $course->title }}</option>
                @endforeach
                @if($this->courses->isEmpty())
                    <option value="">No courses available</option>
                @endif
            </select>
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
        </div>
    </div>

    <div class="panel-content" style="padding:2rem;width:100%">
        @if($this->currentCourse)
            <div class="content-header">
                <div class="content-breadcrumb">{{ $this->currentCourse->title }}</div>
                <h1 class="content-title">{{ $this->currentModule ? $this->currentModule->title : 'No topic selected' }}</h1>
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
                            <button class="btn-solve">
                               View
                            </button>
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
</div>