<?php

namespace Kraenkvisuell\StatamicReps\StaticCaching;

use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Console\Commands\StaticWarmJob;

/**
 * Statamic's warm job (reps:warm and the re-caching after saves, through
 * static_caching.warm_job, which the addon sets when a site leaves it empty) with an expiring
 * unique lock, sent past the CDN.
 *
 * The job is unique per URL: while one waits, another for the same URL is dropped. Statamic's
 * lock never expires, so jobs removed from the queue without running (queue:clear, a flushed Redis
 * list) kept blocking their URLs: a later warm run queued nothing for them. reps:warm releases the
 * locks of the jobs it clears; the expiry covers every other way.
 *
 * With static_caching.warm_ip set, the request connects to that address (the server itself)
 * instead of the domain's DNS: behind a CDN that ignores query strings in its cache key, the
 * re-cache request (?__recache=…) would be answered with the CDN's old copy and never reach the
 * server.
 */
class WarmJob extends StaticWarmJob
{
    public $uniqueFor = 3600;

    public function handle()
    {
        if ($ip = Reps::config('static_caching.warm_ip')) {
            $this->clientConfig['curl'][CURLOPT_RESOLVE] = [static::resolve((string) $this->request->getUri(), $ip)];
        }

        parent::handle();
    }

    /** curl's host:port:address entry for the URL. */
    public static function resolve(string $url, string $ip): string
    {
        $parts = parse_url($url);
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'https') === 'http' ? 80 : 443);

        return "{$parts['host']}:$port:$ip";
    }
}
