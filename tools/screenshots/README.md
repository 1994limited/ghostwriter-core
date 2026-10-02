# Docs screenshots

Takes the screenshots for the three addons' docs and store pages from the local test sites, with headless Chrome. It is a development tool: `/tools` is left out of the package (`.gitattributes`).

```bash
php tools/screenshots/run.php statamic
php tools/screenshots/run.php filament
php tools/screenshots/run.php craft
```

Each run does the following:

1. **Saves the site's state.** It copies the folders, database or SQLite file that the run touches to `$TMPDIR/ghostwriter-shots/<cms>/`.
2. **Seeds every state the shots need,** without a model call. It writes Northfold content directly into the site's own storage:
   - guides, kinds and suggested kinds;
   - a content plan with a pending batch of suggestions;
   - conversations at each stage;
   - photo results and a made image;
   - a second user for the shared conversation;
   - the dashboard widget.
3. **Signs in** with the login in the site's `TESTING.md`.
4. **Takes each shot** in the [shot list](../../../ghostwriter/addon-reviews/docs-pass/shots.md), in a 1440×900 window at device scale 2, light unless the id ends in `-dark`. Shots go to the addon's `docs/images/<id>.png`.
5. **Frames the four store images** into `docs/store/0N-*.png` and `-2x.png`, at 1600×900 and 3200×1800.
6. **Puts the site back** exactly as it was, even when a shot fails or the run is stopped. A copy left by a run that was killed is put back at the start of the next one.

## Options

| Option | What it does |
|---|---|
| `--only=writing-draft,plan` | Take only these shots (`store/01-writing-panel` for one store image, `store` for all four) |
| `--no-store` | Skip the store images |
| `--list` | List the shot ids |
| `--keep` | Leave the seeded state in place afterwards, to look around the CP |
| `--restore` | Put back a site left by `--keep` |

## Where things are

By default the sites and addons are siblings of this checkout:
- `../gw-test-<cms>` for the test site;
- `../ghostwriter-<cms>` for the addon;
- `http://gw-test-<cms>.test` for the URL.

Override them with `GW_SHOT_SITE`, `GW_SHOT_ADDON` and `GW_SHOT_URL`. `GW_SHOT_EMAIL` and `GW_SHOT_PASSWORD` override the login, and `GW_SHOT_CHROME` points at Chrome (default: `/Applications/Google Chrome.app`). The tool only runs against `.test`, `.localhost` or `localhost` hosts.

| Path | What |
|---|---|
| `run.php` | The runner |
| `lib/` | Chrome over the DevTools protocol (`Chrome`, `DevToolsSocket`), page helpers (`Browser`), state save and restore (`State`), store frames (`Frame`), and the base `Site` |
| `sites/<cms>.php` | One site: its state, sign-in and shots |
| `seeders/<cms>.php` | Runs inside the site to write the seeded state; prints JSON with the IDs it made |
| `assets/` | The store frame (`frame.html`, using Newsreader and Public Sans from Google Fonts) and the Ghostwriter logo |

The DevTools client and the frame layout come from the Statamic addon's `scripts/screenshots.php` and `scripts/frame.php`.

## Needs

- PHP 8.2+ and Google Chrome.
- The test sites running under Herd, with their `TESTING.md`.
- `mysql`/`mysqldump` (Statamic, Craft) or `sqlite3` (Filament) on the path.

The queue workers can stay running: nothing the tool does queues a job. Each site also stops, in the browser, any Ghostwriter request that would ask the model for something.

For the run only, the tool sets a placeholder `OPENAI_API_KEY` (so the "Make one" tab shows) and, on Statamic, `STATAMIC_PRO_ENABLED=true` (for the second user). Both go when the site is put back.

## Adding a shot

1. Add a row to the shot list.
2. Add the shot to each `sites/<cms>.php`. A shot is an array:
   - `url`
   - optional `steps` (clicks, as a person would)
   - `ready` (text, or `css:` and a selector, that must be on screen)
   - optional `clip` and `pad`, to crop to an element
   - `scheme`, `height`, `hide`
3. Seed anything new in `seeders/<cms>.php`, and add any new folder or table to `state()`.
4. Never click anything that asks the model for something. The test sites have real keys.
5. Look at every image before committing it.
