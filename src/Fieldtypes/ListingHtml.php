<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Statamic\Fields\Fieldtype;

/**
 * A computed listing column that renders HTML (the CP's default cell renders text only). The
 * value comes from a computed value (see Cp\ArtistListing); resources/js/cp.js registers the
 * index component. Not meant for forms: give the field `visibility: computed` and a condition
 * that never matches.
 */
class ListingHtml extends Fieldtype
{
    protected static $handle = 'reps_html';

    protected $selectable = false;

    public function preProcessIndex($value)
    {
        return (string) $value;
    }
}
