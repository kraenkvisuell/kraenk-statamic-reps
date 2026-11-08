<?php

use Illuminate\Support\Facades\Route;
use Kraenkvisuell\StatamicReps\Http\Controllers\LegacyRedirectController;
use Kraenkvisuell\StatamicReps\Http\Controllers\LightboxController;
use Kraenkvisuell\StatamicReps\Http\Controllers\SitemapController;
use Kraenkvisuell\StatamicReps\Http\Controllers\SlideshowIndexController;
use Kraenkvisuell\StatamicReps\Reps;

if (Reps::config('seo.sitemap')) {
    Route::get('sitemap.xml', SitemapController::class)->name('reps.sitemap');
}

// legacy URLs with old Nova ids: legacy.id_redirects = ['slideshows/{id}/{index?}' => 'slideshows', …]
foreach (Reps::config('legacy.id_redirects', []) as $pattern => $collection) {
    Route::get($pattern, LegacyRedirectController::class)
        ->where(['id' => '[0-9]+', 'index' => '[0-9]+'])
        ->defaults('reps_collection', $collection);
}

// a slideshow URL followed by the number of the slide to start at
if (Reps::config('slideshow_index_segment')) {
    Route::get('{path}/{index}', SlideshowIndexController::class)
        ->where(['path' => '(?!cp/|!/|img/|api/).+', 'index' => '[0-9]+'])
        ->name('reps.slideshow-index');
}

// the visitor's lightbox (session only, so no CSRF token: it can only change the visitor's own list
// and keeps the pages that show the buttons static-cacheable)
if (Reps::feature('lightbox')) {
    Route::prefix('!/reps/lightbox')
        ->middleware(Reps::feature('lightbox_requires_login') ? ['auth'] : [])
        // Laravel 13 calls the CSRF middleware PreventRequestForgery, older versions VerifyCsrfToken / ValidateCsrfToken
        ->withoutMiddleware([
            'Illuminate\\Foundation\\Http\\Middleware\\PreventRequestForgery',
            'Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken',
            'Illuminate\\Foundation\\Http\\Middleware\\VerifyCsrfToken',
        ])
        ->group(function () {
            Route::get('/', [LightboxController::class, 'state'])->name('reps.lightbox.state');
            Route::post('toggle', [LightboxController::class, 'toggle'])->name('reps.lightbox.toggle');
            Route::post('order', [LightboxController::class, 'order'])->name('reps.lightbox.order');
            Route::post('clear', [LightboxController::class, 'clear'])->name('reps.lightbox.clear');
            Route::get('download', [LightboxController::class, 'download'])->name('reps.lightbox.download');
        });
}
