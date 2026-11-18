<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Statamic\Fields\Fieldtype;

/**
 * The position select of a CP listing (Cp\PositionColumn): the value is [position, total, url]
 * from a computed value; resources/js/cp.js registers the index component. Not for forms.
 */
class ListingPosition extends Fieldtype
{
    protected static $handle = 'reps_position';

    protected $selectable = false;

    public function preProcessIndex($value)
    {
        return is_array($value) ? $value : null;
    }
}
