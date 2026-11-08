<?php

namespace Kraenkvisuell\StatamicReps\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\Entry;

/**
 * Re-saves entries whose stored URI (entries.uri) no longer matches their route, e.g. after a
 * route in the config changed or a parent's slug changed. Repeatable.
 */
class RefreshUris extends Command
{
    protected $signature = 'reps:refresh-uris {--dry-run : Only report}';

    protected $description = 'Re-save entries whose stored URI is out of date';

    public function handle(): int
    {
        $count = 0;

        foreach (Reps::collections() as $collection) {
            $stored = DB::table('entries')->where('collection', $collection)->pluck('uri', 'id');

            foreach (Entry::query()->where('collection', $collection)->get() as $entry) {
                $uri = $entry->uri();

                if (($stored[$entry->id()] ?? null) === $uri) {
                    continue;
                }

                $count++;
                $this->line("  $collection: ".($stored[$entry->id()] ?? '–')." → $uri");

                if (! $this->option('dry-run')) {
                    $entry->saveQuietly();
                }
            }
        }

        $this->info($count ? "$count URIs ".($this->option('dry-run') ? 'out of date.' : 'refreshed.') : 'Nothing to do.');

        return self::SUCCESS;
    }
}
