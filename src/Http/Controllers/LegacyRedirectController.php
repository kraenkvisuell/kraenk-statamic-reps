<?php

namespace Kraenkvisuell\StatamicReps\Http\Controllers;

use Illuminate\Http\Request;
use Statamic\Facades\Entry;

/** 301 from an old URL with a Nova id to the imported entry (by legacy_id). */
class LegacyRedirectController
{
    public function __invoke(Request $request)
    {
        $entry = Entry::query()
            ->where('collection', $request->route()->defaults['reps_collection'])
            ->where('legacy_id', (int) $request->route('id'))
            ->first();

        abort_unless($entry && $entry->published() && $entry->url(), 404);

        $index = $request->route('index');

        return redirect($entry->url().($index ? "/$index" : ''), 301);
    }
}
