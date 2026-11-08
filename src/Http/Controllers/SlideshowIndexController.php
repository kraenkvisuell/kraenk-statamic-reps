<?php

namespace Kraenkvisuell\StatamicReps\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\FrontendController;

/**
 * /…/<slideshow>/<n>: renders the slideshow (templates read the slide number from
 * {{ last_segment }}); every other path goes on to Statamic.
 */
class SlideshowIndexController
{
    public function __invoke(Request $request, string $path, string $index)
    {
        $site = Site::findByUrl($request->url()) ?? Site::default();
        $uri = '/'.trim(Str::after('/'.$path, rtrim(parse_url($site->url(), PHP_URL_PATH) ?: '', '/')), '/');
        $entry = Entry::findByUri($uri, $site->handle());

        if ($entry && $entry->collectionHandle() === 'slideshows' && $entry->published()) {
            return $entry->toResponse($request);
        }

        return app(FrontendController::class)->index($request);
    }
}
