<?php

namespace Kraenkvisuell\StatamicReps\Translations;

use Statamic\Facades\Site;
use Statamic\Policies\GlobalSetVariablesPolicy as StatamicPolicy;

/**
 * Another site's global variables are edited in the default site's form (Translations\Sites), so
 * the site dropdown of a global set offers only the default site. Translations\Listener saves
 * them without a policy check.
 */
class GlobalVariablesPolicy extends StatamicPolicy
{
    public function before($user, $ability = null, $variables = null)
    {
        if (Translations::enabled() && $variables && $variables->locale() !== Site::default()->handle()) {
            return false;
        }

        return parent::before($user);
    }
}
