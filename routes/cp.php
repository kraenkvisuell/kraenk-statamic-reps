<?php

use Illuminate\Support\Facades\Route;
use Kraenkvisuell\StatamicReps\Http\Controllers\Cp\PositionController;
use Kraenkvisuell\StatamicReps\Http\Controllers\Cp\SlideshowItemController;

Route::get('reps/slideshows/{id}', [SlideshowItemController::class, 'show'])->name('reps.slideshows.show');
Route::post('reps/slideshows/{id}/toggle', [SlideshowItemController::class, 'toggle'])->name('reps.slideshows.toggle');
Route::post('reps/slideshows/{id}/first', [SlideshowItemController::class, 'first'])->name('reps.slideshows.first');
Route::delete('reps/slideshows/{id}', [SlideshowItemController::class, 'destroy'])->name('reps.slideshows.destroy');
Route::post('reps/position/{id}', [PositionController::class, 'update'])->name('reps.position.update');
