<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('classroom');
})->name('home');

Route::resource('files', App\Http\Controllers\FileController::class);

Route::get('/modules/{module}/content/create', function ($module) {
    return view('content.create', ['moduleId' => $module]);
});

Route::get('/content/{moduleContent}', function (App\Models\ModuleContent $moduleContent) {
    return view('content.show', ['moduleContent' => $moduleContent]);
})->name('content.show');

