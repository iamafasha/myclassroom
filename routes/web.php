<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('classroom');
});

Route::get('/modules/{module}/content/create', function ($module) {
    return view('content.create', ['moduleId' => $module]);
});
