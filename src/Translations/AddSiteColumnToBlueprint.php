<?php

namespace Kraenkvisuell\StatamicReps\Translations;

use Statamic\Entries\AddSiteColumnToBlueprint as StatamicListener;
use Statamic\Events\EntryBlueprintFound;

/** The entry lists show only the default site's entries (Translations\Sites): no "Site" column. */
class AddSiteColumnToBlueprint extends StatamicListener
{
    public function handle(EntryBlueprintFound $event)
    {
        if (! Translations::enabled()) {
            parent::handle($event);
        }
    }
}
