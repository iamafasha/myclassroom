@extends('layouts.app')

@section('content')
<div class="panel-list" style="width: 100%; padding: 40px; overflow-y: auto;">
    <div class="content-header">
        <a href="{{ route('home') }}" style="color: #4F46E5; text-decoration: none; font-weight: 500; display: inline-block; margin-bottom: 15px;">&larr; Back to Dashboard</a>
        <h1 class="content-title">{{ $moduleContent->label ?? 'Content' }}</h1>
    </div>

    <div class="content-card" style="margin-top: 20px; padding: 30px; display: block;">
        @php
            $contentable = $moduleContent->content->contentable ?? null;
            $type = $contentable ? class_basename($contentable) : 'Unknown';
        @endphp

        @if($type === 'NoteContent')
            <div style="line-height: 1.6; color: #374151;">
                {!! nl2br(e($contentable->content)) !!}
            </div>
        @elseif($type === 'PdfNotesContent')
            <div style="margin-bottom: 20px; padding: 15px; background-color: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; color: #1E3A8A; font-weight: 500;">
                @if($contentable->start_position && $contentable->end_position)
                    Please read from page {{ $contentable->start_position }} to {{ $contentable->end_position }}.
                @elseif($contentable->start_position)
                    Please start reading from page {{ $contentable->start_position }}.
                @else
                    Please read the attached PDF document.
                @endif
            </div>
            
            @php
                $pdfUrl = $contentable->file_url;
                if ($contentable->start_position) {
                    $pdfUrl .= '#page=' . $contentable->start_position;
                }
            @endphp
            
            <iframe src="{{ $pdfUrl }}" width="100%" height="800px" style="border: 1px solid #E5E7EB; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"></iframe>
        @elseif($type === 'VideoContent')
            <div style="margin-bottom: 20px; padding: 15px; background-color: #F3F4F6; border: 1px solid #E5E7EB; border-radius: 8px; color: #374151; font-weight: 500;">
                @if($contentable->start_time && $contentable->end_time)
                    Please watch from {{ $contentable->start_time }} to {{ $contentable->end_time }}.
                @elseif($contentable->start_time)
                    Please start watching at {{ $contentable->start_time }}.
                @else
                    Please watch the attached video.
                @endif
            </div>
            
            @php
                $videoUrl = $contentable->file_url;
                
                // Helper to convert MM:SS to seconds
                function timeToSeconds($time) {
                    if (!$time) return null;
                    $parts = explode(':', $time);
                    if (count($parts) == 2) {
                        return ($parts[0] * 60) + $parts[1];
                    }
                    if (count($parts) == 3) {
                        return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
                    }
                    return $time;
                }
                
                $tParam = '';
                $startSeconds = timeToSeconds($contentable->start_time);
                $endSeconds = timeToSeconds($contentable->end_time);
                
                if ($startSeconds !== null && $endSeconds !== null) {
                    $tParam = '#t=' . $startSeconds . ',' . $endSeconds;
                } elseif ($startSeconds !== null) {
                    $tParam = '#t=' . $startSeconds;
                }
                
                $videoUrl .= $tParam;
            @endphp
            
            <div style="border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #E5E7EB;">
                <video controls width="100%" style="display: block;">
                    <source src="{{ $videoUrl }}" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
            </div>
        @else
            <div style="color: #6B7280; text-align: center; padding: 40px;">
                Content not available.
            </div>
        @endif
    </div>
</div>
@endsection
