<?php

namespace Kraenkvisuell\StatamicReps\Translations;

use Statamic\Facades\Site;
use Statamic\Policies\SitePolicy as StatamicPolicy;

/**
 * Nobody opens another site in the CP when translatable fields are on (see Translations\Sites):
 * the site dropdowns of collection trees and navigations offer only the sites a user may view.
 */
class SitePolicy extends StatamicPolicy
{
    public function before($user, $ability = null, $site = null)
    {
        if (Translations::enabled() && $ability === 'view' && $site && $site->handle() !== Site::default()->handle()) {
            return false;
        }

        return parent::before($user);
    }
}
