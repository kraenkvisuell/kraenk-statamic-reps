<?php

namespace Kraenkvisuell\StatamicReps\Jobs;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Renders pages inside the worker so their Glide images are generated on Bunny before a visitor
 * asks for them. With a Glide cache disk Statamic generates the images while the page renders,
 * which takes minutes for a gallery with hundreds of new photos (longer than the web timeout).
 */
class WarmPageImages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    /** @param  list<string>  $paths */
    public function __construct(public array $paths) {}

    public function uniqueId(): string
    {
        return implode('|', $this->paths);
    }

    public function handle(Kernel $kernel): void
    {
        foreach ($this->paths as $path) {
            $start = microtime(true);
            $response = $kernel->handle($request = Request::create(url($path)));
            $kernel->terminate($request, $response);

            Log::info(sprintf('Glide images for %s: %d in %.0fs', $path, $response->getStatusCode(), microtime(true) - $start));
        }
    }
}
