# Studio: wiring it into an addon

Core 0.3.0 holds the Studio: every model call the addons make for writing, planning and learning a site. The prompt is filled from neutral inputs, sent through `Ai\Providers`, and the reply is read back into core types. The three addons' `Studio` classes did this with CMS objects; after the switch, each addon only translates its objects into the inputs and the results back into its own.

It lives in `Core\Studio` rather than `Core\Ai`. `Ai` is the provider layer and knows nothing about prompts or what a job means; the Studio sits on top of `Ai`, `Prompts` and `Text`.

What differed between the three, and what core does instead, is in [studio-unification.md](studio-unification.md).

## Building it

Build one next to `Providers` (a container singleton, a plugin component):

```php
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;

$studio = new Studio(
    $providers,                         // the Providers registry, or any TextProvider
    $prompts,                           // the addon's PromptLibrary (its Vocabulary and overrides)
    $logger,                            // PSR-3, optional
    StudioOptions::craft(logReplies: $settings->debug),   // ::statamic(), ::filament()
);
```

- **Logger:** Statamic `Log::channel()` (default channel, as today), Filament `Log::channel(config('ghostwriter.log_channel'))`, Craft its PSR-3 bridge.
- **`logReplies`:** off unless a debug setting is on. With it on, a reply that can't be read goes in the log context under `reply` (F8). Prompts and keys are never logged.

## Jobs, inputs and translators

Each job takes small value objects. The addon needs one translator (a `StudioInputs` class, say) that builds them from its CMS objects.

| Job | Inputs | Returns | Translator needed |
|---|---|---|---|
| `analyseVoice(VoiceSample[])` | `VoiceSample(title, group, text)` | `TaggedResponse` (guide in `document`, tokens) | Voice scanner samples → `VoiceSample`. |
| `refineVoice($guide, $history, $request)` | strings; history as `Message`s or `role`/`content` arrays | `TaggedResponse` | None. |
| `analyseType(TypeSurvey)` | `TypeSurvey(groupTitle, groupHandle, Layout, ?title, chosenExamples)`; `Layout(fields, examples, studied)` | `Result<array>`: the type data | Schema → described fields, pattern → `Layout::fromPattern($described, $pattern)`. Then, as today, its own type from `$result->value`. |
| `suggestKinds(KindSurvey)` | `KindSurvey(groupTitle, groupHandle, KindSample[], ContentKind[] taught, string[] dismissed)`; `KindSample(id, title, text, builtAs, ?under, ?variantHandle, ?variantName)` | `Result<SuggestedKind[]>` | The newest 60 published entries → `KindSample`; taught types → `ContentKind`. |
| `suggestIdeas(PlanContext)` | `PlanContext(PlanGroup[], PlannedIdea[], voice, steer, count)`; `PlanGroup(title, handle, ContentKind[], PlanItem[])`; `PlanItem(title, published, summary)` | `Result<SuggestedIdea[]>` | Groups → `PlanGroup` with up to 150 items each; the plan's ideas → `PlannedIdea`. |
| `analyseImagery($groupTitle, ImagerySample[])` | `ImagerySample(label, on, Image)` | `Result<string>` | Sampled images → `ImagerySample`. |
| `draftBrief(ContentKind, $title, $notes, $titles)` | the kind; the group's 40 newest titles | `Result<array<string, string>>` | Type → `ContentKind`; titles. |
| `write(Conversation, WriterContext)` | `Conversation(messages, ?draft, answers)`; `WriterContext(ContentKind, voice, Layout, images)` | `TaggedResponse` (`draft`) | Session → `Conversation`; type → `ContentKind` and `Layout`. |
| `brief(ContentKind, $answers)` | | `string` | |
| `writerInstructions(WriterContext)` | | `string` | As for `write`. |
| `photoQuery($title, $summary)` | `Studio::summaryOf($draft)` for the summary | `Result<string>` | None. |
| `ask($agent, $prompt, ...)` | | `TextResponse` | For one-off calls; applies the cut-off policy. |

`Result` has `value` and `usage` (`Ai\Usage`, every call the job made, retries included), so the addon keeps counting tokens: `$session->usage['input'] += $result->usage->input`.

`ContentKind::fromArray($handle, $type->toArray())` reads the arrays all three store (`title`, `description`, `guidance`, `checklist`, `questions`). Where only a kind's name is shown (taught kinds, a group's kinds in the plan), `new ContentKind($handle, $title, $description)` is enough.

Failures:

- A reply that can't be read throws `Studio\UnreadableReply`. It is an `InvalidArgumentException` with the message the addons threw, so existing catches keep working. `problem` says what was wrong.
- A draft or guide still cut off after the retry throws `Ai\Exceptions\Truncated`. Any other agent keeps what came back.
- Provider failures are `ProviderException`s, as before.

