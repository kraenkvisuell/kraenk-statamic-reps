# kraenkvisuell/kraenk-statamic-reps

Statamic 6 addon with the **backend** shared by kraenkvisuell's artist representation sites
(kombinatrotweiss, wildfoxrunning, visualeyes, aiartists), which replaced their Laravel Nova 3 sites.
Frontends (Antlers, CSS, JS) stay in each site; this package holds the content model, the import and
re-sync from the old Nova sites, SEO, the lightbox and the portfolio queries. Everything a site does
differently is set in its `config/kraenk-statamic-reps.php`, which is merged over
[`config/kraenk-statamic-reps.php`](config/kraenk-statamic-reps.php) (every key has a default there;
associative arrays merge recursively, lists replace).


## Slideshows field of the artist

The artist's `slideshows` field is a `reps_slideshows` field (an entries field, `Fieldtypes\Slideshows`)
with rows like the old Nova list: a position dropdown (plus drag), the categories under the title,
the works' thumbnails (`cp.slideshow_thumbnails`, videos play muted), the number of works (opens the
slideshow in a stack) and actions: edit, publish/unpublish, hide in / show in the artist overview
(feature `overview`), delete (with a confirmation modal, for users who may delete slideshows; the
slideshow is deleted right away and leaves the artist's list); no unlink, a slideshow always belongs to its artist (only a slideshow that no longer exists can be unlinked). Instead of Statamic's
"Create"/"Link" buttons it has an "Add slideshow" button (the `labels.slideshow` label) on the right
above the list (adds the new slideshow at position 1) and below it (adds it at the end); new
slideshows start as drafts (`default_status: draft`, written by `reps:install`). The actions save the slideshow right away
(`Http\Controllers\Cp\SlideshowItemController`); the order is saved with the artist. The row is
`reps-slideshow-item` in `resources/js/cp.js`, which extends Statamic's own relationship row.

## Slideshows tab of a category

With the feature `category_slideshow_order` (off by default; on at kombinatrotweiss) a category has a
"Slideshows" tab after the main tab, like the old Nova list: the slideshows its page shows (with
`represents_in_categories` only those with a work chosen to represent the artist in the category,
like Nova's `category_slideshows_are_filtered`), titled "Artist / Slideshow", with the artist's rows
but only the works the category page shows as thumbnails, no badges and only "Edit" as action.
Only the order is edited there (no link, create or unlink: a slideshow's categories are set in the
slideshow); it's saved as the category's `slideshow_order`, which `Portfolio::categorySlideshows`
follows (the others newest first). `Fieldtypes\CategorySlideshows`, `reps_category_slideshows-fieldtype`
in `resources/js/cp.js`.
## Works of a slideshow

The collapsed work rows show what the old Nova list showed: the file as a large preview (videos
play), a badge per flag or category that is set ("Übersicht", "Portfolio-Bild",
"Übersicht: Portrait", "Kategorieseite: Portrait"), then the title. The on/off flags (show in overview, portfolio
image, start page image, discipline image – whichever the site uses) are switches (one per line,
label to the right) on top of the
expanded row, saved with the slideshow. `Listeners\AdjustBlueprints::workRows` moves and marks the
fields (`reps_large_preview`, `reps_flag`, `replicator_preview: false` for the rest);
`resources/js/cp.js` extends Statamic's assets, toggle and entries fieldtypes only for marked fields.

"Add images" below the works (`add_works`, `Fieldtypes\AddWorks`) is an assets field for several
files at once (upload, drop or choose from the container): each file becomes an "Image / video"
work with the set's defaults, appended collapsed to the end of the works right away, in the order
the uploads finish (one at a time, in the order chosen). The field itself saves nothing. Config:
`works_field` (default `works`), `set` (default `image`) and the assets field's own (`container`,
`folder`). Statamic's replicator only reads its collapsed rows once, so cp.js lets every
replicator follow `meta.collapsed` when it changes from outside.

## Translations (multi-site)

On a multi-site site (kombinatrotweiss) every language is edited in the default site's form; the
"Working in" panel and the site dropdown of the globals are hidden, and the edit page of a
localization opens its origin (feature `translatable_fields`, on by default, no effect on single-site
sites). Built on `daun/statamic-multisite-field`:

- every `localizable: true` field of the addon's blueprints (and of `<blueprint>_extra` fieldsets)
  becomes a `reps_translatable` field: one input per language behind a "Language" switch (in the
  sidebar, on global sets on top of each tab); untranslated values show the German text greyed out,
- `title` and `slug` stay plain fields (Statamic needs them as strings), followed by
  "Title (English)" / "Slug (English)" (`reps_site_value`; empty: like German).

The data stays where Statamic keeps it: the German value on the origin, the English one on the
localization (the fields stay `localizable`, the eloquent driver stores only those as a
localization's own values). So templates, routing, SEO and `reps:import` / `reps:resync` are
unchanged. `Translations\Listener` writes the other languages to the localizations when the origin
is saved from the CP (an emptied field falls back to German); saves without these values (the
import) pass untouched.

## Position column in CP listings

Collections listed in `cp.position_columns` (orderable ones, e.g. `['artists', 'categories']`) get a
position select as the first column of their CP listing (`Cp\PositionColumn`, a computed
`reps_position` field added to the blueprint, the index component in `resources/js/cp.js`).
Picking a position moves the entry there right away (`POST cp/reps/position/{id}`, needs the
"reorder" permission) and reloads the listing. The order is saved through the collection's
tree like a drag in the CP, so static caching invalidates as for any reorder.
New entries of the orderable collections in `cp.new_entries_first` (e.g. `['news']`) start at
position 1 instead of last (`Listeners\NewEntriesFirst`, on `EntryCreated`; quiet saves of the
import and re-syncs keep their order).

## Simple translations (one site, kombinatrotweiss)

With [bastihilger/simple-statamic-translations](https://github.com/bastihilger/simple-statamic-translations)
installed, a single site and the feature `simple_translations` on, every language lives in the same
entry instead of a site per language (`Translations\SimpleTranslations`):

- every localizable field of the addon's blueprints (and of `<blueprint>_extra` fieldsets) is
  wrapped in a translated field of its own handle, so templates read it as before
  (`{{ description }}` is the current language's); `title` and `slug` stay plain and get
  `title_translation` / `slug_translation`; `robots` stays plain (the same in every language),
- the language comes from the URL (`/de/…`, `/en/…`, `{language}` at the start of every
  collection route),
- PHP that reads values raw uses `SimpleTranslations::read($item, $key)` (the SEO tag, menu
  labels), language links and hreflang `SimpleTranslations::urls($entry)`,
- `reps:import` / `reps:resync` write the other languages into the translated fields
  (`SimpleTranslations::merge`: other languages only where they differ, idempotent) instead of
  localizations; `legacy.locales` maps the legacy locales to language codes
  (`['de' => 'default', 'en' => 'en']`), `seo.locales` the og:locale per language.

## Install in a site

`composer require kraenkvisuell/kraenk-statamic-reps:^0.1` (from Packagist). For working
on the addon next to a site, point it at a path repository (`../kraenk-statamic-reps`, symlinked) locally
without committing that.

## Releases

Versions are git tags (`v0.1.0`, …). Under 1.0 a minor bump (`v0.2.0`) means sites need changes, a
patch (`v0.1.1`) is a fix. Release: commit, run the sites' tests against the change, `git tag -a v0.x.y
-m v0.x.y && git push --follow-tags`; then per site `composer update kraenkvisuell/kraenk-statamic-reps`
(widen the constraint for a minor bump), test, commit `composer.lock`.

Then: `php artisan vendor:publish --tag=kraenk-statamic-reps-config`, set the site's options,
`php artisan reps:install` (writes collections, globals, navigations, the asset container),
`php artisan migrate`, `php artisan reps:import`.

## What it provides

- **Content model.** Collections `pages`, `artists`, `slideshows` (works as replicator rows),
  `categories`, `disciplines`, optional `news`, `productions`, `special_categories` +
  `special_projects`, `projects` + `skills`, `clients`; orderable (a tree) except those in
  `unordered_collections` (e.g. `['pages']`, left without a structure). Blueprints live in
  `resources/collection-blueprints/` and are registered as namespaces, so they are not copied into
  the sites; `Listeners\AdjustBlueprints` inlines the addon fieldsets (`reps::seo`, `reps::main_content`,
  `reps::work_common` …), removes fields whose `reps_feature` is off and blocks not in `page_blocks`,
  applies the labels and appends the site's own `<blueprint>_extra` fieldset. A site can still
  override a blueprint in `resources/blueprints/vendor/collections/<handle>/`. Global blueprints
  (`site_settings`, `seo`) are fallbacks. Computed values for routes: `artist_slug` (slideshows),
  `special_category_slug`, `discipline_slug` (artists), `route_segment` (categories).
  `Listeners\SyncRelations` keeps both sides of artist `slideshows` ↔ slideshow `artist` (special
  category `projects` ↔ special project `special_category`): saving the parent sets the children's
  field; "Add slideshow" opens the create form with the artist filled in, and the saved
  slideshow joins the artist's list even when the artist form isn't saved; an artist's list always
  holds exactly the slideshows pointing at it (a form opened before a slideshow was added can't
  drop it; a slideshow given another artist moves). The artist is hidden in the stacks an artist
  opens and a select on the collection's own pages (`AdjustBlueprints::artistSelect`).
  `Listeners\RemoveDeletedChildren` takes a deleted slideshow / special project off the list
  (Statamic doesn't remove entry references; the CP showed "This item is unavailable"), and
  `Listeners\LogDeletions` logs every deleted entry with the user. `cp.nested_collections` (e.g.
  `['slideshows']`) leaves collections edited inside their parent out of the CP nav, like
  `special_projects`. Classes in `src/Listeners` register themselves.
- **`reps:install`** writes the structure files the config describes, touching only the keys it
  manages. Repeatable.
- **`reps:import`** imports the old Nova site from its dump on the `legacy` connection
  (`LEGACY_DB_*`). Nova is the source until go-live: imported entries (found by `legacy_id`, ids are
  deterministic) are made to match the dump, rows deleted in Nova are deleted, entries created in
  Statamic are never touched. Rich text → Bard, nova-cms blocks → `main_content` sets, settings →
  globals (`legacy.settings`, incl. grids), menus → navigations, multi-locale → localizations.
  `legacy.robots => false` imports everything as indexed and followed, for old themes that wrote
  `index, follow` whatever the robots checkboxes said (the new site respects them).
  A second run reports nothing to do. Options `--only=`, `--no-media`, `--keep-deleted`, `--dry-run`.
- **Media.** An asset's path is the old media folder + file name, so it never changes between runs.
  The local `assets` disk (`public/assets`) mirrors the old originals the content uses (rsync with
  `--files-from` from the server, an S3 disk or a public URL); with the container on Bunny only new
  and replaced files are uploaded, and only replaced files lose their Glide images.
- **`reps:resync`** dumps the live Nova database again (read-only, ssh), reports the differences, and
  with `--apply` backs up the content tables, imports and syncs media incrementally, then renders only
  the pages of changed entries. `--restore=<backup>` undoes an apply.
- **SEO** (instead of SEO Pro): the Nova SEO tab as `reps::seo` on every routable blueprint, a `seo`
  global for the home page and listing pages (`seo.index_pages`), `{{ reps:seo }}` for the head
  (title, description, keywords, robots, canonical, hreflang, og/twitter with configurable og:image
  fallbacks), `/sitemap.xml`.
- **Routes:** `/sitemap.xml`, slideshow URLs with a trailing slide number, 301s from old id URLs
  (`legacy.id_redirects`), the lightbox endpoints under `/!/reps/lightbox`. On multi-site installs
  a 404 that is an entry's URL in another site (`/en/<german slug>`) redirects to the entry in the
  requested site (`localized_slug_redirects`).
- **German CP.** Blueprint labels, instructions, options, collection/global/navigation titles are
  written in English and translated in `lang/de.json` (the CP passes them through `__()`). A site
  gets a German CP with `APP_LOCALE=de` (default in its `config/app.php`) and can override single
  strings in its own `lang/de.json`. Keep `lang/de.json` complete when adding fields.
- **Lightbox:** a per-visitor list of works in the session, sortable per artist, as PDF (dompdf, view
  from the site).
- **Portfolio queries** (`Portfolio`, `{{ reps:works }}`, `{{ reps:artist_categories }}`,
  `{{ reps:category_artists }}`, `{{ reps:overview }}`, `{{ reps:slideshows }}`, `{{ reps:rows }}`).
- **Storage:** the `bunny-assets`, `bunny-glide-cache`, `assets` and `legacy-media` disks
  (from `BUNNY_*` / `LEGACY_S3_*`) unless the site defines them; `reps:copy-assets-to-bunny`,
  `reps:warm-glide`, `reps:refresh-uris`. The Bunny disks use the `bunny` driver from
  statamic-bunny-assets: S3 with failed reads retried (disk keys `retries` / `retry_delay`), because
  Bunny's replicas are sometimes out of step and answer 404 or an error for files that exist.
- **CP artist list** like the old Nova index (`Cp\ArtistListing`, computed `reps_*` fields in the
  artist blueprint that only appear in the list): the name with `/slug` underneath (title and slug
  columns hidden by default), "Copy Link" / "Copy Link 2" buttons copying `a:<Nova id>` /
  `b:<Nova id>` (feature `artist_copy_links`), portfolio images (`cp.artist_portfolio_images`,
  label `labels.portfolio_images`), start page images (feature `startpage_images`), disciplines,
  the colour as swatch and hex code (feature `artist_bgcolor`). Disciplines have a colour field only with the feature `discipline_bgcolor`. The CP page list shows the page
  colour the same way (feature `page_bgcolor`, `Cp\ColorColumn`), the news list too (feature
  `work_bgcolor`).
- **Slideshow of a news item** (`Fieldtypes\ArtistSlideshows`): the select only offers the
  slideshows of the artist chosen above it (none without an artist; Statamic's own select loaded
  every slideshow in one request, ~2,000 on wfr). Changing or clearing the artist clears the
  slideshow and reloads the options; both are clearable.
- **Entry order without a save per entry** (`Entries\EloquentEntryRepository`, eloquent driver):
  when a structure changes (a new news item goes first, an entry is deleted or moved) the driver
  queued a job per moved entry that saved it again (~2,200 jobs for one news item on wfr, 50 s
  with a sync queue). The order is written straight into the `order` column instead; only entries
  whose parent changed are still saved.
- **News from a slideshow** (feature `news_from_project`, wfr, `Fieldtypes\CreateNews`): a saved
  slideshow has a "Create news item" button in its sidebar that opens the news form in a stack
  with the artist and the slideshow filled in. Not shown while the slideshow is being created.
- **Generated news titles** (feature `news_headline`, wfr): the text on a news item is its
  `headline` (the old title); the title is "Artist / Project" (`Support\NewsTitle`: the project's
  artist when the item has none, else the headline or subtitle, else "News"), via the collection's
  `title_format: '{reps_title}'` (written by `reps:install`) and the computed `reps_title`, hidden in
  the form. The import writes the same title, and the slug from it (old id appended only where two
  items share one); an empty slug in the CP follows the title, with -2, -3 … (`Listeners\NewsSlug`).
  Opt-in like the old Nova sites that had it: `cp.artist_portfolio_images_fallback` fills empty
  slots with works shown in overviews, then the first works; `cp.artist_portfolio_images_legacy_order`
  looks for them in the slideshows by Nova id; `cp.listing_videos` plays videos in the image columns
  instead of a file icon (`reps_listing_assets` fieldtype).
  HTML cells use the `reps_html` fieldtype, whose index component is in `resources/js/cp.js` (plain
  JS, no build; published to `public/vendor/kraenk-statamic-reps` by `statamic:install` as
  `cp.<content hash>.js`, like `cp.css`: Bunny pull zones ignore query strings, so Statamic's
  `cp.js?v=<version>` was served the previous release's file, and browsers kept it for its max-age).
- **Asset metadata cache:** every image a page renders needs its asset's metadata (`.meta/*.yaml` on
  Bunny); uncached that's one Bunny request per image, minutes for an artist page. Sites give it a
  cache store of its own (`asset_meta` in `config/cache.php`), which `cache:clear` – run by composer's
  `statamic:install` on every install – leaves alone. statamic-bunny-assets's
  `bunny-assets:warm-asset-meta` loads all metadata in parallel (about 5 minutes for 15,000 assets; seconds when everything is cached), in the deploy
  script before `reps:warm`. Metadata edited in another environment (the bucket is shared) stays
  cached until `php artisan cache:clear asset_meta`.
- **Re-caching the site:** `reps:warm [--queue] [--insecure] [--dry-run]` clears the static cache
  and warms the pages in the order visitors reach them: the home page(s), every internal link in
  the home page's `<nav>` elements (the menu, whatever builds it), the home page's other internal
  links (news, teasers), the published artists, categories and special projects, then every
  published project of every published artist (artists by name, projects in the artist's order).
  A URL is warmed once, in the first tier that lists it, so a tier whose pages are all in the menu
  shows 0 URLs. Every language (simple translations) or site. Everything else is cached on its
  first visit. Deploys run it, and so should a site's invalidator wherever a save changes every
  page (menus, globals …). Queued, Horizon renders the pages in that order. With
  `--queue` it first clears its queue (`static_caching.warm_queue`, give it a queue of its own,
  e.g. `warm`) and releases the unique locks of the jobs it drops (`queue:clear` leaves them
  behind; the new run's jobs for the same URLs would be dropped silently).
- **Own queue and job:** with `static_caching.warm_queue` set (e.g. `warm`), `reps:warm` queues
  there, with its own job (`DeployWarmJob`, so a waiting run doesn't swallow a save's re-cache of
  the same URL), and Statamic's `warm_queue` (e.g. `recache`) only carries the re-caching of single
  pages after saves. Let Horizon take the queues in order without balancing
  (`'queue' => ['recache', 'default', 'warm']`, `'balance' => false`).
- **Warm requests through the CDN:** the reps sites cache their pages at the Bunny pull zone
  (statamic-bunny-cache's `bunny` strategy), and only a request through it fills that cache, so
  every warm request goes to the domain. Bunny gives up on a slow first render (a 504 after its
  origin timeout) while PHP finishes it; the warm job tries again a minute later, up to 3 times.
  (`static_caching.warm_ip` / `REPS_WARM_IP`, which sent warm requests past the CDN, is gone since
  v0.2.6: with the `bunny` strategy the pages warmed that way never reached the cache.)
- **Bunny purges:** with `static_caching.bunny` (`BUNNY_API_KEY`, `BUNNY_PULL_ZONE_ID`) the pull zone
  in front of the site follows the static cache: every URL a warm job has just rendered and every
  invalidated URL is purged, the whole zone when the static cache is cleared (`reps:warm`,
  `static:clear`). Otherwise Bunny keeps its copy until it expires, and saves don't reach visitors.
  Off with the `bunny` strategy of [statamic-bunny-cache](https://github.com/kraenkvisuell/statamic-bunny-cache)
  (the pull zone is the static cache and that addon purges pages by tag).
- **Special projects only inside their category:** the `special_projects` collection is removed from
  Collections in the CP nav; its entries are edited through the special category's projects field.
- **From [statamic-bunny-assets](https://github.com/kraenkvisuell/statamic-bunny-assets)** (required
  by this addon, which only registers the disks with its `bunny` driver): CP thumbnails from the
  Glide CDN, original GIFs, uploads that don't drop each other's files from the asset listing, the
  asset meta warmer (`bunny-assets:warm-asset-meta`). Switched in that addon's own config
  (`statamic-bunny-assets.cdn_thumbnails`, `.original_gifs`); reps has no settings of its own for
  them (since v0.2.0; `cp.cdn_thumbnails`, `imaging.original_gifs` and `reps:warm-asset-meta` are gone).
- **Warm job with an expiring lock:** the addon sets `static_caching.warm_job` (when the site leaves
  it empty) to `StaticCaching\WarmJob`, Statamic's job with a one-hour unique lock. Statamic's
  lock never expires: a warm job removed from the queue without running blocked its URL for good.

## License

MIT, see [LICENSE](LICENSE).
