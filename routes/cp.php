<?php

use Illuminate\Support\Facades\Route;
use Kraenkvisuell\StatamicReps\Http\Controllers\Cp\SlideshowItemController;

Route::get('reps/slideshows/{id}', [SlideshowItemController::class, 'show'])->name('reps.slideshows.show');
Route::post('reps/slideshows/{id}/toggle', [SlideshowItemController::class, 'toggle'])->name('reps.slideshows.toggle');
