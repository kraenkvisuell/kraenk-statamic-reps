<?php

namespace Kraenkvisuell\StatamicReps\Listeners;

use Illuminate\Support\Facades\Log;
use Statamic\Events\EntryDeleted;
use Statamic\Facades\User;

/** Logs who deleted which entry (revisions are off, so nothing else records it). */
class LogDeletions
{
    public function handle(EntryDeleted $event): void
    {
        $entry = $event->entry;

        Log::info('Entry deleted', [
            'collection' => $entry->collectionHandle(),
            'id' => $entry->id(),
            'title' => $entry->value('title'),
            'slug' => $entry->slug(),
            'user' => User::current()?->email() ?? (app()->runningInConsole() ? 'console' : null),
        ]);
    }
}
