<?php 
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use WithFileUploads;

    public $moduleContent;

    public $scoringPivotId = null;

    public array $scoreInputs = [];

    /** Exercise submission state, keyed by content_module_content pivot id. */
    public array $submissionLinks = [];

    public array $submissionFiles = [];

    public function mount($moduleContent)
    {
        $this->moduleContent = \App\Models\ModuleContent::findOrFail($moduleContent);

        $course = $this->moduleContent->module?->course;
        abort_unless(
            $course && \App\Models\Course::visibleTo(auth()->user())->whereKey($course->id)->exists(),
            403,
            'This content belongs to a course you do not have access to.'
        );

        foreach ($this->moduleContent->contents as $content) {
            if (!$content->pivot->is_exercise) {
                continue;
            }

            $answer = $content->pivot->exerciseAnswerFor(auth()->user());
            $this->submissionLinks[$content->pivot->id] = $answer->submission_link ?? '';
        }
    }

    public function submitExercise($pivotId)
    {
        $pivot = \App\Models\ContentModuleContent::where('module_content_id', $this->moduleContent->id)
            ->whereKey($pivotId)
            ->firstOrFail();

        $this->validate([
            "submissionLinks.$pivotId" => 'nullable|url',
            "submissionFiles.$pivotId" => 'nullable|file|max:51200',
        ], [
            "submissionLinks.$pivotId.url" => 'Please enter a valid URL.',
            "submissionFiles.$pivotId.max" => 'The file must be 50MB or smaller.',
        ]);

        $link = trim((string) ($this->submissionLinks[$pivotId] ?? ''));
        $file = $this->submissionFiles[$pivotId] ?? null;

        if ($link === '' && !$file) {
            $this->addError("submission.$pivotId", 'Please provide an answer link or upload a file before submitting.');
            return;
        }

        $answer = \App\Models\ContentExerciseAnswer::firstOrNew([
            'user_id' => auth()->id(),
            'content_module_content_id' => $pivot->id,
        ]);

        if ($link !== '') {
            $answer->submission_link = $link;
        }

        if ($file) {
            $answer->submission_file_path = $file->store('exercise_submissions', 'public');
        }

        $answer->save();

        $this->moduleContent->is_completed = true;
        $this->moduleContent->save();

        unset($this->submissionFiles[$pivotId]);
        $this->moduleContent->load('contents');
    }

    public function openScoring($pivotId)
    {
        $this->authorizeManage();

        $this->scoringPivotId = $pivotId;
        $this->scoreInputs = [];

        foreach ($this->scoringRows as $row) {
            $parts = explode('/', $row['answer']->score ?? '');
            $this->scoreInputs[$row['user']->id] = [
                'obtained' => $parts[0] ?? '',
                'total' => $parts[1] ?? '',
            ];
        }
    }

    public function closeScoring()
    {
        $this->scoringPivotId = null;
        $this->scoreInputs = [];
    }

    public function saveScores()
    {
        $this->authorizeManage();

        $pivot = $this->scoringPivot;

        if (!$pivot) {
            return;
        }

        foreach ($this->scoreInputs as $userId => $input) {
            $obtained = trim((string) ($input['obtained'] ?? ''));
            $total = trim((string) ($input['total'] ?? ''));

            $answer = \App\Models\ContentExerciseAnswer::where('user_id', $userId)
                ->where('content_module_content_id', $pivot->id)
                ->first();

            if ($obtained === '') {
                if ($answer && $answer->score !== null) {
                    $answer->score = null;
                    $answer->save();
                }
                continue;
            }

            $answer ??= new \App\Models\ContentExerciseAnswer([
                'user_id' => $userId,
                'content_module_content_id' => $pivot->id,
            ]);

            $answer->score = $total === '' ? $obtained : $obtained . '/' . $total;
            $answer->save();
        }

        $this->closeScoring();
    }

    public function getScoringPivotProperty()
    {
        // Public properties can be set from the browser, so re-check ownership here.
        return $this->scoringPivotId && $this->canManage
            ? \App\Models\ContentModuleContent::find($this->scoringPivotId)
            : null;
    }

    public function getScoringRowsProperty()
    {
        $pivot = $this->scoringPivot;

        if (!$pivot) {
            return collect();
        }

        $answers = $pivot->exerciseAnswers()->get()->keyBy('user_id');

        return $this->participants($answers->keys())->map(fn ($user) => [
            'user' => $user,
            'answer' => $answers->get($user->id),
        ])->values();
    }

    private function participants($extraUserIds)
    {
        $course = $this->moduleContent->module?->course;

        $classroomUserIds = $course
            ? \App\Models\User::whereHas('classrooms', fn ($q) => $q->whereHas(
                'courses',
                fn ($q2) => $q2->where('courses.id', $course->id)
            ))->pluck('id')
            : collect();

        $ids = $classroomUserIds->merge($extraUserIds)->push(auth()->id())->filter()->unique();

        // No classroom links yet: fall back to every user so submissions are still scoreable.
        if ($classroomUserIds->isEmpty()) {
            return \App\Models\User::orderBy('name')->get();
        }

        return \App\Models\User::whereIn('id', $ids)->orderBy('name')->get();
    }

    #[\Livewire\Attributes\Computed]
    public function canManage()
    {
        return (bool) $this->moduleContent?->module?->course?->isManagedBy(auth()->user());
    }

    /**
     * The next lesson in the course: the following content in this module,
     * or the first content of the next module once this module runs out.
     */
    #[\Livewire\Attributes\Computed]
    public function nextModuleContent()
    {
        $module = $this->moduleContent?->module;

        if (! $module) {
            return null;
        }

        $next = \App\Models\ModuleContent::where('module_id', $module->id)
            ->where(function ($query) {
                $query->where('sort_order', '>', $this->moduleContent->sort_order)
                    ->orWhere(function ($tie) {
                        $tie->where('sort_order', $this->moduleContent->sort_order)
                            ->where('id', '>', $this->moduleContent->id);
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if ($next) {
            return $next;
        }

        $nextModule = \App\Models\Module::where('course_id', $module->course_id)
            ->where(function ($query) use ($module) {
                $query->where('sort_order', '>', $module->sort_order)
                    ->orWhere(function ($tie) use ($module) {
                        $tie->where('sort_order', $module->sort_order)
                            ->where('id', '>', $module->id);
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return $nextModule?->moduleContents()->first();
    }

    /** Editing contents, ordering them and reading everyone's submissions is the owner's job. */
    private function authorizeManage()
    {
        abort_unless($this->canManage, 403, 'Only the course owner can manage this content.');
    }

    public function moveContentItemUp($contentId)
    {
        $this->authorizeManage();

        $this->reorderContentItem($contentId, -1);
    }

    public function moveContentItemDown($contentId)
    {
        $this->authorizeManage();

        $this->reorderContentItem($contentId, 1);
    }

    private function reorderContentItem($contentId, $direction)
    {
        $items = $this->moduleContent->contents()->get();
        foreach ($items as $i => $item) {
            $this->moduleContent->contents()->updateExistingPivot($item->id, ['sort_order' => $i]);
        }

        $items = $this->moduleContent->contents()->get();
        $index = $items->search(fn ($c) => $c->id == $contentId);
        $targetIndex = $index === false ? null : $index + $direction;

        if ($index !== false && $targetIndex !== null && $targetIndex >= 0 && $targetIndex < $items->count()) {
            $current = $items[$index];
            $target = $items[$targetIndex];

            $this->moduleContent->contents()->updateExistingPivot($current->id, ['sort_order' => $targetIndex]);
            $this->moduleContent->contents()->updateExistingPivot($target->id, ['sort_order' => $index]);
        }

        $this->moduleContent->load('contents');
    }
}
?>

<div class="panel-list" style="width: 100%; padding: 40px; overflow-y: auto;">
    <div class="content-header" style="display: flex; justify-content: space-between; align-items: flex-start;">
        <div>
            @php
                $module = $moduleContent->module;
                $course = $module ? $module->course : null;
                $backUrl = ($course && $module) ? route('course.module.show', ['courseId' => $course->id, 'moduleId' => $module->id]) : route('home');
            @endphp
            <a href="{{ $backUrl }}" wire:navigate style="color: #4F46E5; text-decoration: none; font-weight: 500; display: inline-block; margin-bottom: 15px;">&larr; Back to Dashboard</a>
            <h1 class="content-title" style="{{ $moduleContent->is_completed ? 'text-decoration: line-through; color: #6B7280;' : '' }}">{{ $moduleContent->label ?? 'Content' }}</h1>
        </div>

        @if($this->canManage)
        <div x-data="{ open: false }" style="position: relative; display: inline-block;">
            <button @click="open = !open" style="background-color: #4F46E5; color: white; border: none; padding: 0.5rem 1rem; border-radius: 0.375rem; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: background-color 0.2s;">
                + Add Content
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" :style="open ? 'transform: rotate(180deg); transition: transform 0.2s;' : 'transition: transform 0.2s;'">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
            <div x-show="open" @click.outside="open = false" x-transition style="display: none; position: absolute; right: 0; margin-top: 0.5rem; width: 12rem; background-color: white; border-radius: 0.375rem; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05); border: 1px solid #E5E7EB; z-index: 50;">
                <a href="/content/{{ $moduleContent->id }}/add?type=note" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;">Text Note</a>
                <a href="/content/{{ $moduleContent->id }}/add?type=pdf" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;">PDF Document</a>
                <a href="/content/{{ $moduleContent->id }}/add?type=image" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;">Image Content</a>
                <a href="/content/{{ $moduleContent->id }}/add?type=video" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;">Video Content</a>
                <a href="/content/{{ $moduleContent->id }}/add?type=link" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;">External Link</a>
                <a href="/content/{{ $moduleContent->id }}/add?type=quiz" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;">Interactive Quiz</a>
                <a href="/content/{{ $moduleContent->id }}/add?type=live" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none;">Live Class</a>
            </div>
        </div>
        @endif
    </div>

    <div class="content-card" style="margin-top: 20px;  display: block;">
        @php
            if (!function_exists('timeToSeconds')) {
                function timeToSeconds($time) {
                    if (!$time) return null;
                    $parts = explode(':', $time);
                    if (count($parts) == 2) {
                        return ($parts[0] * 60) + $parts[1];
                    }
                    if (count($parts) == 3) {
                        return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
                    }
                    return null;
                }
            }

            if (!function_exists('getYoutubeId')) {
                function getYoutubeId($url) {
                    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches)) {
                        return $matches[1];
                    }
                    return null;
                }
            }

            $contentTypeLabels = [
                'NoteContent' => 'Text Note',
                'PdfNotesContent' => 'PDF Document',
                'VideoContent' => 'Video',
                'LinkContent' => 'External Link',
                'QuizContent' => 'Quiz',
                'ImageContent' => 'Image',
                'LiveClassContent' => 'Live Class',
            ];
        @endphp

        @forelse($moduleContent->contents as $index => $singleContent)
            @php
                $contentable = $singleContent->contentable;
                $type = $contentable ? class_basename($contentable) : 'Unknown';
                $uid = $moduleContent->id . '-' . $index;
            @endphp

            <div style="{{ !$loop->first ? 'margin-top: 30px; padding-top: 30px; border-top: 1px solid #E5E7EB;' : '' }}">

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span style="font-size: 0.75rem; font-weight: 700; color: #6B7280; text-transform: uppercase; letter-spacing: 0.05em;">{{ $contentTypeLabels[$type] ?? 'Content' }}</span>
                @if($this->canManage)
                <div style="display: flex; align-items: center; gap: 6px;">
                    <button wire:click="moveContentItemUp({{ $singleContent->id }})" @if($loop->first) disabled @endif title="Move up" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; background: white; border: 1px solid #D1D5DB; border-radius: 6px; cursor: {{ $loop->first ? 'not-allowed' : 'pointer' }}; opacity: {{ $loop->first ? '0.4' : '1' }}; color: #374151;">&uarr;</button>
                    <button wire:click="moveContentItemDown({{ $singleContent->id }})" @if($loop->last) disabled @endif title="Move down" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; background: white; border: 1px solid #D1D5DB; border-radius: 6px; cursor: {{ $loop->last ? 'not-allowed' : 'pointer' }}; opacity: {{ $loop->last ? '0.4' : '1' }}; color: #374151;">&darr;</button>
                    <a href="{{ route('content.edit', ['moduleContentId' => $moduleContent->id, 'contentId' => $singleContent->id]) }}" wire:navigate style="margin-left: 4px; padding: 6px 12px; background: white; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: #4F46E5; text-decoration: none;">Edit</a>
                </div>
                @endif
            </div>

            @if($type === 'NoteContent')
                <div style="line-height: 1.6; color: #374151;">
                    {!! nl2br(e($contentable->content)) !!}
                </div>
            @elseif($type === 'PdfNotesContent')

                @php
                    $pdfUrl = $contentable->file_url;
                    $startPage = $contentable->start_position ? (int)$contentable->start_position : 1;
                    $endPage = $contentable->end_position ? (int)$contentable->end_position : 'null';
                    $startPercent = $contentable->start_percentage ?? 0;
                    $endPercent = $contentable->end_percentage ?? 100;
                @endphp

                @once
                    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
                    <script>
                        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                    </script>
                @endonce

                <div style="display: flex; justify-content: center; gap: 15px; margin-bottom: 15px; align-items: center; background: #F3F4F6; padding: 10px; border-radius: 8px; border: 1px solid #E5E7EB;">
                    <button id="zoom-out-{{ $uid }}" style="padding: 6px 12px; background: white; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; font-weight: 500; color: #374151;">- Zoom Out</button>
                    <span id="zoom-level-{{ $uid }}" style="font-weight: bold; color: #111827; min-width: 60px; text-align: center;">150%</span>
                    <button id="zoom-in-{{ $uid }}" style="padding: 6px 12px; background: white; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; font-weight: 500; color: #374151;">+ Zoom In</button>
                </div>

                <div id="pdf-wrapper-{{ $uid }}" style="width: 100%; overflow-x: auto; background: #F3F4F6; border-radius: 8px; border: 1px solid #E5E7EB;">
                    <div id="pdf-container-{{ $uid }}" style="display: flex; flex-direction: column; gap: 20px; align-items: center; padding: 20px; min-width: min-content;">
                        <p id="pdf-loading-{{ $uid }}" style="color: #6B7280; font-weight: 500;">Loading PDF pages...</p>
                    </div>
                </div>

                <script>
                    (function() {
                        const url = "{{ $pdfUrl }}";
                        const startPage = {{ $startPage }};
                        let endPage = {{ $endPage }};
                        const startPercent = {{ $startPercent }};
                        const endPercent = {{ $endPercent }};
                        const container = document.getElementById('pdf-container-{{ $uid }}');
                        const loading = document.getElementById('pdf-loading-{{ $uid }}');

                        let currentScale = 1.5;
                        let loadedPdf = null;

                        function insertSorted(el, pageNum) {
                            el.dataset.page = pageNum;
                            let inserted = false;
                            for (let i = 0; i < container.children.length; i++) {
                                if (container.children[i].dataset && parseInt(container.children[i].dataset.page) > pageNum) {
                                    container.insertBefore(el, container.children[i]);
                                    inserted = true;
                                    break;
                                }
                            }
                            if (!inserted) {
                                container.appendChild(el);
                            }
                        }

                        function renderPages(pdf, scale) {
                            container.innerHTML = '';

                            for (let pageNum = startPage; pageNum <= endPage; pageNum++) {
                                pdf.getPage(pageNum).then(function(page) {
                                    const viewport = page.getViewport({scale: scale});
                                    const canvas = document.createElement('canvas');
                                    const ctx = canvas.getContext('2d');
                                    canvas.height = viewport.height;
                                    canvas.width = viewport.width;
                                    canvas.style.display = 'block';

                                    const renderContext = {
                                        canvasContext: ctx,
                                        viewport: viewport
                                    };
                                    page.render(renderContext);

                                    const topPct = (pageNum === startPage) ? startPercent : 0;
                                    const bottomPct = (pageNum === endPage) ? endPercent : 100;

                                    if (topPct > 0 || bottomPct < 100) {
                                        const topSkip = viewport.height * (topPct / 100);
                                        const visibleHeight = Math.max(0, viewport.height * ((bottomPct - topPct) / 100));

                                        const wrapper = document.createElement('div');
                                        wrapper.style.overflow = 'hidden';
                                        wrapper.style.width = viewport.width + 'px';
                                        wrapper.style.height = visibleHeight + 'px';
                                        wrapper.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.1)';

                                        canvas.style.marginTop = (-topSkip) + 'px';
                                        wrapper.appendChild(canvas);

                                        insertSorted(wrapper, pageNum);
                                    } else {
                                        canvas.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.1)';
                                        insertSorted(canvas, pageNum);
                                    }
                                });
                            }
                        }

                        pdfjsLib.getDocument(url).promise.then(function(pdf) {
                            loading.style.display = 'none';
                            loadedPdf = pdf;

                            if (endPage === null || endPage > pdf.numPages) {
                                endPage = pdf.numPages;
                            }

                            renderPages(pdf, currentScale);
                        }).catch(function(err) {
                            loading.innerText = 'Failed to load PDF.';
                            console.error(err);
                        });

                        document.getElementById('zoom-in-{{ $uid }}').addEventListener('click', function() {
                            currentScale += 0.25;
                            document.getElementById('zoom-level-{{ $uid }}').innerText = Math.round(currentScale * 100) + '%';
                            if (loadedPdf) renderPages(loadedPdf, currentScale);
                        });

                        document.getElementById('zoom-out-{{ $uid }}').addEventListener('click', function() {
                            if (currentScale > 0.5) {
                                currentScale -= 0.25;
                                document.getElementById('zoom-level-{{ $uid }}').innerText = Math.round(currentScale * 100) + '%';
                                if (loadedPdf) renderPages(loadedPdf, currentScale);
                            }
                        });
                    })();
                </script>
            @elseif($type === 'VideoContent')

                @php
                    $videoUrl = $contentable->file_url;
                    $startSeconds = timeToSeconds($contentable->start_time);
                    $endSeconds = timeToSeconds($contentable->end_time);
                    $youtubeId = getYoutubeId($videoUrl);
                @endphp

                @once
                    <style>
                        /* Keep the frame inside the viewport, letterboxing rather than cropping or overflowing. */
                        .video-frame { width: 100%; max-width: calc(70vh * 16 / 9); margin: 0 auto; }
                        .video-frame-inner { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; background: #000; }
                        .video-frame-inner > * { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }

                        .video-player { position: relative; width: 100%; max-height: 70vh; margin: 0 auto; background: #000; border-radius: 8px; overflow: hidden; border: 1px solid #E5E7EB; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); display: flex; align-items: center; justify-content: center; outline: none; }
                        .video-player video { display: block; width: 100%; max-height: 70vh; object-fit: contain; background: #000; }

                        /* Fullscreen: fill the screen, drop the page chrome, let the video use the full height. */
                        .video-player:fullscreen, .video-player:-webkit-full-screen { max-height: none; width: 100vw; height: 100vh; border: none; border-radius: 0; }
                        .video-player:fullscreen video, .video-player:-webkit-full-screen video { max-height: 100vh; height: 100vh; }
                        .video-player:fullscreen .video-player-controls, .video-player:-webkit-full-screen .video-player-controls { padding: 18px 28px; }

                        .video-player-controls { position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(0,0,0,0.85), rgba(0,0,0,0)); padding: 24px 14px 10px; display: flex; flex-direction: column; gap: 6px; transition: opacity 0.2s ease; }
                        .video-player.is-idle .video-player-controls { opacity: 0; pointer-events: none; }
                        .video-player.is-idle { cursor: none; }
                        .video-player-row { display: flex; align-items: center; gap: 12px; }
                        .video-player-controls button { background: none; border: none; color: #ffffff; cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; border-radius: 4px; }
                        .video-player-controls button:hover { color: #C7D2FE; }
                        .video-player-controls button:focus-visible { outline: 2px solid #C7D2FE; outline-offset: 2px; }
                        .video-player-seek { width: 100%; cursor: pointer; accent-color: #4F46E5; height: 4px; }
                        .video-player-time { color: #ffffff; font-size: 13px; font-family: monospace; white-space: nowrap; }
                        .video-player-spacer { flex: 1; }
                        .video-player-speed { color: #ffffff; font-size: 13px; font-weight: 700; min-width: 34px; }
                        .video-player-volume-wrap { display: flex; align-items: center; gap: 6px; }
                        .video-player-volume { width: 0; opacity: 0; height: 4px; accent-color: #4F46E5; cursor: pointer; transition: width 0.2s ease, opacity 0.2s ease; }
                        .video-player-volume-wrap:hover .video-player-volume,
                        .video-player-volume-wrap:focus-within .video-player-volume { width: 70px; opacity: 1; }
                        @media (max-width: 640px) {
                            .video-player-volume-wrap:hover .video-player-volume { width: 0; opacity: 0; }
                        }
                    </style>
                @endonce

                @if($youtubeId)
                    <div class="video-frame">
                        <div class="video-frame-inner">
                            <div id="yt-player-{{ $uid }}"></div>
                        </div>
                    </div>

                    @once
                        <script>
                            window.ytInitQueue = window.ytInitQueue || [];
                            window.initYtPlayer = window.initYtPlayer || function(elementId, videoId, ytStartSec, ytEndSec) {
                                let player;
                                let ytInterval = null;

                                player = new YT.Player(elementId, {
                                    videoId: videoId,
                                    playerVars: {
                                        'start': ytStartSec,
                                        'end': ytEndSec ? ytEndSec : undefined,
                                        'rel': 0,
                                        'modestbranding': 1,
                                        'enablejsapi': 1,
                                        'fs': 1
                                    },
                                    events: {
                                        'onReady': function(event) {
                                            const iframe = player.getIframe();
                                            if (iframe) {
                                                iframe.setAttribute('allowfullscreen', '');
                                                iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen');
                                            }
                                            if (ytStartSec) {
                                                player.seekTo(ytStartSec, true);
                                            }
                                        },
                                        'onStateChange': function(event) {
                                            if (event.data === YT.PlayerState.PLAYING) {
                                                if (!ytInterval) {
                                                    ytInterval = setInterval(checkYtBounds, 250);
                                                }
                                            } else {
                                                if (ytInterval) {
                                                    clearInterval(ytInterval);
                                                    ytInterval = null;
                                                }
                                            }
                                        }
                                    }
                                });

                                function checkYtBounds() {
                                    if (!player || typeof player.getCurrentTime !== 'function') return;
                                    const currentTime = player.getCurrentTime();
                                    if (ytStartSec !== null && currentTime < ytStartSec) {
                                        player.seekTo(ytStartSec, true);
                                    }
                                    if (ytEndSec !== null && currentTime >= ytEndSec) {
                                        player.pauseVideo();
                                        player.seekTo(ytEndSec, true);
                                    }
                                }
                            };

                            window.onYouTubeIframeAPIReady = window.onYouTubeIframeAPIReady || function() {
                                window.ytInitQueue.forEach(function(fn) { fn(); });
                                window.ytInitQueue = [];
                            };
                        </script>
                    @endonce

                    <script>
                        (function() {
                            const elementId = 'yt-player-{{ $uid }}';
                            const ytVideoId = "{{ $youtubeId }}";
                            const ytStartSec = {{ $startSeconds ?? '0' }};
                            const ytEndSec = {{ $endSeconds ?? 'null' }};

                            function doInit() {
                                window.initYtPlayer(elementId, ytVideoId, ytStartSec, ytEndSec);
                            }

                            if (window.YT && window.YT.Player) {
                                doInit();
                            } else {
                                window.ytInitQueue = window.ytInitQueue || [];
                                window.ytInitQueue.push(doInit);

                                if (!window.ytApiTagInserted) {
                                    window.ytApiTagInserted = true;
                                    var tag = document.createElement('script');
                                    tag.src = "https://www.youtube.com/iframe_api";
                                    var firstScriptTag = document.getElementsByTagName('script')[0];
                                    firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);
                                }
                            }
                        })();
                    </script>
                @else
                    <div class="video-player" id="video-container-{{ $uid }}" tabindex="0">
                        <video id="course-video-{{ $uid }}" playsinline preload="metadata">
                            <source src="{{ $videoUrl }}">
                            Your browser does not support the video tag.
                        </video>

                        <!-- Custom Controls -->
                        <div class="video-player-controls" id="video-controls-{{ $uid }}">
                            <input type="range" class="video-player-seek" data-role="seek" value="0" min="0" max="100" step="0.1" aria-label="Seek">

                            <div class="video-player-row">
                                <button type="button" data-role="play" title="Play/pause (space)" aria-label="Play">
                                    <svg data-role="icon-play" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    <svg data-role="icon-pause" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24" style="display:none;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                                </button>

                                <div class="video-player-volume-wrap">
                                    <button type="button" data-role="mute" title="Mute (m)" aria-label="Mute">
                                        <svg data-role="icon-volume" xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3A4.5 4.5 0 0014 7.97v8.06A4.47 4.47 0 0016.5 12zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                                        <svg data-role="icon-muted" xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 24 24" style="display:none;"><path d="M16.5 12A4.5 4.5 0 0014 7.97v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51A8.8 8.8 0 0021 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06a8.99 8.99 0 003.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>
                                    </button>
                                    <input type="range" class="video-player-volume" data-role="volume" min="0" max="1" step="0.05" value="1" aria-label="Volume">
                                </div>

                                <button type="button" data-role="back" title="Back 10 seconds (←)" aria-label="Back 10 seconds">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 24 24"><path d="M12.5 8V5l-4 4 4 4v-3c2.76 0 5 2.24 5 5s-2.24 5-5 5-5-2.24-5-5h-2c0 3.87 3.13 7 7 7s7-3.13 7-7-3.13-7-7-7z"/><text x="11" y="20" font-size="8" font-weight="bold" fill="currentColor" text-anchor="middle">10</text></svg>
                                </button>

                                <span class="video-player-time" data-role="time">00:00 / 00:00</span>

                                <button type="button" data-role="forward" title="Forward 10 seconds (→)" aria-label="Forward 10 seconds">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 24 24"><path d="M11.5 8V5l4 4-4 4v-3c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5h2c0 3.87-3.13 7-7 7s-7-3.13-7-7 3.13-7 7-7z"/><text x="13" y="20" font-size="8" font-weight="bold" fill="currentColor" text-anchor="middle">10</text></svg>
                                </button>

                                <span class="video-player-spacer"></span>

                                <button type="button" data-role="speed" class="video-player-speed" title="Playback speed" aria-label="Playback speed">1x</button>

                                <button type="button" data-role="pip" title="Picture in picture" aria-label="Picture in picture" style="display:none;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="21" height="21" fill="currentColor" viewBox="0 0 24 24"><path d="M19 11h-8v6h8v-6zm4 8V4.98C23 3.88 22.1 3 21 3H3c-1.1 0-2 .88-2 1.98V19c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2zm-2 .02H3V4.97h18v14.05z"/></svg>
                                </button>

                                <button type="button" data-role="fullscreen" title="Fullscreen (f)" aria-label="Fullscreen">
                                    <svg data-role="icon-expand" xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
                                    <svg data-role="icon-collapse" xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" viewBox="0 0 24 24" style="display:none;"><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <script>
                        (function() {
                            const container = document.getElementById('video-container-{{ $uid }}');
                            const video = document.getElementById('course-video-{{ $uid }}');
                            if (!container || !video || container.dataset.playerReady) return;
                            container.dataset.playerReady = '1';

                            const el = role => container.querySelector('[data-role="' + role + '"]');
                            const seekBar = el('seek');
                            const timeDisplay = el('time');
                            const iconPlay = el('icon-play');
                            const iconPause = el('icon-pause');
                            const iconVolume = el('icon-volume');
                            const iconMuted = el('icon-muted');
                            const iconExpand = el('icon-expand');
                            const iconCollapse = el('icon-collapse');
                            const volumeSlider = el('volume');
                            const speedBtn = el('speed');
                            const pipBtn = el('pip');

                            // The content can be clipped to a section of the source video.
                            let startSec = {{ $startSeconds ?? 'null' }};
                            let endSec = {{ $endSeconds ?? 'null' }};

                            const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
                            let idleTimer = null;

                            function formatTime(seconds) {
                                if (isNaN(seconds)) return "00:00";
                                const m = Math.floor(seconds / 60);
                                const s = Math.floor(seconds % 60);
                                return (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
                            }

                            function showPlaying(playing) {
                                iconPlay.style.display = playing ? 'none' : 'block';
                                iconPause.style.display = playing ? 'block' : 'none';
                                el('play').setAttribute('aria-label', playing ? 'Pause' : 'Play');
                                if (!playing) wake(false);
                            }

                            function updateDisplay() {
                                if (startSec === null) return;
                                const currentRel = Math.max(0, video.currentTime - startSec);
                                const durationRel = Math.max(0, endSec - startSec);

                                seekBar.max = durationRel;
                                seekBar.value = currentRel;
                                timeDisplay.textContent = formatTime(currentRel) + " / " + formatTime(durationRel);
                            }

                            video.addEventListener('loadedmetadata', function() {
                                if (startSec === null) startSec = 0;
                                if (endSec === null || endSec > video.duration) endSec = video.duration;

                                video.currentTime = startSec;
                                updateDisplay();
                            });

                            function togglePlay() {
                                if (video.paused) {
                                    if (video.currentTime >= endSec) video.currentTime = startSec;
                                    video.play();
                                } else {
                                    video.pause();
                                }
                            }

                            function skip(seconds) {
                                const target = video.currentTime + seconds;
                                video.currentTime = Math.min(Math.max(target, startSec ?? 0), endSec ?? video.duration);
                                updateDisplay();
                            }

                            video.addEventListener('play', () => showPlaying(true));
                            video.addEventListener('pause', () => showPlaying(false));
                            video.addEventListener('ended', () => showPlaying(false));

                            el('play').addEventListener('click', togglePlay);
                            video.addEventListener('click', togglePlay);
                            video.addEventListener('dblclick', toggleFullscreen);
                            el('back').addEventListener('click', () => skip(-10));
                            el('forward').addEventListener('click', () => skip(10));

                            video.addEventListener('timeupdate', function() {
                                if (startSec !== null && video.currentTime < startSec) {
                                    video.currentTime = startSec;
                                }
                                if (endSec !== null && video.currentTime >= endSec) {
                                    video.pause();
                                    video.currentTime = endSec;
                                }
                                updateDisplay();
                            });

                            seekBar.addEventListener('input', function() {
                                video.currentTime = (startSec ?? 0) + parseFloat(seekBar.value);
                            });

                            // Volume
                            function showMuted() {
                                const muted = video.muted || video.volume === 0;
                                iconVolume.style.display = muted ? 'none' : 'block';
                                iconMuted.style.display = muted ? 'block' : 'none';
                                el('mute').setAttribute('aria-label', muted ? 'Unmute' : 'Mute');
                            }
                            el('mute').addEventListener('click', function() {
                                video.muted = !video.muted;
                                if (!video.muted && video.volume === 0) video.volume = 1;
                                volumeSlider.value = video.muted ? 0 : video.volume;
                                showMuted();
                            });
                            volumeSlider.addEventListener('input', function() {
                                video.volume = parseFloat(volumeSlider.value);
                                video.muted = video.volume === 0;
                                showMuted();
                            });

                            // Playback speed
                            speedBtn.addEventListener('click', function() {
                                const next = SPEEDS[(SPEEDS.indexOf(video.playbackRate) + 1) % SPEEDS.length];
                                video.playbackRate = next;
                                speedBtn.textContent = next + 'x';
                            });

                            // Picture in picture, where the browser supports it
                            if (document.pictureInPictureEnabled && !video.disablePictureInPicture) {
                                pipBtn.style.display = 'flex';
                                pipBtn.addEventListener('click', function() {
                                    if (document.pictureInPictureElement) {
                                        document.exitPictureInPicture();
                                    } else {
                                        video.requestPictureInPicture().catch(() => {});
                                    }
                                });
                            }

                            // Fullscreen
                            function fullscreenElement() {
                                return document.fullscreenElement || document.webkitFullscreenElement || null;
                            }
                            function toggleFullscreen() {
                                if (fullscreenElement()) {
                                    (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                                } else if (container.requestFullscreen) {
                                    container.requestFullscreen().catch(() => {});
                                } else if (container.webkitRequestFullscreen) {
                                    container.webkitRequestFullscreen();
                                } else if (video.webkitEnterFullscreen) {
                                    // iOS Safari only goes fullscreen through its own player.
                                    video.webkitEnterFullscreen();
                                }
                            }
                            function showFullscreen() {
                                const isFull = fullscreenElement() === container;
                                iconExpand.style.display = isFull ? 'none' : 'block';
                                iconCollapse.style.display = isFull ? 'block' : 'none';
                                el('fullscreen').setAttribute('aria-label', isFull ? 'Exit fullscreen' : 'Fullscreen');
                            }
                            el('fullscreen').addEventListener('click', toggleFullscreen);
                            document.addEventListener('fullscreenchange', showFullscreen);
                            document.addEventListener('webkitfullscreenchange', showFullscreen);

                            // Hide the controls while playing and untouched, so the video is unobstructed.
                            function wake(scheduleHide) {
                                container.classList.remove('is-idle');
                                clearTimeout(idleTimer);
                                if (scheduleHide !== false && !video.paused) {
                                    idleTimer = setTimeout(() => container.classList.add('is-idle'), 2500);
                                }
                            }
                            container.addEventListener('mousemove', () => wake(true));
                            container.addEventListener('mouseleave', () => { if (!video.paused) container.classList.add('is-idle') });
                            container.addEventListener('touchstart', () => wake(true), { passive: true });

                            // Keyboard shortcuts once the player has focus.
                            container.addEventListener('keydown', function(event) {
                                const key = event.key.toLowerCase();
                                if ([' ', 'k', 'f', 'm', 'arrowleft', 'arrowright'].includes(key)) event.preventDefault();

                                if (key === ' ' || key === 'k') togglePlay();
                                else if (key === 'f') toggleFullscreen();
                                else if (key === 'm') el('mute').click();
                                else if (key === 'arrowleft') skip(-10);
                                else if (key === 'arrowright') skip(10);
                                wake(true);
                            });

                            showMuted();
                            showFullscreen();
                            updateDisplay();
                        })();
                    </script>
                @endif
            @elseif($type === 'LinkContent')
                <div style="padding: 30px; background: #F9FAFB; border-radius: 8px; border: 1px solid #E5E7EB; text-align: center;">
                    <div style="margin-bottom: 20px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 24 24" stroke="#4F46E5" style="margin: 0 auto 12px; display: block;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        <h3 style="font-size: 1.25rem; font-weight: 600; color: #111827; margin-bottom: 8px;">External Resource Link</h3>
                        @if($contentable->description)
                            <p style="color: #4B5563; font-size: 0.95rem; max-width: 600px; margin: 0 auto 20px; line-height: 1.5;">{{ $contentable->description }}</p>
                        @endif
                    </div>

                    <a href="{{ $contentable->url }}" target="_blank" rel="noopener noreferrer" style="display: inline-flex; align-items: center; gap: 8px; background-color: #4F46E5; color: white; padding: 12px 24px; border-radius: 8px; font-weight: 600; text-decoration: none; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2); transition: background-color 0.2s;">
                        Visit Resource
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                    </a>
                </div>
            @elseif($type === 'QuizContent')
                <div style="padding: 24px; background: #F9FAFB; border-radius: 12px; border: 1px solid #E5E7EB;">
                    @if($contentable->description)
                        <div style="margin-bottom: 24px; color: #4B5563; font-size: 0.95rem; line-height: 1.5; background: #EEF2FF; border: 1px solid #C7D2FE; padding: 14px 18px; border-radius: 8px;">
                            💡 {{ $contentable->description }}
                        </div>
                    @endif

                    @if($moduleContent->score)
                        <div style="margin-bottom: 24px; padding: 16px 20px; background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 8px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <h4 style="margin: 0; color: #065F46; font-size: 1rem; font-weight: 700;">Quiz Completed!</h4>
                                <p style="margin: 4px 0 0 0; color: #047857; font-size: 0.875rem;">Your score has been calculated and saved.</p>
                            </div>
                            <div style="font-size: 1.25rem; font-weight: 800; color: #059669; background: white; padding: 8px 18px; border-radius: 8px; border: 1px solid #A7F3D0;">
                                Score: {{ $moduleContent->score }}
                            </div>
                        </div>
                    @endif

                    <form action="{{ route('content.submit-quiz', $moduleContent->id) }}" method="POST">
                        @csrf
                        @php
                            $questions = $contentable->questions ?? [];
                        @endphp

                        @foreach($questions as $qIndex => $q)
                            <div style="margin-bottom: 24px; padding: 20px; background: white; border: 1px solid #E5E7EB; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                                <div style="display: flex; gap: 8px; align-items: flex-start; margin-bottom: 12px;">
                                    <span style="background: #4F46E5; color: white; border-radius: 9999px; width: 24px; height: 24px; display: inline-flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; flex-shrink: 0; margin-top: 2px;">
                                        {{ $qIndex + 1 }}
                                    </span>
                                    <h4 style="margin: 0; font-size: 1rem; font-weight: 600; color: #111827; line-height: 1.4;">
                                        {{ $q['question'] }}
                                    </h4>
                                </div>

                                @php
                                    $isMultipleChoice = count($q['correct_answers'] ?? []) > 1;
                                @endphp

                                <p style="margin: 0 0 12px 32px; font-size: 0.75rem; color: #6B7280; font-style: italic;">
                                    @if($isMultipleChoice)
                                        (Select all correct answers - multiple selection supported)
                                    @else
                                        (Select the correct answer)
                                    @endif
                                </p>

                                <div style="margin-left: 32px; display: flex; flex-direction: column; gap: 10px;">
                                    @foreach($q['options'] as $oIndex => $option)
                                        <label style="display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 6px; cursor: pointer; transition: all 0.2s; user-select: none;">
                                            <input type="checkbox" name="answers[{{ $qIndex }}][]" value="{{ $oIndex }}" style="width: 16px; height: 16px; accent-color: #4F46E5; cursor: pointer;">
                                            <span style="font-size: 0.9rem; color: #374151; font-weight: 500;">
                                                <strong style="color: #6B7280; margin-right: 4px;">{{ chr(65 + $oIndex) }}.</strong> {{ $option }}
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach

                        <div style="display: flex; justify-content: flex-end; margin-top: 20px;">
                            <button type="submit" style="background-color: #4F46E5; color: white; border: none; padding: 12px 28px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(79, 70, 229, 0.2);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                {{ $moduleContent->is_completed ? 'Resubmit Quiz' : 'Submit Quiz Answers' }}
                            </button>
                        </div>
                    </form>
                </div>
            @elseif($type === 'LiveClassContent')
                @php
                    $status = $contentable->status();
                    $palette = match ($status) {
                        'live' => ['bg' => '#FEF2F2', 'border' => '#FECACA', 'accent' => '#DC2626', 'label' => 'Live now'],
                        'ended' => ['bg' => '#F9FAFB', 'border' => '#E5E7EB', 'accent' => '#6B7280', 'label' => 'Ended'],
                        default => ['bg' => '#F5F3FF', 'border' => '#DDD6FE', 'accent' => '#6D28D9', 'label' => 'Upcoming'],
                    };
                @endphp

                <div style="padding: 24px; background: {{ $palette['bg'] }}; border: 1px solid {{ $palette['border'] }}; border-radius: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
                        <div>
                            <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: {{ $palette['accent'] }};">
                                @if($status === 'live')
                                    <span style="width: 8px; height: 8px; border-radius: 9999px; background: {{ $palette['accent'] }}; display: inline-block;"></span>
                                @endif
                                {{ $palette['label'] }}
                            </span>
                            <h3 style="margin: 8px 0 0; font-size: 1.25rem; font-weight: 700; color: #111827;">
                                {{ $contentable->title ?? 'Live Class' }}
                            </h3>
                            <div style="margin-top: 10px; display: flex; flex-direction: column; gap: 4px; font-size: 0.9rem; color: #374151;">
                                <span style="display: flex; align-items: center; gap: 8px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                    </svg>
                                    {{ $contentable->starts_at->format('l, j F Y') }}
                                </span>
                                <span style="display: flex; align-items: center; gap: 8px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {{ $contentable->starts_at->format('H:i') }} – {{ $contentable->endsAt()->format('H:i') }}
                                    <span style="color: #6B7280;">({{ $contentable->duration_minutes ?: 60 }} min)</span>
                                </span>
                                @if($status === 'upcoming')
                                    <span style="color: #6B7280; font-size: 0.85rem;">Starts {{ $contentable->starts_at->diffForHumans() }}</span>
                                @endif
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
                            @if($contentable->canJoin() && $status !== 'ended')
                                <a href="{{ $contentable->join_link }}" target="_blank" rel="noopener noreferrer"
                                   style="display: inline-flex; align-items: center; gap: 8px; background: {{ $palette['accent'] }}; color: white; padding: 12px 24px; border-radius: 8px; font-weight: 600; text-decoration: none;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    {{ $status === 'live' ? 'Join now' : 'Join class' }}
                                </a>
                            @elseif($contentable->canJoin())
                                <a href="{{ $contentable->join_link }}" target="_blank" rel="noopener noreferrer" style="font-size: 0.85rem; color: #4F46E5; text-decoration: underline;">
                                    Open class link
                                </a>
                            @elseif(!$contentable->is_join_enabled)
                                <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #6B7280; background: white; border: 1px solid #E5E7EB; padding: 8px 14px; border-radius: 8px;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                    Joining is closed
                                </span>
                            @else
                                <span style="font-size: 0.85rem; color: #6B7280; font-style: italic; max-width: 220px; text-align: right;">
                                    No link yet — your instructor will share it.
                                </span>
                            @endif

                            @if($status !== 'ended')
                                <div x-data="{ open: false }" @click.outside="open = false" style="position: relative;">
                                    <button type="button" @click="open = !open"
                                            style="display: inline-flex; align-items: center; gap: 6px; background: white; color: #374151; border: 1px solid #D1D5DB; padding: 8px 14px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        Add to calendar
                                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24" stroke="currentColor" :style="open ? 'transform: rotate(180deg);' : ''">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>

                                    <div x-show="open" x-transition style="display: none; position: absolute; top: 100%; right: 0; margin-top: 6px; background: white; border: 1px solid #E5E7EB; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); min-width: 210px; z-index: 40; overflow: hidden;">
                                        <a href="{{ $contentable->googleCalendarUrl() }}" target="_blank" rel="noopener noreferrer" @click="open = false"
                                           style="display: block; padding: 10px 14px; font-size: 0.85rem; color: #374151; text-decoration: none; border-bottom: 1px solid #F3F4F6;">
                                            Google Calendar
                                        </a>
                                        <a href="{{ route('live-class.ics', $contentable->id) }}" @click="open = false"
                                           style="display: block; padding: 10px 14px; font-size: 0.85rem; color: #374151; text-decoration: none;">
                                            Apple / Outlook (.ics)
                                        </a>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if($contentable->description)
                        <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid {{ $palette['border'] }}; color: #374151; font-size: 0.95rem; line-height: 1.6;">
                            {!! nl2br(e($contentable->description)) !!}
                        </div>
                    @endif
                </div>
            @elseif($type == 'ImageContent')
                <div>
                    <img src="{{ $contentable->file_url }}" alt="{{ $contentable->name }}">
                </div>
            @else
                <div style="color: #6B7280; text-align: center; padding: 40px;">
                    Content not available.
                </div>
            @endif

            @if($singleContent->pivot->is_exercise)
                @php
                    $exerciseAnswer = $singleContent->pivot->exerciseAnswerFor(auth()->user());
                @endphp
                <div style="margin-top: 24px; padding: 24px; border: 1px solid #FCD34D; background: #FFFBEB; border-radius: 12px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <span style="font-size: 1.25rem;">📝</span>
                            <h3 style="font-size: 1.1rem; font-weight: 700; color: #92400E; margin: 0;">Exercise Submission Required</h3>
                        </div>
                        @php
                            $submissionCount = $singleContent->pivot->exerciseAnswers()
                                ->where(function ($q) {
                                    $q->whereNotNull('submission_link')->orWhereNotNull('submission_file_path');
                                })->count();
                        @endphp
                        @if($this->canManage)
                        <button type="button" wire:click="openScoring({{ $singleContent->pivot->id }})" style="background: white; color: #92400E; border: 1px solid #F59E0B; padding: 8px 14px; border-radius: 8px; font-weight: 600; font-size: 0.85rem; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                            </svg>
                            Submissions &amp; Scoring
                            <span style="background: #FEF3C7; border: 1px solid #FCD34D; border-radius: 9999px; padding: 0 7px; font-size: 0.75rem; font-weight: 700;">{{ $submissionCount }}</span>
                        </button>
                        @endif
                    </div>

                    @php
                        $pivotId = $singleContent->pivot->id;
                    @endphp

                    @error("submission.$pivotId")
                        <div style="background-color: #FEE2E2; color: #DC2626; border: 1px solid #FCA5A5; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 0.875rem;">
                            {{ $message }}
                        </div>
                    @enderror

                    @if($exerciseAnswer && ($exerciseAnswer->submission_link || $exerciseAnswer->submission_file_path || $exerciseAnswer->score))
                        <div style="background: white; border: 1px solid #FDE68A; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                            <h4 style="font-size: 0.9rem; font-weight: 600; color: #78350F; margin-top: 0; margin-bottom: 8px;">Your Submitted Exercise Details:</h4>
                            @if($exerciseAnswer->submission_link)
                                <div style="margin-bottom: 6px; font-size: 0.9rem;">
                                    <strong>Answer Link:</strong>
                                    <a href="{{ $exerciseAnswer->submission_link }}" target="_blank" rel="noopener noreferrer" style="color: #2563EB; text-decoration: underline;">
                                        {{ $exerciseAnswer->submission_link }}
                                    </a>
                                </div>
                            @endif
                            @if($exerciseAnswer->submission_file_path)
                                <div style="margin-bottom: 6px; font-size: 0.9rem;">
                                    <strong>Submitted File:</strong>
                                    <a href="{{ asset('storage/' . $exerciseAnswer->submission_file_path) }}" target="_blank" style="color: #2563EB; text-decoration: underline;">
                                        Download File
                                    </a>
                                </div>
                            @endif
                            @if($exerciseAnswer->score)
                                <div style="font-size: 0.9rem;">
                                    <strong>Score:</strong>
                                    <span style="font-weight: 700; color: #D97706; background: #FEF3C7; border: 1px solid #FCD34D; padding: 2px 10px; border-radius: 6px;">
                                        {{ $exerciseAnswer->score }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    @endif

                    <form wire:submit="submitExercise({{ $pivotId }})"
                          x-data="{ progress: 0, uploading: false }"
                          x-on:livewire-upload-start="uploading = true; progress = 0"
                          x-on:livewire-upload-finish="uploading = false; progress = 100"
                          x-on:livewire-upload-cancel="uploading = false"
                          x-on:livewire-upload-error="uploading = false"
                          x-on:livewire-upload-progress="progress = $event.detail.progress">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 0.875rem; font-weight: 600; color: #78350F; margin-bottom: 6px;">Provide Answer Link</label>
                                <input type="url" wire:model="submissionLinks.{{ $pivotId }}" style="width: 100%; padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.875rem; outline: none;" placeholder="https://github.com/... or Google Doc URL">
                                @error("submissionLinks.$pivotId") <span style="color: #DC2626; font-size: 0.75rem; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label style="display: block; font-size: 0.875rem; font-weight: 600; color: #78350F; margin-bottom: 6px;">Or Upload Answer File</label>
                                <input type="file" wire:model="submissionFiles.{{ $pivotId }}" style="width: 100%; padding: 6px 12px; background: white; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.875rem;">
                                @error("submissionFiles.$pivotId") <span style="color: #DC2626; font-size: 0.75rem; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                                @if(isset($submissionFiles[$pivotId]) && $submissionFiles[$pivotId])
                                    <span style="font-size: 0.75rem; color: #065F46; display: block; margin-top: 4px;">
                                        Ready: {{ $submissionFiles[$pivotId]->getClientOriginalName() }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        <div x-show="uploading" style="display: none; margin-bottom: 16px;">
                            <div style="font-size: 0.75rem; color: #78350F; margin-bottom: 4px;">Uploading… <span x-text="progress + '%'"></span></div>
                            <div style="width: 100%; background: #FDE68A; border-radius: 4px;">
                                <div :style="`width: ${progress}%`" style="height: 8px; background: #D97706; border-radius: 4px; transition: width 0.2s;"></div>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; align-items: center; margin-top: 16px;">
                            <button type="submit" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="submitExercise" style="background-color: #D97706; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(217, 119, 6, 0.2);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                {{ ($exerciseAnswer && ($exerciseAnswer->submission_link || $exerciseAnswer->submission_file_path || $exerciseAnswer->score)) ? 'Update Submission' : 'Submit Answer' }}
                            </button>
                        </div>
                    </form>
                </div>
            @endif
            </div>
        @empty
            <div style="color: #6B7280; text-align: center; padding: 40px;">
                No content added yet. Use "+ Add Content" above to get started.
            </div>
        @endforelse

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; display: flex; justify-content: flex-end; align-items: center; gap: 12px; flex-wrap: wrap;">
            <form action="{{ route('content.toggle-complete', $moduleContent->id) }}" method="POST">
                @csrf
                @if($moduleContent->is_completed)
                    <button type="submit" style="background-color: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Completed (Click to unmark)
                    </button>
                @else
                    <button type="submit" style="background-color: #4F46E5; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                        Mark as Completed
                    </button>
                @endif
            </form>

            @if($this->nextModuleContent)
                <a href="{{ route('content.show', $this->nextModuleContent->id) }}" wire:navigate
                   style="background-color: #EEF2FF; color: #4F46E5; border: 1px solid #C7D2FE; text-decoration: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: all 0.2s;">
                    Next: {{ \Illuminate\Support\Str::limit($this->nextModuleContent->label ?? 'Content', 32) }}
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </a>
            @else
                <span style="color: #6B7280; font-size: 14px; font-weight: 600; padding: 10px 4px;">
                    You've reached the last content in this course.
                </span>
            @endif
        </div>
    </div>

    @if($this->scoringPivot)
        @php
            $scoringContent = $this->scoringPivot->content;
            $scoringRows = $this->scoringRows;
            $scoredCount = $scoringRows->filter(fn ($row) => $row['answer'] && $row['answer']->score)->count();
        @endphp
        <div style="position: fixed; inset: 0; background-color: rgba(17, 24, 39, 0.6); z-index: 60; display: flex; align-items: center; justify-content: center; padding: 24px;">
            <div style="background: white; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); width: 100%; max-width: 68rem; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden;">

                <div style="padding: 20px 24px; border-bottom: 1px solid #E5E7EB; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px;">
                    <div>
                        <h2 style="margin: 0; font-size: 1.15rem; font-weight: 700; color: #111827;">Exercise Submissions &amp; Scoring</h2>
                        <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: #6B7280;">
                            {{ $moduleContent->label ?? 'Content' }}
                            @if($scoringContent)
                                &middot; {{ $contentTypeLabels[class_basename($scoringContent->contentable_type)] ?? 'Content' }}
                            @endif
                            &middot; {{ $scoredCount }}/{{ $scoringRows->count() }} scored
                        </p>
                    </div>
                    <button type="button" wire:click="closeScoring" style="background: none; border: none; cursor: pointer; color: #6B7280; padding: 4px; line-height: 0;" title="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div style="overflow: auto; flex: 1;">
                    <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                        <thead>
                            <tr style="background: #F9FAFB; position: sticky; top: 0; z-index: 1;">
                                <th style="text-align: left; padding: 10px 24px; font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase; color: #6B7280; border-bottom: 1px solid #E5E7EB;">Student</th>
                                <th style="text-align: left; padding: 10px 16px; font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase; color: #6B7280; border-bottom: 1px solid #E5E7EB;">Submission</th>
                                <th style="text-align: left; padding: 10px 16px; font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase; color: #6B7280; border-bottom: 1px solid #E5E7EB; white-space: nowrap;">Submitted</th>
                                <th style="text-align: left; padding: 10px 24px; font-size: 0.72rem; letter-spacing: 0.05em; text-transform: uppercase; color: #6B7280; border-bottom: 1px solid #E5E7EB; width: 220px;">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($scoringRows as $row)
                                @php
                                    $rowUser = $row['user'];
                                    $answer = $row['answer'];
                                    $hasSubmission = $answer && ($answer->submission_link || $answer->submission_file_path);
                                @endphp
                                <tr style="border-bottom: 1px solid #F3F4F6; {{ $rowUser->id === auth()->id() ? 'background: #FAFAFF;' : '' }}">
                                    <td style="padding: 12px 24px; vertical-align: top;">
                                        <div style="font-weight: 600; color: #111827;">
                                            {{ $rowUser->name }}
                                            @if($rowUser->id === auth()->id())
                                                <span style="font-size: 0.7rem; font-weight: 600; color: #4F46E5; background: #EEF2FF; border: 1px solid #C7D2FE; padding: 1px 6px; border-radius: 9999px; margin-left: 4px;">You</span>
                                            @endif
                                        </div>
                                        <div style="color: #6B7280; font-size: 0.8rem;">{{ $rowUser->email }}</div>
                                    </td>
                                    <td style="padding: 12px 16px; vertical-align: top;">
                                        @if($hasSubmission)
                                            @if($answer->submission_link)
                                                <div style="margin-bottom: 4px;">
                                                    <a href="{{ $answer->submission_link }}" target="_blank" rel="noopener noreferrer" style="color: #2563EB; text-decoration: underline; word-break: break-all;">
                                                        {{ \Illuminate\Support\Str::limit($answer->submission_link, 60) }}
                                                    </a>
                                                </div>
                                            @endif
                                            @if($answer->submission_file_path)
                                                <a href="{{ asset('storage/' . $answer->submission_file_path) }}" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; color: #2563EB; text-decoration: underline;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                                    </svg>
                                                    Download file
                                                </a>
                                            @endif
                                        @else
                                            <span style="color: #9CA3AF; font-style: italic;">No submission yet</span>
                                        @endif
                                    </td>
                                    <td style="padding: 12px 16px; vertical-align: top; color: #6B7280; white-space: nowrap;">
                                        {{ $hasSubmission && $answer->updated_at ? $answer->updated_at->format('M j, Y H:i') : '—' }}
                                    </td>
                                    <td style="padding: 12px 24px; vertical-align: top;">
                                        <div style="display: flex; align-items: center; gap: 6px;">
                                            <input type="number" step="any" wire:model="scoreInputs.{{ $rowUser->id }}.obtained" placeholder="9" style="width: 72px; padding: 6px 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.875rem; outline: none;">
                                            <span style="font-weight: 700; color: #6B7280;">/</span>
                                            <input type="number" step="any" wire:model="scoreInputs.{{ $rowUser->id }}.total" placeholder="10" style="width: 72px; padding: 6px 10px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.875rem; outline: none;">
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" style="padding: 40px; text-align: center; color: #6B7280;">
                                        No students found for this course.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div style="padding: 16px 24px; border-top: 1px solid #E5E7EB; display: flex; justify-content: space-between; align-items: center; gap: 12px; background: #F9FAFB;">
                    <span style="font-size: 0.8rem; color: #6B7280;">Leave a score blank to clear it.</span>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" wire:click="closeScoring" style="padding: 9px 18px; border: 1px solid #D1D5DB; border-radius: 8px; background: white; font-size: 0.875rem; font-weight: 600; color: #374151; cursor: pointer;">Cancel</button>
                        <button type="button" wire:click="saveScores" style="padding: 9px 18px; border: none; border-radius: 8px; background: #4F46E5; color: white; font-size: 0.875rem; font-weight: 600; cursor: pointer;">Save Scores</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
