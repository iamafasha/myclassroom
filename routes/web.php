<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('classroom');
})->name('home');

Route::get('/course/{course}', function (\App\Models\Course $course) {
    return view('classroom', ['courseId' => $course->id]);
})->name('course.show');

Route::get('/course/{course}/module/{module}', function (\App\Models\Course $course, \App\Models\Module $module) {
    return view('classroom', ['courseId' => $course->id, 'moduleId' => $module->id]);
})->name('course.module.show');

Route::resource('files', App\Http\Controllers\FileController::class);

Route::get('/modules/{module}/content/create', function ($module) {
    return view('content.create', ['moduleId' => $module]);
});

Route::get('/content/{moduleContent}', function (App\Models\ModuleContent $moduleContent) {
    return view('content.show', ['moduleContent' => $moduleContent]);
})->name('content.show');

Route::post('/content/{moduleContent}/toggle-complete', function (App\Models\ModuleContent $moduleContent) {
    $moduleContent->is_completed = !$moduleContent->is_completed;
    $moduleContent->save();
    return back();
})->name('content.toggle-complete');

