<?php

namespace Kraenkvisuell\StatamicReps\StaticCaching;

use Illuminate\Support\Facades\Http;
use Kraenkvisuell\StatamicReps\Reps;
use Throwable;

/**
 * Drops pages from the Bunny pull zone in front of the site, so a re-cached page is what visitors
 * get right away (static_caching.bunny: an account API key and the pull zone id). Without it Bunny
 * keeps its copy of a page until it expires (30 days for the static files, which carry no
 * Cache-Control): the server had the new page seconds after a save, visitors didn't.
 *
 * Purged: every URL a warm job has just rendered (WarmJob), every invalidated URL (UrlInvalidated:
 * deletions, changed slugs), the whole pull zone when the static cache is cleared (reps:warm,
 * static:clear). Failures are reported, never thrown: a page must still be re-cached when Bunny's
 * API is down.
 */
class BunnyPurge
{
    public static function enabled(): bool
    {
        return filled(Reps::config('static_caching.bunny.api_key')) && filled(Reps::config('static_caching.bunny.pull_zone'));
    }

    /** One URL, without its query string (the pull zone ignores query strings). */
    public static function url(string $url): void
    {
        if (! static::enabled()) {
            return;
        }

        $url = strtok($url, '?');

        static::request(fn ($http) => $http->post('https://api.bunny.net/purge?'.http_build_query(['url' => $url, 'async' => 'false'])));
    }

    /** Everything in the pull zone. */
    public static function all(): void
    {
        if (! static::enabled()) {
            return;
        }

        static::request(fn ($http) => $http->post('https://api.bunny.net/pullzone/'.Reps::config('static_caching.bunny.pull_zone').'/purgeCache'));
    }

    protected static function request(callable $send): void
    {
        try {
            $send(Http::withHeaders(['AccessKey' => Reps::config('static_caching.bunny.api_key')])->timeout(15)->retry(2, 500, throw: false))->throw();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
