@component('mail::message')
# Your session is booked

{{ $isStudent ? 'You are meeting' : 'You are meeting with' }} **{{ $counterpart?->name ?? 'your counterpart' }}**@if($session->course) on **{{ $session->course->title }}**@endif.

@component('mail::panel')
**{{ $session->topic }}**

{{ $session->scheduled_at->format('l, j F Y') }} at {{ $session->scheduled_at->format('H:i') }} – {{ $session->endsAt()->format('H:i') }} ({{ $session->duration_minutes }} min)
@endcomponent

@if($session->mentor_note)
**Note from your mentor:** {{ $session->mentor_note }}
@endif

@if($session->meeting_link)
@component('mail::button', ['url' => $session->meeting_link])
Join the session
@endcomponent
@endif

@component('mail::button', ['url' => route('sessions.index'), 'color' => 'success'])
View in Classroom
@endcomponent

The calendar invite is attached — open it to add this to your calendar. You will get a reminder 15 minutes before it starts.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