### Per addon

**Statamic**

```php
$studio->suggestKinds(new KindSurvey($collection->title(), $collection->handle(), $entries->map(fn ($entry) => new KindSample(
    (string) $entry->id(),
    (string) $entry->get('title'),
    $prose->fromEntry($entry),
    $builtAs,                                         // enabled replicator set types
    $entry->parent()?->title(),
    $entry->blueprint()->handle(),
    $several ? $entry->blueprint()->title() : null,   // only when the collection has several blueprints
))->all(), $taught, $state['dismissed']));

new PlanItem($entry->get('title'), $entry->published(), is_string($summary) && $summary !== '' ? Str::limit($summary, 160) : '');

$kinds->value[0]->toArray('blueprint');
$ideas->value[0]->toArray('collection', 'type');
```

- `WriterContext::$images`: `app(ImageStudio::class)->describe($type)`.
- Plan count: `config('ghostwriter.plan.suggestions', 8)`. Brief titles: the 40 newest entries, any status.

**Craft**

- `KindSample`: `$entry->id` (an int), `$entry->title`, `$prose->fromEntry($entry)`, enabled block types, `$entry->getParent()?->title`, `$entry->getType()->handle`, and the entry type's name when the section has several. The group title is `Craft::t('site', $section->name)`.
- `PlanItem::fromProse($entry->title, $entry->getStatus() === Entry::STATUS_LIVE, $prose->fromEntry($entry))`.
- `WriterContext::$images`: "This site has no image tools switched on. Leave image fields out of the draft; a person adds images afterwards. If asked for images, say so plainly." (`Studio::images()` today; keep it overridable there).
- `toArray('entryType')`, `toArray('section', 'type')`. Plan count from `planSuggestions`. Brief titles: the 40 newest, any status.
- Behaviour change: a cut-off plan, kind list, brief or imagery guide is kept rather than failing (§6.6).

**Filament**

- `KindSample`: `(string) $record->getKey()`, the title field (or `#id`), `$prose->of($data, $schema, $titleField)`, the builder's block types; no `under` or variant.
- `PlanItem::fromProse($title, ! $draft, $prose)`, with the voice guide from `Guides`.
- `WriterContext::$images`: "Leave image and upload fields out of the draft; a person adds images afterwards."
- `toArray()`, `toArray('resource', 'kind')`. Plan count `PLAN_SUGGESTIONS` (8). Brief titles: the 40 newest published.

## Checking parity

Every request a test suite sends goes through `FakeProvider`, which keeps it. `Studio\Testing\RequestLog` writes them down so two runs can be compared: the addon on its own Studio, then on core's. The only differences allowed are the deliberate ones in [studio-unification.md](studio-unification.md).

1. **Before.** In the addon, on `main`, require core `~0.3.0` and change nothing else (0.3.0 only adds). Add a recorder to the base `TestCase::tearDown()`:

   ```php
   if ($path = getenv('GHOSTWRITER_RECORD_REQUESTS')) {
       RequestLog::append($path, static::class.'::'.$this->name(), $this->ai->requests());
   }
   ```

   (Craft's Codeception tests: `$this->getName()` and `$this->fake`.) Run the suite with `GHOSTWRITER_RECORD_REQUESTS=/tmp/before.jsonl`.
2. **After.** On the switch branch, the same with `/tmp/after.jsonl`.
3. **Compare.**

   ```bash
   vendor/bin/compare-requests /tmp/before.jsonl /tmp/after.jsonl
   ```

   It lists tests only in one log, tests that sent a different number of requests, and each field that differs (agent, instructions, prompt, history, images, the token limit and effort as sent, model, timeout), with where the text first differs. `--ignore=maxTokens` leaves a field out. It exits 1 when anything differs.

Delete the log files between runs: `append()` adds to them.

Tests whose fakes answer differently before and after (a fake queued for a reply that's now kept rather than retried, say) show up as a different number of requests. Check each against the unification notes.

### The fixtures

`tests/Fixtures/studio/{statamic,craft,filament}/*.json` hold, for each addon's vocabulary and options, a few representative inputs, the replies faked, the exact requests core sent (in `RequestLog` form) and the result. They show what a translator has to produce. `tests/Studio/ParityCases.php` builds the inputs; `StudioParityTest` checks core still sends the same. After a deliberate change to a prompt or a job:

```bash
GHOSTWRITER_UPDATE_FIXTURES=1 vendor/bin/phpunit --filter StudioParityTest
```

Before 0.3.0 was tagged, the Statamic and Filament Studios were run side by side with core's in their own test suites (writer instructions, `write`, `brief`, the type analysis and its re-ask, kinds, ideas, the brief writer and both voice jobs) and sent identical requests. Craft's was compared by reading the code.
