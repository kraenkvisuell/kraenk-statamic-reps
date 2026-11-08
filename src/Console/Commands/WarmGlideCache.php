<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

/**
 * Renders every page that can be reached by links from the home page, inside this process.
 * With the Glide cache on Bunny (GLIDE_CACHE_DISK=bunny-glide-cache), images are generated
 * while a page renders, so a page with many new images (a gallery) can take longer than the
 * web server's timeout. Run this after glide:clear or a large upload.
 */
#[Signature('reps:warm-glide {--limit=1000 : Maximum number of pages}')]
#[Description('Render all linked pages so their Glide images are generated')]
class WarmGlideCache extends Command
{
    public function handle(Kernel $kernel): int
    {
        $root = rtrim(config('app.url'), '/');
        $host = parse_url($root, PHP_URL_HOST);

        // With static caching on, these renders are what visitors get, so they must carry the
        // public host: a bare Request::create() is http://localhost and puts that into every
        // asset URL (Vite) of the cached pages.
        if (! $host || in_array($host, ['localhost', '127.0.0.1'])) {
            $this->error("APP_URL ({$root}) is not the public URL of the site. Nothing rendered.");

            return self::FAILURE;
        }

        $queue = ['/'];
        $seen = ['/' => true];
        $failed = 0;

        while ($queue && count($seen) <= $this->option('limit')) {
            $path = array_shift($queue);
            $start = microtime(true);
            $response = $kernel->handle($request = Request::create($root.$path));
            $kernel->terminate($request, $response);
            $seconds = microtime(true) - $start;

            if ($response->getStatusCode() !== 200) {
                $failed += $response->getStatusCode() >= 500 ? 1 : 0;
                $this->line(sprintf('  %d %s', $response->getStatusCode(), $path));

                // follow redirects on the same host (e.g. / → /de/ on multi-site installs)
                $location = $response->isRedirection() ? parse_url((string) $response->headers->get('Location')) : null;

                if ($location && (! isset($location['host']) || $location['host'] === $host)) {
                    $link = '/'.ltrim($location['path'] ?? '', '/');

                    if (! isset($seen[$link])) {
                        $seen[$link] = true;
                        $queue[] = $link;
                    }
                }

                continue;
            }

            if ($seconds > 5) {
                $this->line(sprintf('  %.0fs %s', $seconds, $path));
            }

            preg_match_all('/href="([^"#?]+)"/', (string) $response->getContent(), $matches);

            foreach ($matches[1] as $href) {
                $url = parse_url(html_entity_decode($href));

                if ((isset($url['scheme']) && ! in_array($url['scheme'], ['http', 'https'])) || (isset($url['host']) && $url['host'] !== $host)) {
                    continue;
                }

                $link = '/'.ltrim($url['path'] ?? '', '/');

                if (isset($seen[$link]) || preg_match('~^/(assets|img|build|cp|!)/|\.\w+$~', $link)) {
                    continue;
                }

                $seen[$link] = true;
                $queue[] = $link;
            }
        }

        $this->info(sprintf('Rendered %d pages.', count($seen) - count($queue)));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
