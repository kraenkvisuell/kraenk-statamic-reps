<?php

namespace Kraenkvisuell\StatamicReps\Translations;

use Statamic\Sites\Sites as StatamicSites;

/**
 * With translatable fields every language is edited in the default site's form, so the CP shows
 * no other site: only the default site is authorized and selected. Statamic builds every site
 * switch from these two (the header's site selector, the "Site" filter of the entry lists, the
 * site dropdown of trees, navigations and globals, the entries fieldtype), and the entry lists
 * only show the default site's entries. The frontend and the data are unaffected; the other
 * sites' trees follow the default site's (Translations\Listener).
 */
class Sites extends StatamicSites
{
    public function authorized()
    {
        $sites = parent::authorized();

        return Translations::enabled() ? $sites->only($this->default()->handle()) : $sites;
    }

    public function selected()
    {
        return Translations::enabled() ? $this->default() : parent::selected();
    }
}
