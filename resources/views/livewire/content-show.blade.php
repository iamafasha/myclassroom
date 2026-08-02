<?php 
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public $moduleContent;

    public function mount($moduleContent)
    {
        $this->moduleContent = \App\Models\ModuleContent::find($moduleContent);
    }

    public function moveContentItemUp($contentId)
    {
        $this->reorderContentItem($contentId, -1);
    }

    public function moveContentItemDown($contentId)
    {
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
                <a href="/content/{{ $moduleContent->id }}/add?type=quiz" style="display: block; padding: 0.75rem 1rem; font-size: 0.875rem; color: #374151; text-decoration: none;">Interactive Quiz</a>
            </div>
        </div>
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
                <div style="display: flex; align-items: center; gap: 6px;">
                    <button wire:click="moveContentItemUp({{ $singleContent->id }})" @if($loop->first) disabled @endif title="Move up" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; background: white; border: 1px solid #D1D5DB; border-radius: 6px; cursor: {{ $loop->first ? 'not-allowed' : 'pointer' }}; opacity: {{ $loop->first ? '0.4' : '1' }}; color: #374151;">&uarr;</button>
                    <button wire:click="moveContentItemDown({{ $singleContent->id }})" @if($loop->last) disabled @endif title="Move down" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; background: white; border: 1px solid #D1D5DB; border-radius: 6px; cursor: {{ $loop->last ? 'not-allowed' : 'pointer' }}; opacity: {{ $loop->last ? '0.4' : '1' }}; color: #374151;">&darr;</button>
                    <a href="{{ route('content.edit', ['moduleContentId' => $moduleContent->id, 'contentId' => $singleContent->id]) }}" wire:navigate style="margin-left: 4px; padding: 6px 12px; background: white; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.8rem; font-weight: 600; color: #4F46E5; text-decoration: none;">Edit</a>
                </div>
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

                @if($youtubeId)
                    <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; background: #000;">
                        <div id="yt-player-{{ $uid }}" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></div>
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
                                        'enablejsapi': 1
                                    },
                                    events: {
                                        'onReady': function(event) {
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
                    <div style="border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; background: #000; position: relative;" id="video-container-{{ $uid }}">
                        <video id="course-video-{{ $uid }}" width="100%" style="display: block;">
                            <source src="{{ $videoUrl }}" type="video/mp4">
                            Your browser does not support the video tag.
                        </video>

                        <!-- Custom Controls -->
                        <div id="video-controls-{{ $uid }}" style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.7); padding: 10px 15px; display: flex; align-items: center; gap: 15px;">
                            <button id="play-pause-{{ $uid }}" style="background: none; border: none; color: white; cursor: pointer; padding: 0; display: flex; align-items: center;">
                                <!-- Play Icon -->
                                <svg id="icon-play-{{ $uid }}" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                <!-- Pause Icon (hidden) -->
                                <svg id="icon-pause-{{ $uid }}" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24" style="display:none;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                            </button>
                            <input type="range" id="seek-bar-{{ $uid }}" value="0" min="0" max="100" style="flex: 1; cursor: pointer;">
                            <span id="time-display-{{ $uid }}" style="color: white; font-size: 13px; font-family: monospace;">00:00 / 00:00</span>
                        </div>
                    </div>

                    <script>
                        document.addEventListener("DOMContentLoaded", function() {
                            const video = document.getElementById('course-video-{{ $uid }}');
                            const playPauseBtn = document.getElementById('play-pause-{{ $uid }}');
                            const iconPlay = document.getElementById('icon-play-{{ $uid }}');
                            const iconPause = document.getElementById('icon-pause-{{ $uid }}');
                            const seekBar = document.getElementById('seek-bar-{{ $uid }}');
                            const timeDisplay = document.getElementById('time-display-{{ $uid }}');

                            let startSec = {{ $startSeconds ?? 'null' }};
                            let endSec = {{ $endSeconds ?? 'null' }};

                            function formatTime(seconds) {
                                if (isNaN(seconds)) return "00:00";
                                const m = Math.floor(seconds / 60);
                                const s = Math.floor(seconds % 60);
                                return (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
                            }

                            video.addEventListener('loadedmetadata', function() {
                                if (startSec === null) startSec = 0;
                                if (endSec === null || endSec > video.duration) endSec = video.duration;

                                video.currentTime = startSec;
                                updateDisplay();
                            });

                            function updateDisplay() {
                                if (startSec === null) return;
                                const currentRel = Math.max(0, video.currentTime - startSec);
                                const durationRel = Math.max(0, endSec - startSec);

                                seekBar.max = durationRel;
                                seekBar.value = currentRel;
                                timeDisplay.textContent = formatTime(currentRel) + " / " + formatTime(durationRel);
                            }

                            playPauseBtn.addEventListener('click', function() {
                                if (video.paused) {
                                    if (video.currentTime >= endSec) video.currentTime = startSec;
                                    video.play();
                                    iconPlay.style.display = 'none';
                                    iconPause.style.display = 'block';
                                } else {
                                    video.pause();
                                    iconPlay.style.display = 'block';
                                    iconPause.style.display = 'none';
                                }
                            });

                            video.addEventListener('timeupdate', function() {
                                if (startSec !== null && video.currentTime < startSec) {
                                    video.currentTime = startSec;
                                }
                                if (endSec !== null && video.currentTime >= endSec) {
                                    video.pause();
                                    video.currentTime = endSec;
                                    iconPlay.style.display = 'block';
                                    iconPause.style.display = 'none';
                                }
                                updateDisplay();
                            });

                            seekBar.addEventListener('input', function() {
                                video.currentTime = startSec + parseFloat(seekBar.value);
                            });

                            video.addEventListener('ended', function() {
                                iconPlay.style.display = 'block';
                                iconPause.style.display = 'none';
                            });
                        });
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
                <div style="margin-top: 24px; padding: 24px; border: 1px solid #FCD34D; background: #FFFBEB; border-radius: 12px;">
                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px;">
                        <span style="font-size: 1.25rem;">📝</span>
                        <h3 style="font-size: 1.1rem; font-weight: 700; color: #92400E; margin: 0;">Exercise Submission Required</h3>
                    </div>

                    @if($errors->has('exercise'))
                        <div style="background-color: #FEE2E2; color: #DC2626; border: 1px solid #FCA5A5; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 0.875rem;">
                            {{ $errors->first('exercise') }}
                        </div>
                    @endif

                    @if($singleContent->pivot->submission_link || $singleContent->pivot->submission_file_path || $singleContent->pivot->score)
                        <div style="background: white; border: 1px solid #FDE68A; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                            <h4 style="font-size: 0.9rem; font-weight: 600; color: #78350F; margin-top: 0; margin-bottom: 8px;">Your Submitted Exercise Details:</h4>
                            @if($singleContent->pivot->submission_link)
                                <div style="margin-bottom: 6px; font-size: 0.9rem;">
                                    <strong>Answer Link:</strong>
                                    <a href="{{ $singleContent->pivot->submission_link }}" target="_blank" rel="noopener noreferrer" style="color: #2563EB; text-decoration: underline;">
                                        {{ $singleContent->pivot->submission_link }}
                                    </a>
                                </div>
                            @endif
                            @if($singleContent->pivot->submission_file_path)
                                <div style="margin-bottom: 6px; font-size: 0.9rem;">
                                    <strong>Submitted File:</strong>
                                    <a href="{{ asset('storage/' . $singleContent->pivot->submission_file_path) }}" target="_blank" style="color: #2563EB; text-decoration: underline;">
                                        Download File
                                    </a>
                                </div>
                            @endif
                            @if($singleContent->pivot->score)
                                <div style="font-size: 0.9rem;">
                                    <strong>Score:</strong>
                                    <span style="font-weight: 700; color: #D97706; background: #FEF3C7; border: 1px solid #FCD34D; padding: 2px 10px; border-radius: 6px;">
                                        {{ $singleContent->pivot->score }}
                                    </span>
                                </div>
                            @endif
                        </div>
                    @endif

                    <form action="{{ route('content.submit-exercise', [$moduleContent->id, $singleContent->id]) }}" method="POST" enctype="multipart/form-data">
                        @csrf
                        @php
                            $scoreParts = explode('/', $singleContent->pivot->score ?? '');
                            $obtained = $scoreParts[0] ?? '';
                            $total = isset($scoreParts[1]) ? $scoreParts[1] : '';
                        @endphp
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                            <div>
                                <label style="display: block; font-size: 0.875rem; font-weight: 600; color: #78350F; margin-bottom: 6px;">Provide Answer Link</label>
                                <input type="url" name="submission_link" value="{{ old('submission_link', $singleContent->pivot->submission_link) }}" style="width: 100%; padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.875rem; outline: none;" placeholder="https://github.com/... or Google Doc URL">
                                @error('submission_link') <span style="color: #DC2626; font-size: 0.75rem; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label style="display: block; font-size: 0.875rem; font-weight: 600; color: #78350F; margin-bottom: 6px;">Or Upload Answer File</label>
                                <input type="file" name="submission_file" style="width: 100%; padding: 6px 12px; background: white; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.875rem;">
                                @error('submission_file') <span style="color: #DC2626; font-size: 0.75rem; display: block; margin-top: 4px;">{{ $message }}</span> @enderror
                            </div>
                        </div>

                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 0.875rem; font-weight: 600; color: #78350F; margin-bottom: 6px;">Exercise Score (Fraction format, e.g. 9/10)</label>
                            <div style="display: flex; align-items: center; gap: 8px; max-width: 300px;">
                                <input type="number" step="any" name="obtained_score" value="{{ old('obtained_score', $obtained) }}" placeholder="Score (e.g. 9)" style="flex: 1; padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.875rem; outline: none; background: white;">
                                <span style="font-weight: bold; font-size: 1.25rem; color: #78350F;">/</span>
                                <input type="number" step="any" name="total_score" value="{{ old('total_score', $total) }}" placeholder="Total (e.g. 10)" style="flex: 1; padding: 8px 12px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 0.875rem; outline: none; background: white;">
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; align-items: center; margin-top: 16px;">
                            <button type="submit" style="background-color: #D97706; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 2px 4px rgba(217, 119, 6, 0.2);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                {{ ($singleContent->pivot->submission_link || $singleContent->pivot->submission_file_path || $singleContent->pivot->score) ? 'Update Submission' : 'Submit Answer' }}
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

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; display: flex; justify-content: flex-end;">
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
        </div>
    </div>
</div>
