@extends('layouts.app')

@section('content')
    <livewire:classroom-dashboard :course-id="$courseId ?? null" :module-id="$moduleId ?? null" />
@endsection

