<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('/about', 'About')->name('about');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    // Living brand/design-system showcase (base component library examples).
    Route::inertia('design', 'DesignSystem')->name('design-system');
});

require __DIR__.'/settings.php';
