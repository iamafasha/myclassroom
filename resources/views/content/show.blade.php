@extends('layouts.app')

@section('content')
<div class="panel-list" style="width: 100%; padding: 40px; overflow-y: auto;">
    <div class="content-header">
        @php
            $module = $moduleContent->module;
            $course = $module ? $module->course : null;
            $backUrl = ($course && $module) ? route('course.module.show', ['course' => $course->id, 'module' => $module->id]) : route('home');
        @endphp
        <a href="{{ $backUrl }}" wire:navigate style="color: #4F46E5; text-decoration: none; font-weight: 500; display: inline-block; margin-bottom: 15px;">&larr; Back to Dashboard</a>
        <h1 class="content-title" style="{{ $moduleContent->is_completed ? 'text-decoration: line-through; color: #6B7280;' : '' }}">{{ $moduleContent->label ?? 'Content' }}</h1>
    </div>

    <div class="content-card" style="margin-top: 20px;  display: block;">
        @php
            $contentable = $moduleContent->content->contentable ?? null;
            $type = $contentable ? class_basename($contentable) : 'Unknown';
        @endphp

        @if($type === 'NoteContent')
            <div style="line-height: 1.6; color: #374151;">
                {!! nl2br(e($contentable->content)) !!}
            </div>
        @elseif($type === 'PdfNotesContent')
            
            @php
                $pdfUrl = $contentable->file_url;
                $startPage = $contentable->start_position ? (int)$contentable->start_position : 1;
                $endPage = $contentable->end_position ? (int)$contentable->end_position : 'null';
            @endphp
            
            <div style="display: flex; justify-content: center; gap: 15px; margin-bottom: 15px; align-items: center; background: #F3F4F6; padding: 10px; border-radius: 8px; border: 1px solid #E5E7EB;">
                <button id="zoom-out" style="padding: 6px 12px; background: white; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; font-weight: 500; color: #374151;">- Zoom Out</button>
                <span id="zoom-level" style="font-weight: bold; color: #111827; min-width: 60px; text-align: center;">150%</span>
                <button id="zoom-in" style="padding: 6px 12px; background: white; border: 1px solid #D1D5DB; border-radius: 4px; cursor: pointer; font-weight: 500; color: #374151;">+ Zoom In</button>
            </div>
            
            <div id="pdf-wrapper" style="width: 100%; overflow-x: auto; background: #F3F4F6; border-radius: 8px; border: 1px solid #E5E7EB;">
                <div id="pdf-container" style="display: flex; flex-direction: column; gap: 20px; align-items: center; padding: 20px; min-width: min-content;">
                    <p id="pdf-loading" style="color: #6B7280; font-weight: 500;">Loading PDF pages...</p>
                </div>
            </div>
            
            <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
            <script>
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
                
                const url = "{{ $pdfUrl }}";
                const startPage = {{ $startPage }};
                let endPage = {{ $endPage }};
                const container = document.getElementById('pdf-container');
                const loading = document.getElementById('pdf-loading');
                
                let currentScale = 1.5;
                let loadedPdf = null;
                
                function renderPages(pdf, scale) {
                    container.innerHTML = '';
                    
                    for (let pageNum = startPage; pageNum <= endPage; pageNum++) {
                        pdf.getPage(pageNum).then(function(page) {
                            const viewport = page.getViewport({scale: scale});
                            const canvas = document.createElement('canvas');
                            const ctx = canvas.getContext('2d');
                            canvas.height = viewport.height;
                            canvas.width = viewport.width;
                            canvas.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.1)';
                            
                            const renderContext = {
                                canvasContext: ctx,
                                viewport: viewport
                            };
                            page.render(renderContext);
                            
                            canvas.dataset.page = pageNum;
                            let inserted = false;
                            for (let i = 0; i < container.children.length; i++) {
                                if (container.children[i].dataset && parseInt(container.children[i].dataset.page) > pageNum) {
                                    container.insertBefore(canvas, container.children[i]);
                                    inserted = true;
                                    break;
                                }
                            }
                            if (!inserted) {
                                container.appendChild(canvas);
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
                
                document.getElementById('zoom-in').addEventListener('click', function() {
                    currentScale += 0.25;
                    document.getElementById('zoom-level').innerText = Math.round(currentScale * 100) + '%';
                    if (loadedPdf) renderPages(loadedPdf, currentScale);
                });
                
                document.getElementById('zoom-out').addEventListener('click', function() {
                    if (currentScale > 0.5) {
                        currentScale -= 0.25;
                        document.getElementById('zoom-level').innerText = Math.round(currentScale * 100) + '%';
                        if (loadedPdf) renderPages(loadedPdf, currentScale);
                    }
                });
            </script>
        @elseif($type === 'VideoContent')
            
            @php
                $videoUrl = $contentable->file_url;
                
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
                
                function getYoutubeId($url) {
                    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches)) {
                        return $matches[1];
                    }
                    return null;
                }
                
                $startSeconds = timeToSeconds($contentable->start_time);
                $endSeconds = timeToSeconds($contentable->end_time);
                $youtubeId = getYoutubeId($videoUrl);
            @endphp
            
            @if($youtubeId)
                <div style="position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; background: #000;">
                    <div id="yt-player" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"></div>
                </div>
                
                <script>
                    (function() {
                        var tag = document.createElement('script');
                        tag.src = "https://www.youtube.com/iframe_api";
                        var firstScriptTag = document.getElementsByTagName('script')[0];
                        firstScriptTag.parentNode.insertBefore(tag, firstScriptTag);

                        var player;
                        const ytVideoId = "{{ $youtubeId }}";
                        const ytStartSec = {{ $startSeconds ?? '0' }};
                        const ytEndSec = {{ $endSeconds ?? 'null' }};
                        let ytInterval = null;

                        window.onYouTubeIframeAPIReady = function() {
                            player = new YT.Player('yt-player', {
                                videoId: ytVideoId,
                                playerVars: {
                                    'start': ytStartSec,
                                    'end': ytEndSec ? ytEndSec : undefined,
                                    'rel': 0,
                                    'modestbranding': 1,
                                    'enablejsapi': 1
                                },
                                events: {
                                    'onReady': onPlayerReady,
                                    'onStateChange': onPlayerStateChange
                                }
                            });
                        };

                        function onPlayerReady(event) {
                            if (ytStartSec) {
                                player.seekTo(ytStartSec, true);
                            }
                        }

                        function onPlayerStateChange(event) {
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
                    })();
                </script>
            @else
                <div style="border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB; background: #000; position: relative;" id="video-container">
                    <video id="course-video" width="100%" style="display: block;">
                        <source src="{{ $videoUrl }}" type="video/mp4">
                        Your browser does not support the video tag.
                    </video>
                    
                    <!-- Custom Controls -->
                    <div id="video-controls" style="position: absolute; bottom: 0; left: 0; right: 0; background: rgba(0,0,0,0.7); padding: 10px 15px; display: flex; align-items: center; gap: 15px;">
                        <button id="play-pause" style="background: none; border: none; color: white; cursor: pointer; padding: 0; display: flex; align-items: center;">
                            <!-- Play Icon -->
                            <svg id="icon-play" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            <!-- Pause Icon (hidden) -->
                            <svg id="icon-pause" xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" viewBox="0 0 24 24" style="display:none;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                        </button>
                        <input type="range" id="seek-bar" value="0" min="0" max="100" style="flex: 1; cursor: pointer;">
                        <span id="time-display" style="color: white; font-size: 13px; font-family: monospace;">00:00 / 00:00</span>
                    </div>
                </div>
                
                <script>
                    document.addEventListener("DOMContentLoaded", function() {
                        const video = document.getElementById('course-video');
                        const playPauseBtn = document.getElementById('play-pause');
                        const iconPlay = document.getElementById('icon-play');
                        const iconPause = document.getElementById('icon-pause');
                        const seekBar = document.getElementById('seek-bar');
                        const timeDisplay = document.getElementById('time-display');
                        
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
        @else
            <div style="color: #6B7280; text-align: center; padding: 40px;">
                Content not available.
            </div>
        @endif

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
@endsection
