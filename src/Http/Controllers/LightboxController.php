<?php

namespace Kraenkvisuell\StatamicReps\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Kraenkvisuell\StatamicReps\Lightbox\Lightbox;
use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Statamic;

class LightboxController
{
    public function __construct(protected Lightbox $lightbox) {}

    public function state()
    {
        return ['keys' => $this->lightbox->keys(), 'count' => count($this->lightbox->keys())];
    }

    public function toggle(Request $request)
    {
        $active = $this->lightbox->toggle((string) $request->input('key'));

        return ['active' => $active] + $this->state();
    }

    public function order(Request $request)
    {
        $this->lightbox->order((array) $request->input('keys', []));

        return $this->state();
    }

    public function clear()
    {
        $this->lightbox->clear();

        return $this->state();
    }

    public function download()
    {
        $groups = $this->lightbox->grouped()->map(function ($group) {
            $group['works'] = collect($group['works'])->map(fn ($item) => $item + [
                'src' => (string) Statamic::tag('glide')->params(['src' => $item['asset'], 'width' => 1600, 'absolute' => true])->fetch(),
            ])->all();

            return $group;
        });

        abort_if($groups->isEmpty(), 404);

        $settings = GlobalSet::findByHandle('site_settings')?->in(Site::current()->handle());

        return Pdf::loadView(Reps::config('lightbox.pdf_view'), [
            'groups' => $groups,
            'settings' => $settings?->toAugmentedArray() ?? [],
        ])
            ->setPaper(Reps::config('lightbox.paper', 'a4'), Reps::config('lightbox.orientation', 'landscape'))
            ->setOption(['isRemoteEnabled' => true])
            ->download(Str::slug(config('app.name')).'-lightbox.pdf');
    }
}
