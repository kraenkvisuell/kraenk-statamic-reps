<?php

namespace Kraenkvisuell\StatamicReps\StaticCaching;

use Statamic\Console\Commands\StaticWarmJob;

/**
 * Statamic's warm job (reps:warm and the re-caching after saves, through
 * static_caching.warm_job, which the addon sets when a site leaves it empty) with an expiring
 * unique lock and retries.
 *
 * The job is unique per URL: while one waits, another for the same URL is dropped. Statamic's
 * lock never expires, so jobs removed from the queue without running (queue:clear, a flushed Redis
 * list) kept blocking their URLs: a later warm run queued nothing for them. reps:warm releases the
 * locks of the jobs it clears; the expiry covers every other way.
 *
 * The request goes to the domain, through the CDN: with statamic-bunny-cache's `bunny` strategy
 * the pull zone is the page cache, and only a request through it fills it (the origin shield,
 * and from there every edge). The CDN gives up on a slow first render (a 504 after its origin
 * timeout) while PHP finishes it, so the job tries again a minute later, when the page renders
 * fast.
 *
 * After the request, the URL is purged at Bunny (BunnyPurge, off with the `bunny` strategy).
 */
class WarmJob extends StaticWarmJob
{
    public $uniqueFor = 3600;

    public $tries = 3;

    public $backoff = 60;

    public function handle()
    {
        parent::handle();

        // the server has the new page now; the CDN in front of it must not keep the old one
        BunnyPurge::url((string) $this->request->getUri());
    }
}
