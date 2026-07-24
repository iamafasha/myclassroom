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

Route::post('/content/{moduleContent}/submit-exercise', function (Illuminate\Http\Request $request, App\Models\ModuleContent $moduleContent) {
    $request->validate([
        'submission_link' => 'nullable|url',
        'submission_file' => 'nullable|file|max:51200',
        'obtained_score' => 'nullable|numeric',
        'total_score' => 'nullable|numeric',
        'score' => 'nullable|string|max:50',
    ]);

    if (!$request->submission_link && !$request->hasFile('submission_file') && !$request->score && !$request->obtained_score) {
        return back()->withErrors(['exercise' => 'Please provide an answer link, upload a file, or enter a score before submitting.']);
    }

    if ($request->submission_link) {
        $moduleContent->submission_link = $request->submission_link;
    }

    if ($request->hasFile('submission_file')) {
        $path = $request->file('submission_file')->store('exercise_submissions', 'public');
        $moduleContent->submission_file_path = $path;
    }

    if ($request->filled('obtained_score') && $request->filled('total_score')) {
        $moduleContent->score = $request->obtained_score . '/' . $request->total_score;
    } elseif ($request->filled('obtained_score')) {
        $moduleContent->score = $request->obtained_score;
    } elseif ($request->has('score')) {
        $moduleContent->score = $request->score;
    }

    $moduleContent->is_completed = true;
    $moduleContent->save();

    return back();
})->name('content.submit-exercise');

Route::post('/content/{moduleContent}/submit-quiz', function (Illuminate\Http\Request $request, App\Models\ModuleContent $moduleContent) {
    $quiz = $moduleContent->content->contentable;
    $questions = $quiz->questions ?? [];
    
    $userAnswers = $request->input('answers', []);
    
    $correctCount = 0;
    $totalQuestions = count($questions);
    
    foreach ($questions as $qIndex => $q) {
        $expected = array_map('intval', $q['correct_answers'] ?? []);
        sort($expected);
        
        $given = isset($userAnswers[$qIndex]) ? array_map('intval', (array)$userAnswers[$qIndex]) : [];
        sort($given);
        
        if ($expected === $given) {
            $correctCount++;
        }
    }
    
    $moduleContent->score = $correctCount . '/' . $totalQuestions;
    $moduleContent->is_completed = true;
    $moduleContent->save();
    
    return back();
})->name('content.submit-quiz');

