<?php

declare(strict_types=1);

use App\Modules\Core\Http\Controllers\Web\LocaleController;
use Illuminate\Support\Facades\Route;

// Language switcher — available to guests and authenticated users alike.
Route::post('locale', LocaleController::class)->name('locale.update');
