<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::resources([
    'categories' => \App\Http\Controllers\CategoryController::class,
]);
