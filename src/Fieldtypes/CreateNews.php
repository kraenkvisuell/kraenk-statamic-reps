<?php

namespace Kraenkvisuell\StatamicReps\Fieldtypes;

use Kraenkvisuell\StatamicReps\Reps;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Fieldtypes\Entries;

/**
 * "Create news item" in a saved slideshow (feature news_from_project): opens the news create form
 * in a stack with the slideshow and its artist filled in (`?values[…][]=`). Stores nothing. The
 * button is `reps_create_news-fieldtype` in resources/js/cp.js (Statamic's relationship
 * CreateButton); a slideshow that isn't saved yet gets no button (no creatables, and the field is
 * hidden by Listeners\AdjustBlueprints).
 */
class CreateNews extends Entries
{
    protected static $handle = 'reps_create_news';

    protected $selectable = false;

    protected $component = 'reps_create_news';

    public function preProcess($data)
    {
        return null;
    }

    public function process($data)
    {
        return null;
    }

    public function preload()
    {
        return [
            'creatables' => $this->newsCreatables(),
            'formComponent' => $this->getFormComponent(),
            'formComponentProps' => $this->getFormComponentProps(),
            'formStackSize' => $this->getFormStackSize(),
        ];
    }

    protected function newsCreatables(): array
    {
        $slideshow = $this->field?->parent();
        $news = Reps::hasCollection('news') ? Collection::findByHandle('news') : null;

        if (! $slideshow instanceof EntryContract || ! $slideshow->id() || ! $news
            || ! User::current()?->can('create', [EntryContract::class, $news])) {
            return [];
        }

        $slideshow = $slideshow->root();
        $values = array_filter([
            'artist' => array_values(array_filter((array) $slideshow->get('artist'))),
            'slideshow' => [$slideshow->id()],
        ]);

        return [['url' => $news->createEntryUrl(Site::selected()->handle()).'?'.http_build_query(['values' => $values])]];
    }
}
