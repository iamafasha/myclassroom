<?php

use App\Models\Classroom;
use App\Models\Course;
use App\Models\LiveClassContent;
use App\Models\Module;
use App\Models\ModuleContent;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    /** Which module the "Your module" carousel is showing: an index into $this->modules. */
    public int $moduleCursor = 0;

    /** Narrows the whole screen to one class. Null means every class the user is in. */
    #[Url(as: 'class')]
    public ?int $classroomId = null;

    public function mount()
    {
        // A class from someone else's account, or one left behind, must not filter anything.
        if ($this->classroomId && !$this->classrooms->contains('id', $this->classroomId)) {
            $this->classroomId = null;
        }

        $this->focusLatestModule();
    }

    public function selectClassroom(?int $classroomId)
    {
        $this->classroomId = $classroomId && $this->classrooms->contains('id', $classroomId) ? $classroomId : null;

        // The old cursor points into a different list of modules now.
        unset($this->courses, $this->courseIds, $this->modules, $this->latestModule, $this->lessonTotals, $this->currentClassroom);
        $this->focusLatestModule();
    }

    /** Point the carousel at the newest module, so it opens where the user left off. */
    private function focusLatestModule(): void
    {
        $this->moduleCursor = 0;

        $latestId = $this->latestModule?->id;

        if ($latestId) {
            $index = $this->modules->search(fn ($module) => $module->id === $latestId);
            $this->moduleCursor = $index === false ? 0 : $index;
        }
    }

    /** Classes the user administers or attends, the ones worth switching between. */
    #[Computed]
    public function classrooms()
    {
        return Classroom::query()
            ->where(fn ($q) => $q->where('admin_id', auth()->id())
                ->orWhereHas('users', fn ($u) => $u->where('users.id', auth()->id())))
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function currentClassroom()
    {
        return $this->classroomId ? $this->classrooms->firstWhere('id', $this->classroomId) : null;
    }

    public function previousModule()
    {
        $this->moduleCursor = max(0, $this->moduleCursor - 1);
    }

    public function nextModule()
    {
        $this->moduleCursor = min($this->modules->count() - 1, $this->moduleCursor + 1);
    }

    /** Courses feeding the screen: everything visible, or only what the picked class teaches. */
    #[Computed]
    public function courses()
    {
        return Course::visibleTo(auth()->user())
            ->when($this->classroomId, fn ($q) => $q->whereHas(
                'classrooms',
                fn ($classroom) => $classroom->whereKey($this->classroomId)
            ))
            ->withCount('modules')
            ->orderBy('title')
            ->get();
    }

    #[Computed]
    public function courseIds()
    {
        return $this->courses->pluck('id');
    }

    /** Every module the user can reach, in course then syllabus order — the carousel walks this list. */
    #[Computed]
    public function modules()
    {
        if ($this->courseIds->isEmpty()) {
            return collect();
        }

        return Module::with('course')
            ->whereIn('course_id', $this->courseIds)
            ->join('courses', 'courses.id', '=', 'modules.course_id')
            ->orderBy('courses.title')
            ->orderBy('modules.sort_order')
            ->orderBy('modules.id')
            ->select('modules.*')
            ->get();
    }

    #[Computed]
    public function currentModule()
    {
        return $this->modules->get($this->moduleCursor) ?? $this->modules->first();
    }

    /** The most recently added module: what "your latest class" points at. */
    #[Computed]
    public function latestModule()
    {
        if ($this->courseIds->isEmpty()) {
            return null;
        }

        return Module::with('course')
            ->whereIn('course_id', $this->courseIds)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /** Lesson tallies per module, so progress bars never trigger a query per card. */
    #[Computed]
    public function lessonTotals()
    {
        if ($this->courseIds->isEmpty()) {
            return collect();
        }

        return ModuleContent::query()
            ->join('modules', 'modules.id', '=', 'module_contents.module_id')
            ->whereIn('modules.course_id', $this->courseIds)
            ->groupBy('module_contents.module_id')
            ->selectRaw('module_contents.module_id, count(*) as total, sum(module_contents.is_completed) as done')
            ->get()
            ->keyBy('module_id');
    }

    public function moduleProgress(?Module $module): array
    {
        $row = $module ? $this->lessonTotals->get($module->id) : null;

        $total = (int) ($row->total ?? 0);
        $done = (int) ($row->done ?? 0);

        return [
            'total' => $total,
            'done' => $done,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }

    /** The four tiles under the carousel, for whichever module is on screen. */
    #[Computed]
    public function moduleStats()
    {
        $module = $this->currentModule;

        if (!$module) {
            return ['lessons' => [0, 0], 'exercises' => [0, 0], 'liveClasses' => [0, 0], 'score' => null];
        }

        $progress = $this->moduleProgress($module);

        $exercisePivots = DB::table('content_module_content')
            ->join('module_contents', 'module_contents.id', '=', 'content_module_content.module_content_id')
            ->where('module_contents.module_id', $module->id)
            ->where('content_module_content.is_exercise', true)
            ->pluck('content_module_content.id');

        $submitted = $exercisePivots->isEmpty() ? 0 : DB::table('content_exercise_answers')
            ->whereIn('content_module_content_id', $exercisePivots)
            ->where('user_id', auth()->id())
            ->count();

        $liveClasses = $this->liveClassesOfModule($module);

        // The freshest quiz result in this module, shown as-is ("3/5").
        $score = ModuleContent::where('module_id', $module->id)
            ->whereNotNull('score')
            ->latest('updated_at')
            ->value('score');

        return [
            'lessons' => [$progress['done'], $progress['total']],
            'exercises' => [$submitted, $exercisePivots->count()],
            'liveClasses' => [
                $liveClasses->filter(fn ($class) => $class->status() !== 'ended')->count(),
                $liveClasses->count(),
            ],
            'score' => $score,
        ];
    }

    private function liveClassesOfModule(Module $module)
    {
        $ids = DB::table('content_module_content')
            ->join('module_contents', 'module_contents.id', '=', 'content_module_content.module_content_id')
            ->join('contents', 'contents.id', '=', 'content_module_content.content_id')
            ->where('module_contents.module_id', $module->id)
            ->where('contents.contentable_type', LiveClassContent::class)
            ->pluck('contents.contentable_id');

        return $ids->isEmpty() ? collect() : LiveClassContent::whereIn('id', $ids)->get();
    }

    /** First unfinished lesson anywhere, following course then syllabus order. */
    #[Computed]
    public function nextLesson()
    {
        return $this->lessonQuery()->where('module_contents.is_completed', false)->first()
            ?? $this->lessonQuery()->first();
    }

    /** The next unfinished lesson inside the latest module, for the "latest class" band. */
    #[Computed]
    public function latestModuleLesson()
    {
        if (!$this->latestModule) {
            return null;
        }

        $query = fn () => ModuleContent::with('contents.contentable')
            ->where('module_id', $this->latestModule->id)
            ->orderBy('sort_order')
            ->orderBy('id');

        return $query()->where('is_completed', false)->first() ?? $query()->first();
    }

    private function lessonQuery()
    {
        return ModuleContent::with(['contents.contentable', 'module.course'])
            ->join('modules', 'modules.id', '=', 'module_contents.module_id')
            ->join('courses', 'courses.id', '=', 'modules.course_id')
            ->whereIn('modules.course_id', $this->courseIds)
            ->orderBy('courses.title')
            ->orderBy('modules.sort_order')
            ->orderBy('modules.id')
            ->orderBy('module_contents.sort_order')
            ->orderBy('module_contents.id')
            ->select('module_contents.*');
    }

    /** Live classes running now or still ahead, soonest first. */
    #[Computed]
    public function upcomingClasses()
    {
        if ($this->courseIds->isEmpty()) {
            return collect();
        }

        return LiveClassContent::query()
            ->with('content.moduleContents.module.course')
            ->whereHas('content.moduleContents.module', fn ($q) => $q->whereIn('course_id', $this->courseIds))
            ->where('starts_at', '>=', now()->subDay())
            ->orderBy('starts_at')
            ->get()
            ->filter(fn ($class) => $class->status() !== 'ended')
            ->take(4)
            ->values();
    }

    /** Course cards on the right rail: title, owner and how far through it the user is. */
    #[Computed]
    public function courseProgress()
    {
        if ($this->courseIds->isEmpty()) {
            return collect();
        }

        $totals = ModuleContent::query()
            ->join('modules', 'modules.id', '=', 'module_contents.module_id')
            ->whereIn('modules.course_id', $this->courseIds)
            ->groupBy('modules.course_id')
            ->selectRaw('modules.course_id, count(*) as total, sum(module_contents.is_completed) as done')
            ->get()
            ->keyBy('course_id');

        return $this->courses
            ->map(function ($course) use ($totals) {
                $total = (int) ($totals[$course->id]->total ?? 0);
                $done = (int) ($totals[$course->id]->done ?? 0);

                return [
                    'course' => $course,
                    'total' => $total,
                    'done' => $done,
                    'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
                ];
            });
    }

    /** "Video", "Quiz", "Live class"… taken from the first piece of content on the lesson. */
    public function lessonKind(?ModuleContent $lesson): string
    {
        $contentable = $lesson?->contents->first()?->contentable;

        if (!$contentable) {
            return 'Lesson';
        }

        return \Illuminate\Support\Str::headline(
            str_replace('Content', '', class_basename($contentable))
        );
    }
}; ?>

<div class="home-wrap">
    <style>
        .home-wrap { display: flex; width: 100%; height: 100%; overflow: hidden; }

        .home-main { flex: 1; min-width: 0; overflow-y: auto; padding: 32px 36px 48px; }

        .home-side {
            width: 360px;
            flex-shrink: 0;
            overflow-y: auto;
            padding: 28px 24px 48px;
            background: #F9FAFB;
            border-left: 1px solid var(--border-color);
        }

        .home-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 24px; }
        .home-greeting { font-size: 26px; font-weight: 500; color: #111827; margin: 0 0 4px; }
        .home-greeting strong { font-weight: 800; }
        .home-subgreeting { font-size: 13px; color: var(--text-secondary); margin: 0; }

        /* Class switcher */
        .class-picker { position: relative; flex-shrink: 0; }

        .class-picker-btn {
            display: flex; align-items: center; gap: 8px;
            background: #ffffff;
            border: 1px solid #D1D5DB;
            border-radius: 9999px;
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
            max-width: 260px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .class-picker-btn:hover { border-color: #9CA3AF; box-shadow: 0 1px 3px rgba(16, 24, 40, 0.08); }
        .class-picker-value { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .class-picker-menu {
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            z-index: 40;
            min-width: 250px;
            max-height: 300px;
            overflow-y: auto;
            background: #ffffff;
            border: 1px solid #D1D5DB;
            border-radius: 10px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            padding: 4px;
        }

        .class-picker-option {
            width: 100%;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            background: transparent;
            border: none;
            border-radius: 7px;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 600;
            color: #111827;
            text-align: left;
            cursor: pointer;
        }
        .class-picker-option:hover { background: #F3F4F6; }
        .class-picker-option.selected { background: #EFF6FF; color: var(--primary-blue); }

        .class-picker-hint { font-size: 11px; font-weight: 500; color: var(--text-secondary); flex-shrink: 0; }
        .class-picker-option.selected .class-picker-hint { color: #60A5FA; }

        .home-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        }

        /* Hero: pick up where you left off */
        .resume-card { display: flex; align-items: stretch; overflow: hidden; margin-bottom: 28px; }

        .resume-art {
            width: 130px;
            flex-shrink: 0;
            background: #ECFDF5;
            border-right: 1px solid #D1FAE5;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .resume-art-badge {
            width: 34px; height: 34px;
            border-radius: 10px;
            background: #10B981;
            color: #ffffff;
            display: flex; align-items: center; justify-content: center;
        }

        .resume-bars { display: flex; align-items: flex-end; gap: 3px; height: 26px; }
        .resume-bars span { width: 4px; border-radius: 2px; background: #34D399; }

        .resume-body { flex: 1; min-width: 0; padding: 20px 24px; display: flex; align-items: center; gap: 20px; }
        .resume-copy { flex: 1; min-width: 0; }
        .resume-title { font-size: 16px; font-weight: 800; color: #111827; margin-bottom: 2px; }
        .resume-eyebrow { font-size: 10px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 6px; }
        .resume-context { font-size: 13.5px; font-weight: 600; color: #374151; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .btn-primary {
            background: var(--primary-blue);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            transition: background-color 0.15s;
        }
        .btn-primary:hover { background: #1D4ED8; }

        /* Latest class band */
        .latest-block { border: 1px solid var(--border-color); border-radius: 14px; overflow: hidden; margin-bottom: 32px; border-bottom: 3px solid #F59E0B; }

        .latest-head {
            background: #FEF3C7;
            padding: 12px 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: #92400E;
        }
        .latest-head span { color: #B45309; font-weight: 600; }

        .latest-body { background: #ffffff; padding: 18px 20px; display: flex; align-items: center; gap: 18px; }

        .date-chip {
            width: 58px; height: 58px;
            flex-shrink: 0;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: #ffffff;
        }
        .date-chip-month { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #F59E0B; letter-spacing: 0.5px; }
        .date-chip-day { font-size: 20px; font-weight: 800; color: #111827; line-height: 1.1; }

        .latest-title { font-size: 17px; font-weight: 700; color: #111827; }
        .latest-meta { font-size: 12.5px; color: #F59E0B; font-weight: 600; margin-top: 2px; }

        .btn-solid-dark {
            background: #312E81;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 11px 20px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex; align-items: center; gap: 8px;
            white-space: nowrap;
        }
        .btn-solid-dark:hover { background: #3730A3; }

        /* Module carousel */
        .module-tab {
            display: inline-block;
            background: #FEF3C7;
            color: #92400E;
            border: 1px solid var(--border-color);
            border-bottom: none;
            border-radius: 10px 10px 0 0;
            padding: 10px 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .module-panel { border: 1px solid var(--border-color); border-radius: 0 14px 14px 14px; overflow: hidden; background: #ffffff; }

        .module-panel-head {
            background: #F9FAFB;
            border-bottom: 1px solid var(--border-color);
            padding: 16px 20px;
            display: flex; align-items: center; gap: 14px;
        }

        .carousel-btn {
            width: 32px; height: 32px;
            flex-shrink: 0;
            border-radius: 9999px;
            border: none;
            background: #4B5563;
            color: #ffffff;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: background-color 0.15s;
        }
        .carousel-btn:hover:not(:disabled) { background: #111827; }
        .carousel-btn:disabled { background: #E5E7EB; color: #9CA3AF; cursor: default; }

        .module-panel-title { flex: 1; min-width: 0; text-align: center; }
        .module-panel-course { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); }
        .module-panel-name { font-size: 17px; font-weight: 700; color: #111827; margin-top: 2px; }

        .module-panel-body { background: #F5F8FF; padding: 24px; }

        .stat-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; max-width: 620px; margin: 0 auto; }

        .stat-tile {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            display: flex; align-items: center; gap: 14px;
        }

        .stat-icon { width: 42px; height: 42px; border-radius: 9999px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
        .stat-value { font-size: 19px; font-weight: 700; color: #111827; line-height: 1.1; }
        .stat-label { font-size: 12px; color: var(--text-secondary); margin-top: 2px; }

        .module-progress { max-width: 620px; margin: 20px auto 0; }
        .progress-track { height: 8px; border-radius: 4px; background: #E0E7FF; overflow: hidden; }
        .progress-value { height: 100%; border-radius: 4px; background: var(--primary-blue); }
        .progress-caption { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary); margin-top: 8px; }

        .module-panel-foot { background: #ffffff; border-top: 1px solid var(--border-color); padding: 14px; text-align: center; }
        .module-panel-foot a { color: var(--primary-blue); font-size: 13px; font-weight: 600; text-decoration: none; }
        .module-panel-foot a:hover { text-decoration: underline; }

        /* Right rail */
        .side-heading { font-size: 17px; font-weight: 700; color: #111827; margin: 0 0 2px; }
        .side-sub { font-size: 12.5px; color: var(--text-secondary); margin: 0 0 14px; }

        .upcoming-card {
            position: relative;
            overflow: hidden;
            border-radius: 14px;
            padding: 20px;
            background: linear-gradient(120deg, #EEF2FF 0%, #E0F2FE 100%);
            border: 1px solid #DBEAFE;
            margin-bottom: 28px;
        }

        .upcoming-art { position: absolute; top: -2px; right: 0; pointer-events: none; opacity: 0.7; }
        .upcoming-card > *:not(.upcoming-art) { position: relative; }

        .upcoming-label { font-size: 15px; font-weight: 700; color: #1E3A8A; margin-bottom: 10px; }
        .upcoming-title { font-size: 15px; font-weight: 700; color: #111827; padding-right: 72px; }
        .upcoming-when { font-size: 12.5px; color: #1D4ED8; font-weight: 600; margin-top: 4px; }
        .upcoming-course { font-size: 11.5px; color: var(--text-secondary); margin-top: 2px; }
        .upcoming-empty { font-size: 13.5px; color: #374151; padding: 6px 72px 2px 0; }

        .pill-live {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 700;
            padding: 2px 8px; border-radius: 9999px;
            color: #B91C1C; background: #FEE2E2; border: 1px solid #FCA5A5;
        }

        .side-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 28px; }

        .side-row {
            display: block;
            text-decoration: none;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px 16px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .side-row:hover { border-color: #C7D2FE; box-shadow: 0 2px 6px rgba(16, 24, 40, 0.06); }

        .side-row-title { font-size: 13.5px; font-weight: 700; color: #111827; }
        .side-row-meta { font-size: 11.5px; color: var(--text-secondary); margin-top: 3px; }

        .side-empty { font-size: 13px; color: var(--text-secondary); background: #ffffff; border: 1px dashed var(--border-color); border-radius: 12px; padding: 16px; }

        .mini-track { height: 6px; border-radius: 3px; background: #EEF2FF; margin-top: 10px; overflow: hidden; }
        .mini-value { height: 100%; border-radius: 3px; background: #6366F1; }

        @media (max-width: 1180px) {
            .home-wrap { flex-direction: column; overflow-y: auto; }
            .home-main, .home-side { overflow: visible; }
            .home-side { width: auto; border-left: none; border-top: 1px solid var(--border-color); }
        }

        @media (max-width: 720px) {
            .home-main { padding: 24px 20px 40px; }
            .home-header { flex-direction: column; }
            .class-picker-menu { right: auto; left: 0; }
            .resume-body { flex-direction: column; align-items: flex-start; }
            .latest-body { flex-wrap: wrap; }
            .stat-grid { grid-template-columns: minmax(0, 1fr); }
        }
    </style>

    <div class="home-main">
        <div class="home-header">
            <div>
                <h1 class="home-greeting"><strong>Welcome Back</strong> {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h1>
                <p class="home-subgreeting">
                    {{ now()->format('l, j F Y') }}
                    @if($this->currentClassroom) · {{ $this->currentClassroom->title }} @endif
                </p>
            </div>

            {{-- Only worth a picker once there is more than one class to switch between. --}}
            @if($this->classrooms->count() > 1)
                <div class="class-picker" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                    <button type="button" class="class-picker-btn" @click="open = !open">
                        <svg width="16" height="16" fill="none" stroke="#6B7280" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422A12.083 12.083 0 0112 20.055a12.083 12.083 0 01-6.16-9.477L12 14z" />
                        </svg>
                        <span class="class-picker-value">{{ $this->currentClassroom?->title ?? 'All classes' }}</span>
                        <svg width="14" height="14" fill="none" stroke="#6B7280" stroke-width="2" viewBox="0 0 24 24"
                             :style="open ? 'transform: rotate(180deg);' : ''" style="transition: transform 0.2s;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div class="class-picker-menu" x-show="open" x-cloak x-transition.opacity.duration.100ms>
                        <button type="button" class="class-picker-option {{ $classroomId === null ? 'selected' : '' }}"
                                wire:click="selectClassroom(null)" @click="open = false">
                            All classes
                            <span class="class-picker-hint">{{ $this->classrooms->count() }} classes</span>
                        </button>

                        @foreach($this->classrooms as $classroom)
                            <button type="button" class="class-picker-option {{ $classroomId === $classroom->id ? 'selected' : '' }}"
                                    wire:click="selectClassroom({{ $classroom->id }})" @click="open = false">
                                {{ $classroom->title }}
                                <span class="class-picker-hint">{{ $classroom->isAdministeredBy(auth()->user()) ? 'You teach' : 'Attending' }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Pick up where you left off --}}
        @php $nextLesson = $this->nextLesson; @endphp
        <div class="home-card resume-card">
            <div class="resume-art">
                <div class="resume-art-badge">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                </div>
                <div class="resume-bars">
                    @foreach([10, 18, 26, 14, 22, 12, 20] as $height)
                        <span style="height: {{ $height }}px;"></span>
                    @endforeach
                </div>
            </div>

            <div class="resume-body">
                <div class="resume-copy">
                    @if($nextLesson)
                        <div class="resume-title">{{ $nextLesson->is_completed ? 'Revisit your last lesson' : 'Pick up where you left off' }}</div>
                        <div class="resume-eyebrow">{{ $this->lessonKind($nextLesson) }} · {{ $nextLesson->module?->course?->title }}</div>
                        <div class="resume-context">"{{ $nextLesson->label ?? 'Untitled lesson' }}"</div>
                    @elseif($this->currentClassroom)
                        <div class="resume-title">No lessons in this class yet</div>
                        <div class="resume-eyebrow">{{ $this->currentClassroom->title }}</div>
                        <div class="resume-context">Switch to another class, or wait for the teacher to publish content.</div>
                    @else
                        <div class="resume-title">Nothing to study yet</div>
                        <div class="resume-eyebrow">Get started</div>
                        <div class="resume-context">Join a class or create a course to fill your dashboard.</div>
                    @endif
                </div>

                @if($nextLesson)
                    <a href="{{ route('content.show', $nextLesson->id) }}" wire:navigate class="btn-primary">
                        {{ $nextLesson->is_completed ? 'Review' : 'Continue' }}
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                @elseif($this->currentClassroom)
                    <button type="button" wire:click="selectClassroom(null)" class="btn-primary">
                        Show all classes
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </button>
                @else
                    <a href="{{ route('classes.index') }}" wire:navigate class="btn-primary">
                        Browse classes
                        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                @endif
            </div>
        </div>

        {{-- Your latest class --}}
        @if($this->latestModule)
            @php
                $latest = $this->latestModule;
                $latestProgress = $this->moduleProgress($latest);
                $latestLesson = $this->latestModuleLesson;
            @endphp
            <div class="latest-block">
                <div class="latest-head">
                    Your latest class : <span>{{ $latest->course?->title }} — {{ $latest->title }}</span>
                </div>
                <div class="latest-body">
                    <div class="date-chip">
                        <div class="date-chip-month">{{ $latest->created_at->format('M') }}</div>
                        <div class="date-chip-day">{{ $latest->created_at->format('j') }}</div>
                    </div>

                    <div style="flex: 1; min-width: 0;">
                        <div class="latest-title">{{ $latestLesson?->label ?? $latest->title }}</div>
                        <div class="latest-meta">{{ $latestProgress['done'] }} / {{ $latestProgress['total'] }} completed</div>
                    </div>

                    @if($latestLesson)
                        <a href="{{ route('content.show', $latestLesson->id) }}" wire:navigate class="btn-solid-dark">
                            {{ $latestLesson->is_completed ? 'Open' : 'Start' }}
                            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                            </svg>
                        </a>
                    @else
                        <a href="{{ route('course.module.show', ['courseId' => $latest->course_id, 'moduleId' => $latest->id]) }}" wire:navigate class="btn-solid-dark">
                            Open module
                        </a>
                    @endif
                </div>
            </div>
        @endif

        {{-- Your module --}}
        @if($this->currentModule)
            @php
                $module = $this->currentModule;
                $stats = $this->moduleStats;
                $progress = $this->moduleProgress($module);
            @endphp
            <div>
                <div class="module-tab">Your module</div>
                <div class="module-panel">
                    <div class="module-panel-head">
                        <button type="button" wire:click="previousModule" class="carousel-btn" @disabled($moduleCursor <= 0) title="Previous module">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>

                        <div class="module-panel-title">
                            <div class="module-panel-course">{{ $module->course?->title }}</div>
                            <div class="module-panel-name">{{ $module->title }}</div>
                        </div>

                        <button type="button" wire:click="nextModule" class="carousel-btn" @disabled($moduleCursor >= $this->modules->count() - 1) title="Next module">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>

                    <div class="module-panel-body">
                        <div class="stat-grid">
                            <div class="stat-tile">
                                <div class="stat-icon" style="background: #DBEAFE; color: #2563EB;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="stat-value">{{ $stats['lessons'][0] }}/{{ $stats['lessons'][1] }}</div>
                                    <div class="stat-label">Lessons completed</div>
                                </div>
                            </div>

                            <div class="stat-tile">
                                <div class="stat-icon" style="background: #FEF3C7; color: #D97706;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="stat-value">{{ $stats['exercises'][0] }}/{{ $stats['exercises'][1] }}</div>
                                    <div class="stat-label">Exercises submitted</div>
                                </div>
                            </div>

                            <div class="stat-tile">
                                <div class="stat-icon" style="background: #EDE9FE; color: #7C3AED;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="stat-value">{{ $stats['liveClasses'][0] }}/{{ $stats['liveClasses'][1] }}</div>
                                    <div class="stat-label">Live classes ahead</div>
                                </div>
                            </div>

                            <div class="stat-tile">
                                <div class="stat-icon" style="background: #FEE2E2; color: #DC2626;">
                                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                                    </svg>
                                </div>
                                <div>
                                    <div class="stat-value">{{ $stats['score'] ?? '—' }}</div>
                                    <div class="stat-label">Latest score</div>
                                </div>
                            </div>
                        </div>

                        <div class="module-progress">
                            <div class="progress-track">
                                <div class="progress-value" style="width: {{ $progress['percent'] }}%;"></div>
                            </div>
                            <div class="progress-caption">
                                <span>{{ $progress['percent'] }}% of this module done</span>
                                <span>Module {{ $moduleCursor + 1 }} of {{ $this->modules->count() }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="module-panel-foot">
                        <a href="{{ route('course.module.show', ['courseId' => $module->course_id, 'moduleId' => $module->id]) }}" wire:navigate>
                            Open module →
                        </a>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <aside class="home-side">
        {{-- Upcoming class --}}
        @php $nextClass = $this->upcomingClasses->first(); @endphp
        <div class="upcoming-card">
            {{-- Decorative: a laptop tucked into the corner of the card. --}}
            <svg class="upcoming-art" width="120" height="90" viewBox="0 0 120 90" fill="none" aria-hidden="true">
                <circle cx="86" cy="26" r="44" fill="#BFDBFE" opacity="0.45" />
                <rect x="46" y="20" width="58" height="38" rx="4" fill="#ffffff" stroke="#93C5FD" stroke-width="2" />
                <rect x="52" y="26" width="46" height="26" rx="2" fill="#DBEAFE" />
                <path d="M38 62h74l-6 8H44l-6-8z" fill="#ffffff" stroke="#93C5FD" stroke-width="2" stroke-linejoin="round" />
            </svg>

            <div class="upcoming-label">Upcoming Class</div>

            @if($nextClass)
                @if($nextClass->status() === 'live')
                    <span class="pill-live"><span style="width: 7px; height: 7px; border-radius: 9999px; background: #DC2626;"></span> Live now</span>
                @endif
                <div class="upcoming-title" style="margin-top: {{ $nextClass->status() === 'live' ? '8px' : '0' }};">
                    {{ $nextClass->calendarTitle() }}
                </div>
                <div class="upcoming-when">
                    {{ $nextClass->starts_at->format('D, j M · H:i') }} – {{ $nextClass->endsAt()->format('H:i') }}
                    ({{ $nextClass->starts_at->diffForHumans() }})
                </div>
                <div class="upcoming-course">{{ $nextClass->course()?->title }}</div>

                <div style="display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap;">
                    @if($nextClass->canJoin())
                        <a href="{{ $nextClass->join_link }}" target="_blank" rel="noopener" class="btn-primary" style="padding: 8px 14px;">Join class</a>
                    @endif
                    @if($nextClass->moduleContent())
                        <a href="{{ route('content.show', $nextClass->moduleContent()->id) }}" wire:navigate class="btn-primary"
                           style="padding: 8px 14px; background: #ffffff; color: var(--primary-blue); border: 1px solid #BFDBFE;">Details</a>
                    @endif
                </div>
            @else
                <div class="upcoming-empty">Class details will be updated soon!</div>
            @endif
        </div>

        {{-- Later this week --}}
        @if($this->upcomingClasses->count() > 1)
            <h2 class="side-heading">Coming up</h2>
            <p class="side-sub">The rest of your scheduled live classes</p>
            <div class="side-list">
                @foreach($this->upcomingClasses->skip(1) as $class)
                    @php $lessonId = $class->moduleContent()?->id; @endphp
                    <a class="side-row" @if($lessonId) href="{{ route('content.show', $lessonId) }}" wire:navigate @else href="#" onclick="return false;" @endif>
                        <div class="side-row-title">{{ $class->calendarTitle() }}</div>
                        <div class="side-row-meta">{{ $class->starts_at->format('D, j M · H:i') }} · {{ $class->course()?->title }}</div>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Course progress --}}
        <h2 class="side-heading">Your courses</h2>
        <p class="side-sub">Keep track of how far you have come</p>

        @if($this->courseProgress->isEmpty())
            <div class="side-empty">
                @if($this->currentClassroom)
                    {{ $this->currentClassroom->title }} has no courses yet.
                    <button type="button" wire:click="selectClassroom(null)" style="background: none; border: none; padding: 0; color: var(--primary-blue); font-weight: 600; cursor: pointer;">Show all classes →</button>
                @else
                    You are not in any course yet.
                    <a href="{{ route('classes.index') }}" wire:navigate style="color: var(--primary-blue); font-weight: 600; text-decoration: none;">Discover classes →</a>
                @endif
            </div>
        @else
            <div class="side-list">
                @foreach($this->courseProgress as $row)
                    <a class="side-row" href="{{ route('course.show', $row['course']->id) }}" wire:navigate>
                        <div class="side-row-title">{{ $row['course']->title }}</div>
                        <div class="side-row-meta">
                            {{ $row['course']->modules_count }} {{ \Illuminate\Support\Str::plural('module', $row['course']->modules_count) }}
                            · {{ $row['done'] }}/{{ $row['total'] }} lessons done
                        </div>
                        <div class="mini-track">
                            <div class="mini-value" style="width: {{ $row['percent'] }}%;"></div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </aside>
</div>
