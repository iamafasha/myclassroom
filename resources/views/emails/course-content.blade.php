@component('mail::message')
# New {{ strtolower($typeLabel) }} added

**{{ $title }}** was just added to @if($course)**{{ $course->title }}**@else your course @endif@if($module) — {{ $module->title }}@endif.

@if($liveClass)
@component('mail::panel')
**{{ $liveClass->calendarTitle() }}**

{{ $liveClass->starts_at->format('l, j F Y') }} at {{ $liveClass->starts_at->format('H:i') }} – {{ $liveClass->endsAt()->format('H:i') }} ({{ $liveClass->duration_minutes ?: 60 }} min)
@endcomponent

@if($liveClass->description)
{{ $liveClass->description }}
@endif

The calendar invite is attached — open it to add this class to your calendar.
@endif

@component('mail::button', ['url' => $url])
Open it in Classroom
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
