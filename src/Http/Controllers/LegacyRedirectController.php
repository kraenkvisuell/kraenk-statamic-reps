<?php

namespace Kraenkvisuell\StatamicReps\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/** 301 from an old URL with a Nova id to the imported entry (by legacy_id). */
class LegacyRedirectController
{
    public function __invoke(Request $request)
    {
        $origin = Entry::query()
            ->where('collection', $request->route()->defaults['reps_collection'])
            ->where('site', Site::default()->handle())
            ->where('legacy_id', (int) $request->route('id'))
            ->first();

        $site = Site::findByUrl($request->url()) ?? Site::default();
        $entry = $origin?->in($site->handle()) ?? $origin;

        abort_unless($entry && $entry->published() && $entry->url(), 404);

        $index = $request->route('index');

        return redirect($entry->url().($index ? "/$index" : ''), 301);
    }
}
