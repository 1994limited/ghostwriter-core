# Changelog

All notable changes to `1994/ghostwriter-core` are documented here. From 1.0.0 it follows semantic versioning: a minor or patch release doesn't break the public API.

## Unreleased

"Finish this page", the core phases (finish-this-page design). Nothing in the 1.x API changes; the addons' calls work as they are.

### Added

- **Markers for what an editor must finish (`Gaps\Markers`), phase 1.** A fact to add is `[[ask: adult ticket price]]`, written strictly by `Markers::ask()` and found leniently (`[[ ASK:x ]]` counts); a link to choose is a real link to `#gw-link:<hint>` (`Markers::link()`), or `https://example.com/#gw-link:<hint>` for link fields that validate an address (`Markers::linkUrl()`). Also `asks()`, `links()`, `leftovers()` (a vocabulary placeholder such as `[[item]]` left in text), `placeholderText()` (`TBC`, `[insert date]`, `lorem ipsum`…), `isLinkSentinel()`, `linkHint()`, `normalise()` (near misses such as `[ask: x]` and `[[Ask - x]]` put right), `excerpt()` and `patterns()`, kept for the front end in `resources/gaps/patterns.json` (a test fails when it is out of date, and checks the patterns match the same in JavaScript).
- **`Gaps\FieldPath` and `Gaps\BlockRef`:** where a value sits in an entry, with blocks named by ID where they have one (`page_builder/#a1b2/intro`), so a reorder doesn't move a gap; `dotted()` gives the form's `page_builder.1.intro`.
- **`Layout\LinkPlaceholders`**, implemented by `StatamicLinks`, `CraftLinks` and `NoLinks`: `placeholderFor(Field, $siblings, $hint)` marks a link field as still to choose with the sentinel (Statamic `#gw-link:<hint>`; Craft's Link field and Hyper `https://example.com/#gw-link:<hint>`; Filament none), and `supportsLinks(Field)` says whether a text field can hold a link mark. A separate interface, so a `LinkDialect` written outside core keeps working.
- **`LayoutOptions::$linkSentinels`** (and `withLinkSentinels()`): the house style marks a link it can't settle with the sentinel, with the field's label as the hint, and names it "(link still to choose)". **Off by default in 1.x**: example.com stays, as the addons' tests and the golden layouts expect, and the gap detectors find both.
- **`BuiltEntry::$asks` and `BuiltEntry::$toFill`**: a fact the writer marked in a field that can't hold text (a number, a choice, a toggle, a date) is listed with its path, label and hint, and a note says so ("Still to add by hand: Price (adult ticket price)."); the references still to choose are listed with their paths too. The "Still to choose by hand" note is unchanged.

- **`tests/Contracts/MarkerRoundTripContract`** (with `MarkerRoundTripContractTest`), phase 2: markdown holding a fact to add and a link to choose, through an addon's real apply path and back with its dialect, keeps both markers, in rich text (headings, lists and emphasis too), in a markdown field, and in plain text (where the link is an ask). Core runs it through `EntryBuilder` and the house style with the HTML dialect and with the Bard dialect.

### Changed

- **The writer marks what it doesn't know instead of writing around it** (`resources/prompts/writer.md`). A new section, "Marking what only your colleague knows": a missing fact becomes `[[ask: what is needed]]` (at most five; more means asking first), a link with no known target points at `#gw-link:<where>` (in markdown fields only; elsewhere it is an ask), a fact meant for a number or date field is marked in that field, the reply lists what was marked, and a revision keeps every ask the colleague hasn't answered. "Never invent" now names prices and durations too, and marks never go in the title. Every addon's writer request changes accordingly (`tests/Fixtures/studio/*/write-*.json`).
- `EntryBuilder` keeps markers in text as they are, puts near misses right (`Markers::normalise()`), and no longer turns a marker in a toggle into `false` or reports one in a choice as "not an option": it lists it in `$asks`.
- `Text\Slug::make()` leaves out `[[ask: …]]`.

## 1.2.0 - 2026-10-02

### Added

- **`Images\Libraries\ConnectsAccount`**, the "Connect account" port for libraries that license through the customer's own signed-in account (`needsOAuth`): `authorizationUrl($state, $redirectUri)`, `connect($code, $redirectUri, $state = '')`, `refresh(TokenSet)`, `connected()` and `disconnect()`. Tokens are kept through the library's `LibraryTokens`. The addons' three control panel routes and their rules are in [docs/connecting-accounts.md](docs/connecting-accounts.md).
- **`Images\Libraries\OAuth\Pkce`**: RFC 7636 S256, with the verifier derived from the addon's single-use `state` and the library's secret, so nothing is kept between the connect and callback requests.
- **`FakeLibrary` is a `ConnectsAccount`**, so the addons can test "Connect account" against it and run it in a browser with the demo library: its authorization address goes straight back to the callback with a code bound to the state and address. It takes an optional `tokens:` (`LibraryTokens`); its tokens last an hour and refresh; `refusesConnect` and `refusesRefresh` script failures. Built with `needsOAuth`, it refuses `account()`, `quotes()` and `license()` with `NotConnected` until connected. Without `needsOAuth` (the default) it behaves as before.
- **`Images\Libraries\Paid\Shutterstock`**, the first paid library: a `LicensableLibrary` and `ConnectsAccount`. Search with the customer's app key and secret (basic auth); licensing with their connected account's OAuth token (expiring tokens, refreshed). Comps are never stored: `preview()` gives Shutterstock's own watermarked address (`previewStorage: none`). Costs are the subscription's allotment ("1 download"), from `/v2/user/subscriptions`. `license()` is sent once with the ledger ID as `metadata.customer_id`, and `findLicences()` reads the licence history so `reconcile()` can match it. Editorial images are off unless switched on, and need an editorial quote (`editorial_acknowledgement`). A `sandbox` option uses `api-sandbox.shutterstock.com`. Keys come from the constructor only. See [docs/images.md](docs/images.md).
- `Downloader::json()` takes `account: true` for calls made with a connected account's token: a refusal is then `NotConnected`.

## 1.1.0 - 2026-10-02

Multi-source stock photos, from the stock images design: phase 1, the library abstraction; phase 2, the ledger domain and the model-input guard; and phase 4, the licensing framework and its fakes. Nothing in the 1.0 API changes; the addons' calls work as they are.

### Added

- **Photo libraries (`Images\Libraries`), phase 1:** `PhotoLibrary`, the interface every library implements (`id()`, `label()`, `capabilities()`, `available()`, `search(SearchQuery)`, `photo($id)`, `fetch($id)`), with `Capabilities` (`free`, `mayRank`, `noModelInput`, `needsOAuth`, `quotes`, `previewKeepDays`, `previewStorage`, `termsCheckedAt`, `editorial`, `creditRequired`, `sandbox`), `SearchQuery`, `Offer`, `Cost` (minor units or units of an allowance, never a float) and `Preview` (a paid library's comp: bytes kept privately until `keepUntil`, or only the provider's address).
- **The four free libraries as classes:** `Libraries\Free\Unsplash`, `Pexels`, `Pixabay` and `Openverse`, moved out of `StockSearch` unchanged.
- **`StockSearch` is a set of libraries.** A new optional `libraries:` argument adds more after the free ones (one with a free library's ID replaces it). New: `libraries()`, `library($id)`, `label($id)`, `mayRank(Photo)`, and `search(..., sources:)` to keep a search to some libraries.
- **`Photo` gains `offer`, `editorial`, `restrictions` and `collection`** (appended to the constructor with defaults), `offer()` and `isFree()`. `toArray()` adds the new keys only when set, so a free photo's array is as before; `fromArray()` reads them.
- **`tests/Images/Libraries/LibraryContract.php`:** what every library adapter must do, run against the four free ones.
- **The stock image ledger (`Domain\Stock`), phase 2:** `StockImage`, one record per stock image put into the site, free or paid: its asset (`AssetRef`), where it is used (`Usage`), its state (`preview`, `licensing`, `licensed`, `failed`, `removed`), the comp kept for it and when each comp was downloaded (one "Refresh preview" allowed), credit, licence type, restrictions, product and term end (for the seat and storage terms), the quote and the licence, who did what (`Person`) and an append-only `history` of `HistoryEvent`s. `StockImages` holds the rules, each change under the lock `stock:<id>`: a licence is begun once, an unknown outcome is settled by `reconcile()` from the library's own licences (never by buying again; failed only after 10 minutes), usages follow the records that hold the image (`syncUsages()`), and the publish guard asks `unlicensedIn()` or `unlicensedAmong()`.
- **`StockImageStore`**, with `StockImageQuery` and `StockImagePage`. No delete. `save()` goes through `StockImage::over()`, which refuses a shorter history or a state moving back, and merges two saves' histories. `tests/Contracts/StockImageStoreContract.php` and `Domain\Testing\InMemoryStockImageStore`.
- **`Images\Libraries\Quote` and `Licence`:** a priced licence option, and a licence bought (order ID, cost as charged or estimated, credit line, product type, term end, the idempotency key, and the provider's answer with signed addresses and tokens taken out).
- **`Domain\Stock\ModelInputGuard`:** no Getty or iStock image the site holds goes to a model. It refuses an asset whose ledger record allows no model input (or is Getty's or iStock's), a `GettyImages-*` or `iStock-*` file, and an image whose embedded IPTC or XMP credit, source or copyright names Getty Images or iStock. `PhotoRanker`, `PhotoFinder` and `Studio` take one (an optional last argument); without one, they still check each image's bytes. `Images\ReferenceImage` carries a reference's asset and file name; `Studio\ImagerySample` gains optional `asset` and `filename`.

- **Licensing, phase 4:** `Libraries\PreviewableLibrary` (`preview()`) and `LicensableLibrary` (`account()`, `quotes()`, `license()`, `download()`, `findLicences()`), with `Account`. The errors, in `Images\Exceptions`: `NotConnected`, `QuoteChanged` (with the new quote), `InsufficientBalance`, `LicenceRefused` and `LicensingUncertain` (it may have charged: never retry, reconcile).
- **`StockImages::license()`**, the whole "License & replace": the ledger record goes to `licensing` first, the library is asked once, a plain refusal fails the record, an unknown outcome leaves it for `reconcile()`, the licence is recorded before the file is fetched, and the addon's new `Domain\Stock\AssetReplacer` port (with `ReplaceMeta`: credit, licence type, restrictions, the ledger ID; never title or alt) puts the file in place byte for byte. `replaceAgain()` finishes a licence whose file couldn't be put in place, without buying again.
- **`Downloader::post()`**, for token and licence calls: sent exactly once, never retried or redirected, with `purchase: true` mapping anything that leaves the outcome unknown to `LicensingUncertain`, and messages that name the host at most.
- **`Libraries\Ports\LibraryTokens`** and **`Libraries\OAuth\TokenSet`** (masked in dumps), **`Libraries\StandIn::jpeg()`** (the public-safe stand-in at the photo's aspect ratio), and the fakes: **`Libraries\Testing\FakeLibrary`** (scripted photos, quotes and licence outcomes, GD-drawn comps, every call recorded; also the addons' demo library), `InMemoryLibraryTokens` and `Domain\Testing\MemoryAssetReplacer`.

### Changed

- **A model never sees a photo whose library doesn't allow it** (`Capabilities::$mayRank`). `PhotoRanker` sends only those photos' thumbnails and descriptions to the `photo-picker` agent; the rest follow the judged ones in their library's order, unjudged and never picked. Every free library allows it, so nothing changes for them yet. Unsplash stays judged by decision; see the stock images design.
- **Reference images and imagery samples go through the model-input guard.** One whose embedded credit or copyright names Getty Images or iStock is left out, logged at debug. Others are sent as before.
- `PhotoUnavailable` is no longer `final`, so the licensing errors can extend it.
- The branch alias is `1.x-dev` (it still said `0.5.x-dev`).

## 1.0.0 - 2026-10-02

The first stable release. The extraction from the Statamic, Filament and Craft addons is complete: text handling and prompts, the provider layer, photo search, `Studio`, the schema model and layout algorithms, and the domain model with its stores and rules all live here. All three addons run on it. From here the public API is stable within 1.x, and the addons require `^1.0`.

No code changes since 0.5.2.

## 0.5.2 - 2026-10-02

### Fixed
- Work was taken as stopped after (timeout + 120) × 2 seconds (840 with the default 300), before the addons' job limit of timeout × 3 + 60 (960). A turn still retrying could be shown as failed and a second run started on the same conversation. `DomainOptions::staleAfter()` is now the job limit plus 120 seconds (1,080 with the default).

## 0.5.1 - 2026-10-02

### Fixed
- `Plan::putBack()` refused started pieces, which removed **Back to ideas** (E5) from started-but-unfinished pieces. It now also takes a started piece when the host says it isn't finished (`putBack($id, fn (Idea $idea) => …)`), and still refuses finished pieces and open ideas. Without the callable a started piece is refused, as before.

## 0.5.0 - 2026-10-02

The domain model, its rules and the store contracts, unified from the three addons: Phase 4, stage 1 of the core extraction. The addons switch over in stage 2. See [docs/domain.md](docs/domain.md) for how an addon wires it, and [docs/domain-unification.md](docs/domain-unification.md) for what differed and what core does.

### Added

- **Sessions (`Domain\Sessions`):** `Session` (the brief, conversation, draft, status, usage, examples, images, the record it is for, who started, touched and ran it, `startedWorkingAt`), with `claim()`, `isStale()`, `recoverIfStale()`, `answer()`, `markApplied()`, `canRetry()`, `waitingOn()` and `title()`. `SessionAccess`: shared or private conversations (E7), deleted by the starter or a manager (Q1). `SessionGuard`: every change under a per-session `Lock`, one run at a time with `Busy` saying whose request runs, retries, hand edits refused while a run works (F3), and changes after slow work. `Progress` and `Record`: where a piece has got to, and finished only once its record is saved (E6). `SessionImages`: the image fields' state machine, and a turn's image changes merged around choices made meanwhile (F2).
- **Content plan (`Domain\Planning`):** `Idea`, `PlanState` and `Plan`: suggestions wait for review and a new batch joins them (E3), no title twice, keep or drop, put back only dismissed ideas (E8), an idea whose piece was deleted is open again, only open or dismissed ideas cleared.
- **Kinds (`Domain\Kinds`):** `ContentType` (the "Something new" brief word for word per addon, `modelledOn()`, `forSession()`, `missing()`, `handleFor()`, `toStudio()`), `KindSuggestions` (when a group is due a look, Q3) and `Analysis`.
- **Guides (`Domain\Guides`):** `Guide` and `GuideState`. **Image requests (`Domain\Images`):** `ImageRequest`, `ImageRequests` and `StoredFile`: owner only, cleared after a day. **Queue (`Domain\Queue`):** `Waiting`, the notice for work no worker has picked up.
- **Stale work (CRA-2):** a session, screen state, study or image request still marked working (timeout + 120) × 2 seconds on has stopped, and shows as failed.
- **`Images\Placeholders`** and the **`Images\AssetSink`** port (D10): one rule for where the striped placeholder goes, the same image byte for byte, and the `placeholder_images` setting's label and note.
- **Store interfaces and ports:** `SessionStore`, `PlanStore`, `KindStore`, `GuideStore`, `ImageRequestStore`, `WaitingStore` and `Lock`. Contract tests for each in `tests/Contracts` (traits, with abstract PHPUnit cases), now included in the package; in-memory implementations in `Domain\Testing`.
- **`Format` and `DomainOptions`**, with `statamic()`, `craft()` and `filament()` presets. Every type reads and writes the addon's current stored shape (`fromArray($stored, $format)`, `toArray()`), unchanged fields exactly, so no data migration is needed. `tests/Fixtures/domain` holds 54 stored records, 37 of them recorded from the three test sites with `tools/record-domain/record.php`.
- **Refusals** as exceptions with a status: `NotFound`, `NotAllowed`, `Conflict`, `Busy`, `LockTimeout`.

### Changed for the addons, once they switch

- All three: a run that stopped without finishing shows as failed and can be tried again (new for Statamic and Filament); a new batch of plan suggestions joins the one waiting instead of replacing it; only a dismissed idea can be put back; clearing takes open or dismissed ideas only.
- Statamic and Craft: sessions gain a `started_working_at` key when a run is claimed. Filament's sessions are timed from `updated_at`, as it has no column.
- Craft: an empty page builder gets a placeholder block only when it holds nothing but images, as in Statamic.
- Filament: a run is claimed under a cache lock rather than a conditional update; a retry needs the last message to be a person's.
- The branch alias is `0.5.x-dev`; addons should require `~0.5.0`.

## 0.4.0 - 2026-10-02

The schema model and the layout algorithms, unified from the three addons' copies: Phase 3, stage 1 of the core extraction. The addons switch over in stage 2. See [docs/layout.md](docs/layout.md) for how an addon wires it, and [docs/layout-unification.md](docs/layout-unification.md) for what differed and what core does.

### Added

- **`Schema\Field`, `Set`, `Schema` and `Kind`:** what a kind of entry is made of, with the union of the three addons' kinds (`text`, `longtext`, `richtext`, `choice`, `choices`, `toggle`, `number`, `list`, `blocks`, `rows`, `group`, `reference`), page builders' sets, an adapter's `engine` tag and its own `meta`. `Schema::fromSpecs()` reads the arrays the addons' `SchemaReader`s return today, and `toSpecs()` gives them back.
- **`Schema\EntryData`:** an existing entry's content in one shape whatever the CMS (Craft's `EntryData` shape), with its ID, title and parent.
- **`Layout\SchemaDescriber`, `PatternFinder`, `KindFinder`, `HouseStyle` and `EntryBuilder`**, working on a `Schema` and `EntryData`, with results `Pattern`, `HouseRules`, `HouseResult` (with the "Still to set by hand" note), `FoundKind` and `BuiltEntry`. Each `toArray()` is the array the addons' classes returned. `PatternFinder::choose()` keeps the shared rule for which entries to learn from. `Layouts` builds all five with one set of options and dialects.
- **Dialects:** `RichTextDialect`, with `HtmlDialect` for HTML (Craft's CKEditor and Redactor, Filament's RichEditor and MarkdownEditor); Statamic's Bard dialect lives in its adapter, and a reference copy is in core's tests. `LinkDialect`, with `StatamicLinks`, `CraftLinks` (the adapter passes its Hyper and Link field classes) and `NoLinks` (Filament).
- **`LayoutOptions`**, with `statamic()`, `craft()` and `filament()` presets for what the three did differently: "collection" or "section", "page" or "record", Statamic's bookkeeping keys, whether a block's rich text is copied by position, which unsettled references are named, block and row IDs in the data, the unknown-block wording, and how the kind finder picks its builder and names its kinds.
- **Studio:** `Layout::fromSchema($schema, $pattern, $describer)` describes a structured schema with core's `SchemaDescriber`. `Layout::fromPattern()` with an addon's own text still works, and sends the same requests.
- **Parity tooling:** `Layout\Testing\LayoutLog` and `bin/compare-layouts` (installed as `vendor/bin/compare-layouts`) compare what the layout algorithms gave in two runs of an addon's suite. `tests/Fixtures/layout` holds 495 golden cases (Statamic 225, Filament 95, Craft 175) recorded from each addon's own code, from its test suite, its Northfold test site and made-up inputs, with `tools/record-layouts`; core reproduces every one.

### Changed for the addons, once they switch

- Filament: a field named `label` or `value` is no longer read as part of a Craft link (FIL-9), so a label repeating each record's title isn't copied into a new record as its title.
- Statamic: an empty value shared by most entries is no longer taken for a house default, as in Craft and Filament. A field held back for a link to the new entry itself is skipped in Craft and Filament too, as in Statamic. A copied entry-level default gets fresh set and row IDs.
- The branch alias is `0.4.x-dev`; addons should require `~0.4.0`.

## 0.3.0 - 2026-10-02

The Studio: every model call the addons make for writing, planning and learning a site, unified from the three addons' `Studio` classes. See [docs/studio.md](docs/studio.md) for how an addon wires it, and [docs/studio-unification.md](docs/studio-unification.md) for what differed and what core does.

### Added

- **`Studio\Studio`** with the union of the three addons' jobs: `analyseVoice`, `refineVoice`, `analyseType` (with the one re-ask when the reply can't be read), `suggestKinds`, `suggestIdeas`, `analyseImagery`, `draftBrief`, `photoQuery`, `write`, `brief` and `writerInstructions`, plus `ask()` for one-off calls. Prompts come from the addon's `PromptLibrary`, limits and effort from `Agents`, calls go through `Providers` or any `TextProvider`.
- **Neutral inputs**, no CMS objects: `VoiceSample`, `ContentKind` and `Question`, `Layout` (the fields already described, and the example entries), `TypeSurvey`, `KindSurvey` and `KindSample`, `PlanContext`, `PlanGroup`, `PlanItem` and `PlannedIdea`, `ImagerySample`, `Conversation` and `WriterContext`.
- **Results with usage:** `Result` (`value` and `usage`, counting every call a job made, retries and re-asks included), `SuggestedKind` and `SuggestedIdea` (with `toArray()` in each addon's keys), and `TaggedResponse` for the voice and writing jobs. A reply that can't be read throws `UnreadableReply`, an `InvalidArgumentException` carrying the message the addons threw and the `problem`.
- **`StudioOptions`**, with `statamic()`, `craft()` and `filament()` presets for what the three did differently: example YAML depth, the planner's "draft" or "not published", "blueprint" or "entry type", whether a kind finder reply with no `<kinds>` block means "nothing to add", and the cut-off messages.
- **The cut-off policy (core-ai-design §6.6), once:** a reply that ran out of room is asked for again with twice the room, up to 32000 tokens. Still cut off, a draft or guide (`writer`, `type-analyst`, `voice-analyst`, `voice-editor`) throws `Truncated`; everything else is kept, with a warning.
- **F8:** a reply that can't be read is logged as the problem only ("there was no `<type>` block", "the YAML did not parse at line 3"). The whole reply goes in the log context only with `StudioOptions::$logReplies` on. Prompts, instructions and keys are never logged.
- **Parity tooling:** `Studio\Testing\RequestLog` records a suite's requests as they would be sent, and `bin/compare-requests` (installed as `vendor/bin/compare-requests`) compares two runs. `tests/Fixtures/studio` holds each addon's representative inputs and the exact requests core sends for them.

### Changed for the addons, once they switch

- Craft: a cut-off plan, kind list, brief or imagery guide is kept rather than failing, as in Statamic and Filament.
- Craft and Filament: an empty `<kinds></kinds>` reply means "nothing to add" rather than an error.
- All three: a title repeated within one kind finder or planner reply is kept once; the planner accepts `collection`, `section` or `resource` as the group key; token usage includes the retry with more room and the type analyst's re-ask.
- The branch alias is `0.3.x-dev`; addons should require `~0.3.0`.

## 0.2.0 - 2026-10-02

Photo search, unified from the three addons' copies, with model ranking and the photo libraries' own descriptions. See [docs/images.md](docs/images.md) for how an addon wires it.

### Added

- **`Images\StockSearch`:** Unsplash, Pexels, Pixabay and Openverse over PSR-18, with keys from `Credentials` (`unsplash`, `pexels`, `pixabay`) and Openverse switched by a constructor argument (a bool or a callable). It is the union of the three addons' versions: orientation per shape, nine results per library, a two-word retry for long searches, one library failing without hiding the others, and Openverse results kept only when Openverse's own thumbnail loads (originals are never fetched to check them). `fetch($source, $id)` looks the photo up again by ID, reports Unsplash downloads, and returns a `PhotoFile` (bytes, MIME type, extension and the `Photo`).
- **Hardened downloads:** https only on every hop, with redirects followed by core (at most three, never to localhost, a private or reserved IP address, or a `.local`/`.internal` name) and API keys dropped when a redirect leaves the host. Bodies are read no further than their cap (15 MB for a photo, 2 MB for a thumbnail), and a declared length over the cap is refused before reading. Files must say they are images and be a JPEG, PNG or WebP that PHP can read; the extension comes from the bytes. Failures are `Images\PhotoUnavailable` (an `InvalidArgumentException`, as the addons threw) with a message that never carries a key.
- **`Images\Photo`:** source, ID, thumbnail, credit, credit URL, licence, the library's own `title`, `description` and `tags`, width and height, the full-size address, the search that found it, and `picked` and `reason` when a model judged it. `alt()`, `assetTitle()` and `filenameBase()` turn the library's words into alt text (at most 125 characters), an asset title and a file name, falling back to the search term (decision D4). `toArray()` keeps the keys the addons' UIs already read; `fromArray()` reads them back, older arrays included.
- **`Images\PhotoRanker`:** judges candidates through the `photo-picker` agent. With reference images it matches style and subject, as before; without, it now judges the subject alone against the block's and page's words and each photo's library description, instead of skipping (decision D2). Clear misses are left out. `picked` is set only when the model judged, so "Best match" can no longer land on unranked photos. Requests stay within `Ai\Limits`, and images are made small first (Imagick, then GD).
- **`Images\PhotoFinder`:** the whole flow. Searches are chosen by the `photo-researcher` agent (or typed), run, and judged. When nothing fits, the model's own searches are run and judged once more, with or without references (Statamic's retry, now in all three). Returns `Images\PhotoResults` with `judged`, `withReferences`, `noneFit`, `retried` and the `terms` searched.
- **`Images\PhotoContext`:** the words around a field (title, summary, label, block text, page text, shape, imagery guide).
- **`Text\Slug`:** `make()` for ASCII file names (accents taken off, other scripts transliterated with intl, cut between words) and `clip()` for one-line text cut at a word.
- **`Ai\Ports\DownloadClients`:** an optional extra on `HttpClients`, for clients that hand redirects back instead of following them, and can stream. `GuzzleHttpClients` and `MockHttpClient` implement it; an addon's own `HttpClients` should too.

### Changed

- **The `photo-picker` prompt** handles both modes (with and without references), asks for one line per photo that fits with a reason, and lets the model answer `none: search; search; search`. A plain list of numbers, as a site's older override asks for, is still read. Its golden fixtures are now core's text for all three addons.
- The branch alias is `0.2.x-dev`; addons should require `~0.2.0`.

## 0.1.1 - 2026-10-02

### Changed
- The writer prompt no longer lets the model promise or require anything on the organisation's behalf (conditions, requirements, prices, offers, guarantees, deadlines, policies) that the brief or conversation didn't give it. It leaves them out and says what is missing. Found in testing, where a job advert gained "you'll need to be local".
- The writer may also take facts about the organisation from the existing entries it is shown, which it already did in practice.

## 0.1.0 - 2026-10-01

The first version: the parts the Statamic, Filament and Craft addons had copied by hand, in one framework-free package.

### Added

- **Text:** `Draft`, `LenientYaml`, `TaggedResponse`, `HtmlToMarkdown`, `DraftPreview`, `EntryMerger`, `EntrySimplifier` and `Utf8`. They are unified from the three addons' copies. Where the copies differed, the difference is a constructor option ([docs/text-unification.md](docs/text-unification.md)).
- **Prompts:** all twelve prompts (including `photo-query`, the single-search photo prompt behind Statamic's `Studio::photoQuery`), plus `Vocabulary` (with `statamic()`, `craft()` and `filament()`) and `PromptLibrary`, which supports per-site overrides. Golden tests check that each addon gets its current prompts byte for byte.
- **AI layer,** ported from the Craft addon:
  - Value objects, the `TextProvider` and `ImageProvider` contracts, and the Anthropic, OpenAI and Gemini providers over PSR-18.
  - The `Providers` registry, and the `Credentials`, `HttpClients` and `ProviderSettings` ports. `Providers::withSleeper()` and `withRetryPolicy()` return a configured copy.
  - `Models`, one table of default models: `claude-opus-5-5`, `gpt-6.1-sol`, `gemini-3.8-flash`, `gpt-image-2.5-sunburst` and `gemini-3.1-flash-image`.
  - `Agents`, which sets max tokens and effort per agent.
  - `TextRequest::withMaxTokens(int)` and `withModel(?string)`, which return a copy (to retry a cut-off reply with more room, or on another model).
- **Retries:** 408, 409, 429, 5xx and 529 responses, and connection failures, are retried with backoff and jitter. `retry-after` and `retry-after-ms` are followed, with waits capped at 30 s. A response timeout is not retried.
- **Stop reasons:** `TextResponse::$stopReason` (`end`, `max_tokens`, `refusal`, `safety`, `other`) and `truncated()`. Providers report a cut-off reply and never throw for it.
- **Usage:** `Usage` counts Anthropic's cache-creation tokens and Gemini's thinking tokens.
- **Anthropic fallbacks:**
  - Server-side fallbacks are sent only to models with refusal classifiers, only on Anthropic's own API, and can be turned off.
  - If the API refuses the beta, it is dropped for the rest of the process.
  - A refusal that names a `recommended_model` is retried once on that model.
- **Effort:** sent to OpenAI (`reasoning_effort`) and Gemini (`thinkingLevel`) as well as Anthropic, for the models that take it.
- **Base URLs:** an optional `base_url` per provider, for gateways that speak the same API. It must be `https://`, except for localhost.
- **Request size:** a request with more than 24 images, or more than 20 MB of image data, is refused before it is sent. The limits are public on `Ai\Limits` (`MAX_IMAGES`, `MAX_IMAGE_BYTES`, `fits()`), so addons can size their requests. 24 leaves room for the photo picker's 3 references and 18 thumbnails; 20 MB of raw bytes is about 27 MB once base64-encoded, under Anthropic's 32 MB request limit.
- **Exceptions:** a hierarchy under `ProviderException`, with `retryable()`, `status()` and `provider()`. Keys never appear in messages or logs.
- **Testing:**
  - `Testing\FakeProvider`, with Craft's API plus `assertSent`, `assertNotSent`, `assertImageSent`, `assertNoImageSent`, `assertNothingSent`, `failWith` and `reset(?string $agent)`. Under PHPUnit (or Pest) the asserts go through `PHPUnit\Framework\Assert`, so they count and tests aren't marked risky; without it they throw `AssertionError`.
  - `FakeProvider::withoutKeys()` and `->unconfigured(bool $text = true, bool $image = true)`, which make the faked `Providers` report missing keys: `configured()` false, `image()` and `imageHandle()` null, `keyStatus()` all false, and `text()` throws `NotConfigured`.
  - `Testing\MockHttpClient`, `NetworkError` and `RecordingSleeper`.
- **Boundaries:** `bin/check-boundaries`, which fails if `src/` names a framework or CMS.
- **Dependencies:** `symfony/yaml` `^6.4|^7.0|^8.0`, `psr/http-factory` `^1.1`, and Guzzle `^7.8|^8.0` for `GuzzleHttpClients`. CI also runs `--prefer-lowest`, Guzzle 7, Guzzle 8, and the newest versions without the PHP 8.2 platform pin.

### Deprecated

- `TextResponse::$inputTokens`, `$outputTokens` and `$truncated`. Use `$usage->input`, `$usage->output` and `truncated()`. These properties will be removed in 1.0.
