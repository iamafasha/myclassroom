<?php

use Illuminate\Support\Facades\Route;


Route::middleware('auth')->group(function () {
    \Livewire\Volt\Volt::route('/', 'classroom-dashboard')->name('home');
    \Livewire\Volt\Volt::route('/classes', 'classes.index')->name('classes.index');
    \Livewire\Volt\Volt::route('/classes/{classroom}', 'classes.show')->name('classes.show');
    \Livewire\Volt\Volt::route('/classes/{classroom}/courses/add', 'classes.courses-add')->name('classes.courses.add');
    \Livewire\Volt\Volt::route('/courses', 'courses.index')->name('courses.index');
    \Livewire\Volt\Volt::route('/course/{courseId}', 'classroom-dashboard')->name('course.show');
    \Livewire\Volt\Volt::route('/modules/{moduleId}/content/create', 'create-content-form')->name('content.create');
    \Livewire\Volt\Volt::route('/course/{courseId}/module/{moduleId}', 'classroom-dashboard')->name('course.module.show');
    \Livewire\Volt\Volt::route('/content/{moduleContent}', 'content-show')->name('content.show');

    Route::resource('files', App\Http\Controllers\FileController::class);
    
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
        $quiz = $moduleContent->contents->first()?->contentable;
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
});

require __DIR__.'/auth.php';