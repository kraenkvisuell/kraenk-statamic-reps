<?php

namespace Kraenkvisuell\StatamicReps\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multi-site: a 404 whose path is an entry's URL in another site (e.g. /en/illustration/finanzen,
 * the German slug under the English prefix) is redirected to that entry's URL in the requested
 * site. The old Nova sites accepted every locale's slug, and links in the content still use them.
 */
class RedirectOtherSiteSlugs
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 404 || ! $request->isMethod('GET') || ! Reps::config('localized_slug_redirects') || Site::all()->count() < 2) {
            return $response;
        }

        $site = Site::findByUrl($request->url());
        if (! $site) {
            return $response;
        }

        $uri = '/'.trim(substr(rtrim($request->url(), '/'), strlen(rtrim($site->absoluteUrl(), '/'))), '/');

        foreach (Site::all()->reject(fn ($other) => $other->handle() === $site->handle()) as $other) {
            $entry = Entry::findByUri($uri, $other->handle());
            $target = $entry?->in($site->handle());

            if ($target && $target->published() && ($url = $target->absoluteUrl()) && $url !== $request->url()) {
                return redirect($url.($request->getQueryString() ? '?'.$request->getQueryString() : ''), 301);
            }
        }

        return $response;
    }
}
