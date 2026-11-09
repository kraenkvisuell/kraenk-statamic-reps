<?php

namespace Kraenkvisuell\StatamicReps\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kraenkvisuell\StatamicReps\Translations\Translations;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

/**
 * Multi-site with translatable fields: every language is edited in the default site's form, so
 * the edit pages of localizations (e.g. from the list filtered by site) open the origin, and
 * the globals open in the default site.
 */
class EditInDefaultSite
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('GET') || ! Translations::enabled()) {
            return $next($request);
        }

        $route = $request->route()?->getName();

        if ($route === 'statamic.cp.collections.entries.edit') {
            $entry = $request->route('entry');
            $entry = is_string($entry) ? Entry::find($entry) : $entry;

            if ($entry && $entry->hasOrigin()) {
                return redirect($entry->root()->editUrl());
            }
        }

        if ($route === 'statamic.cp.globals.variables.edit' && $request->query('site') && $request->query('site') !== Site::default()->handle()) {
            return redirect($request->fullUrlWithQuery(['site' => Site::default()->handle()]));
        }

        return $next($request);
    }
}
