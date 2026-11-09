# kraenkvisuell/kraenk-statamic-reps

Private Statamic 6 addon with the **backend** shared by kraenkvisuell's artist representation sites
(kombinatrotweiss, wildfoxrunning, visualeyes, aiartists), which replaced their Laravel Nova 3 sites.
Frontends (Antlers, CSS, JS) stay in each site; this package holds the content model, the import and
re-sync from the old Nova sites, SEO, the lightbox and the portfolio queries. Everything a site does
differently is set in its `config/kraenk-statamic-reps.php`, which is merged over
[`config/kraenk-statamic-reps.php`](config/kraenk-statamic-reps.php) (every key has a default there;
associative arrays merge recursively, lists replace).

## Install in a site

```jsonc
// composer.json
"repositories": {
    "reps": { "type": "vcs", "url": "https://github.com/kraenkvisuell/kraenk-statamic-reps" }
}
```

`composer require kraenkvisuell/kraenk-statamic-reps:^0.1` (needs GitHub access to the private repo, e.g.
`composer config --global github-oauth.github.com <token>` / Forge's GitHub integration). For working
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
  `special_projects`, `projects` + `skills`, `clients`. Blueprints live in
  `resources/collection-blueprints/` and are registered as namespaces, so they are not copied into
  the sites; `Listeners\AdjustBlueprints` inlines the addon fieldsets (`reps::seo`, `reps::main_content`,
  `reps::work_common` …), removes fields whose `reps_feature` is off and blocks not in `page_blocks`,
  applies the labels and appends the site's own `<blueprint>_extra` fieldset. A site can still
  override a blueprint in `resources/blueprints/vendor/collections/<handle>/`. Global blueprints
  (`site_settings`, `seo`) are fallbacks. Computed values for routes: `artist_slug` (slideshows),
  `special_category_slug`, `discipline_slug` (artists), `route_segment` (categories).
- **`reps:install`** writes the structure files the config describes, touching only the keys it
  manages. Repeatable.
- **`reps:import`** imports the old Nova site from its dump on the `legacy` connection
  (`LEGACY_DB_*`). Nova is the source until go-live: imported entries (found by `legacy_id`, ids are
  deterministic) are made to match the dump, rows deleted in Nova are deleted, entries created in
  Statamic are never touched. Rich text → Bard, nova-cms blocks → `main_content` sets, settings →
  globals (`legacy.settings`, incl. grids), menus → navigations, multi-locale → localizations.
  A second run reports nothing to do. Options `--only=`, `--no-media`, `--keep-deleted`, `--dry-run`.
- **Animated GIFs keep their original file** (`imaging.original_gifs`): the Glide tag returns the
  asset's CDN URL for GIFs, like Nova did. Glide re-encoded every frame (minutes per GIF), upscaled
  small ones and produced files too large for a single upload to Bunny. `fm` still converts.
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
  `reps:warm-glide`, `reps:refresh-uris`.
- **CP artist list** like the old Nova index (`Cp\ArtistListing`, computed `reps_*` fields in the
  artist blueprint that only appear in the list): the name with `/slug` underneath (title and slug
  columns hidden by default), "Copy Link" / "Copy Link 2" buttons copying `a:<Nova id>` /
  `b:<Nova id>` (feature `artist_copy_links`), portfolio images (`cp.artist_portfolio_images`,
  label `labels.portfolio_images`), start page images (feature `startpage_images`), disciplines.
  HTML cells use the `reps_html` fieldtype, whose index component is in `resources/js/cp.js` (plain
  JS, no build; published to `public/vendor/kraenk-statamic-reps` by `statamic:install`).
- **Asset metadata cache:** every image a page renders needs its asset's metadata (`.meta/*.yaml` on
  Bunny); uncached that's one Bunny request per image, minutes for an artist page. Sites give it a
  cache store of its own (`asset_meta` in `config/cache.php`), which `cache:clear` – run by composer's
  `statamic:install` on every install – leaves alone. `reps:warm-asset-meta` loads all metadata in
  parallel (about 5 minutes for 15,000 assets; seconds when everything is cached), in the deploy
  script before `static:warm`. Metadata edited in another environment (the bucket is shared) stays
  cached until `php artisan cache:clear asset_meta`.
