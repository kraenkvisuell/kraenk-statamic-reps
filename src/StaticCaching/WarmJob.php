<?php

namespace Kraenkvisuell\StatamicReps\StaticCaching;

use Statamic\Console\Commands\StaticWarmJob;

/**
 * Statamic's warm job (static:warm --queue and background re-caching, through
 * static_caching.warm_job, which the addon sets when a site leaves it empty) with an expiring
 * unique lock. The job is unique per URL: while one waits, another for the same URL is dropped.
 * Statamic's lock never expires, so jobs removed from the queue without running (queue:clear,
 * a flushed Redis list) kept blocking their URLs: a later warm run queued nothing for them.
 * reps:warm releases the locks of the jobs it clears; the expiry covers every other way.
 */
class WarmJob extends StaticWarmJob
{
    public $uniqueFor = 3600;
}
