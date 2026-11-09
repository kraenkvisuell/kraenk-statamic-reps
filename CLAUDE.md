@~/Code/coding-guidelines/CLAUDE.md

# CLAUDE.md

Private Statamic addon `kraenkvisuell/kraenk-statamic-reps`: the shared **backend** of the artist
representation sites `~/Code/kombinatrotweiss`, `~/Code/wildfoxrunning`, `~/Code/visualeyes`,
`~/Code/aiartists` (rebuilt from the Nova sites `~/Code/krw`, `~/Code/wfr`, `~/Code/visualeyes-artists`,
`~/Code/ai-artists`). See README.md for what it provides. Frontend code (templates, CSS, JS) never goes
here – only content model, import/re-sync, SEO, lightbox, queries, storage.

- Every behaviour a site can change is a key in `config/kraenk-statamic-reps.php` with a default.
  Don't read `env()` outside that config file (config caching).
- The sites require tagged releases (`^0.1`) from the private GitHub repo; releasing is in README.md
  ("Releases"). To test a change before tagging, point a site at `../kraenk-statamic-reps` as a path
  repository locally (don't commit that) and run its tests – a change here changes all four.
- The import must stay idempotent: a second `reps:import --dry-run` reports nothing. Compare values
  with `Writer::same()` (Postgres jsonb reorders object keys), keep row ids deterministic
  (`Content::rowId`), never generate random ids for imported data.
- Asset paths must never change between runs (old folder + name), otherwise re-syncs would upload
  everything again and the Glide cache would be lost.
- New blueprint labels, instructions, options or button labels need a German entry in
  `lang/de.json` (kombinatrotweiss runs the CP in German, like its Nova backend).
- Test locally against a site: `cd ~/Code/aiartists && php artisan reps:import --dry-run`.
