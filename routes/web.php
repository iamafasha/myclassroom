<?php

use Illuminate\Support\Facades\Route;


Route::middleware('auth')->group(function () {
    \Livewire\Volt\Volt::route('/', 'classroom-dashboard')->name('home');
    \Livewire\Volt\Volt::route('/classes', 'classes.index')->name('classes.index');
    \Livewire\Volt\Volt::route('/classes/{classroom}', 'classes.show')->name('classes.show');
    \Livewire\Volt\Volt::route('/classes/{classroom}/courses/add', 'classes.courses-add')->name('classes.courses.add');
    \Livewire\Volt\Volt::route('/courses', 'courses.index')->name('courses.index');
    \Livewire\Volt\Volt::route('/course/{courseId}', 'classroom-dashboard')->name('course.show');
    \Livewire\Volt\Volt::route('/content/{moduleContentId}/add', 'create-content-form')->name('content.create');
    \Livewire\Volt\Volt::route('/content/{moduleContentId}/edit/{contentId}', 'create-content-form')->name('content.edit');
    \Livewire\Volt\Volt::route('/course/{courseId}/module/{moduleId}', 'classroom-dashboard')->name('course.module.show');
    \Livewire\Volt\Volt::route('/content/{moduleContent}', 'content-show')->name('content.show');

    Route::resource('files', App\Http\Controllers\FileController::class);
    
    Route::post('/content/{moduleContent}/toggle-complete', function (App\Models\ModuleContent $moduleContent) {
        $moduleContent->is_completed = !$moduleContent->is_completed;
        $moduleContent->save();
        return back();
    })->name('content.toggle-complete');

    Route::post('/content/{moduleContent}/content/{content}/submit-exercise', function (Illuminate\Http\Request $request, App\Models\ModuleContent $moduleContent, App\Models\Content $content) {
        $request->validate([
            'submission_link' => 'nullable|url',
            'submission_file' => 'nullable|file|max:51200',
        ]);

        if (!$request->submission_link && !$request->hasFile('submission_file')) {
            return back()->withErrors(['exercise' => 'Please provide an answer link or upload a file before submitting.']);
        }

        $pivot = App\Models\ContentModuleContent::where('module_content_id', $moduleContent->id)
            ->where('content_id', $content->id)
            ->firstOrFail();

        $answer = App\Models\ContentExerciseAnswer::firstOrNew([
            'user_id' => $request->user()->id,
            'content_module_content_id' => $pivot->id,
        ]);

        if ($request->submission_link) {
            $answer->submission_link = $request->submission_link;
        }

        if ($request->hasFile('submission_file')) {
            $path = $request->file('submission_file')->store('exercise_submissions', 'public');
            $answer->submission_file_path = $path;
        }

        $answer->save();

        $moduleContent->is_completed = true;
        $moduleContent->save();

        return back();
    })->name('content.submit-exercise');

    Route::post('/content/{moduleContent}/submit-quiz', function (Illuminate\Http\Request $request, App\Models\ModuleContent $moduleContent) {
        $quiz = $moduleContent->contents->first(fn ($content) => $content->contentable instanceof App\Models\QuizContent)?->contentable;
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