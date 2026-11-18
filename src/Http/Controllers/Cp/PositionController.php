<?php

namespace Kraenkvisuell\StatamicReps\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Kraenkvisuell\StatamicReps\Cp\PositionColumn;
use Statamic\Facades\Entry;
use Statamic\Http\Controllers\CP\CpController;

/** The position select of a CP listing (Cp\PositionColumn): moves the entry and saves the order. */
class PositionController extends CpController
{
    public function update(Request $request, string $id)
    {
        $entry = Entry::find($id);

        abort_unless($entry && in_array($entry->collectionHandle(), PositionColumn::collections(), true), 404);
        $this->authorize('reorder', $entry->collection());

        $moved = PositionColumn::move($entry, (int) $request->validate(['position' => 'required|integer|min:1'])['position']);

        return ['moved' => $moved, 'value' => PositionColumn::value(Entry::find($id))];
    }
}
