<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::inertia('/about', 'About')->name('about');

Route::middleware(['auth', 'verified'])->group(function () {
    // `dashboard` now lives in the Reporting module — it renders a real report, not a static page.
    // Living brand/design-system showcase (base component library examples).
    Route::inertia('design', 'DesignSystem')->name('design-system');
});

require __DIR__.'/settings.php';
