<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Truncated;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\OutputSchema;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TakesSchemas;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSlots;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSources;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtrasReader;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanReader;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ModelInputGuard;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapRefused;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Review\RevisionReply;
use NineteenNinetyFour\Ghostwriter\Core\Review\RevisionRequest;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Phrases;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewInput;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewPrompt;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\RewordRequest;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReader;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionReply;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\VerifyPrompt;
use NineteenNinetyFour\Ghostwriter\Core\Text\LenientYaml;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Every model call the addons make for writing, planning and learning a
 * site goes through here: the prompt is filled from neutral inputs, sent
 * through the provider layer, and the answer is read back into core types.
 *
 * The addon turns its CMS objects into the inputs (VoiceSample, TypeSurvey,
 * KindSurvey, PlanContext, ImagerySample, Conversation, WriterContext) and
 * turns the results back into its own (a ContentType, a saved idea). See
 * docs/studio.md.
 *
 *     $studio = new Studio($providers, new PromptLibrary(Vocabulary::craft()), $logger, StudioOptions::craft());
 *     $kinds = $studio->suggestKinds(new KindSurvey('News', 'news', $samples, $taught, $dismissed));
 *     foreach ($kinds->value as $kind) { $kind->toArray('entryType'); }
 *     $kinds->usage->output;
 *
 * Every call goes through ask(), which applies the cut-off policy once for
 * all jobs (core-ai-design §6.6): a reply that ran out of room is asked for
 * again with twice the room, up to 32000 tokens; if it still doesn't fit, a
 * draft or guide (StudioOptions::WHOLE) throws Truncated and anything else
 * is kept as far as it got, with a warning in the log.
 *
 * When a reply can't be read, the log says what was wrong with it, and
 * holds the reply itself only with StudioOptions::$logReplies on (F8).
 * Prompts, instructions and keys are never logged.
 */
final class Studio
{
    /** The most room a cut-off reply is given when it is asked for again. */
    public const MAX_TOKENS_CEILING = 32000;

    /** Examples are trimmed to this many characters each. */
    public const EXAMPLE_LIMIT = 7000;

    /** How much of an entry's prose the kind finder is shown. */
    public const OPENING_LENGTH = 220;

    /** The most examples a suggested kind keeps. */
    public const KIND_EXAMPLES = 6;

    /** Kinds asked for at a time. */
    public const KIND_COUNT = 5;

    /** The longest title a suggested kind keeps. */
    public const KIND_TITLE_LENGTH = 60;

    /** The most words a photo search may have before the title is used instead. */
    public const PHOTO_QUERY_WORDS = 6;

    private readonly LoggerInterface $logger;

    private readonly StudioOptions $options;

    private readonly ModelInputGuard $guard;

    /**
     * @param  Providers|TextProvider  $model  The registry (its text provider is used), or a provider.
     */
    public function __construct(
        private readonly Providers|TextProvider $model,
        private readonly PromptLibrary $prompts,
        ?LoggerInterface $logger = null,
        ?StudioOptions $options = null,
        ?ModelInputGuard $guard = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->options = $options ?? new StudioOptions;
        $this->guard = $guard ?? new ModelInputGuard(logger: $this->logger);
    }

    /** Whether there is a model with a key to call. */
    public function configured(): bool
    {
        return ! $this->model instanceof Providers || $this->model->configured();
    }

    public function options(): StudioOptions
    {
        return $this->options;
    }

    /**
     * A prompt with the vocabulary filled in, as the library has it
     * (overrides included).
     */
    public function prompt(string $name): string
    {
        return $this->prompts->get($name);
    }

    /**
     * A tone of voice guide from samples of published writing. The guide is
     * the whole reply, as `document`.
     *
     * @param  array<int, VoiceSample>  $samples
     *
     * @throws ProviderException
     */
    public function analyseVoice(array $samples): TaggedResponse
    {
        $prompt = 'Here are '.count($samples)." samples of published writing from the website.\n\n"
            .implode("\n\n", array_map(fn (VoiceSample $sample, int $i) => sprintf(
                "<sample number=\"%d\" collection=\"%s\" title=\"%s\">\n%s\n</sample>",
                $i + 1,
                htmlspecialchars($sample->group, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($sample->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $sample->text,
            ), array_values($samples), array_keys(array_values($samples))))
            ."\n\nWrite the tone of voice guide.";

        $response = $this->ask('voice-analyst', $prompt);

        return new TaggedResponse('', trim($response->text), $response->usage->input, $response->usage->output);
    }

    /**
     * The voice guide changed as asked, in a conversation about it.
     *
     * @param  array<int, Message|array<string, mixed>>  $history  Earlier turns.
     *
     * @throws ProviderException
     */
    public function refineVoice(string $guide, array $history, string $request): TaggedResponse
    {
        $response = $this->ask('voice-editor', "<current_guide>\n{$guide}\n</current_guide>\n\nRequest: {$request}", $history);

        return TaggedResponse::parse($response->text, 'document', $response->usage->input, $response->usage->output);
    }

    /**
     * What a kind of content is and what to ask before writing it, as the
     * type analyst wrote it: title, description, guidance, checklist,
     * questions and so on, for the addon to make its own type from. A reply
     * that can't be read is asked for once more, told what was wrong.
     *
     * @return Result<array<string, mixed>>
     *
     * @throws UnreadableReply when neither reply can be read.
     * @throws ProviderException
     */
    public function analyseType(TypeSurvey $survey): Result
    {
        $prompt = $this->typePrompt($survey);
        $response = $this->ask('type-analyst', $prompt);
        $usage = $response->usage;
        [$data, $problem, $logged] = $this->readType($response->text);

        if ($data === null) {
            $this->unreadable("the type analysis for {$survey->groupHandle} could not be read ({$logged}); asking again", 'type-analyst', $response->text, ['group' => $survey->groupHandle]);

            $response = $this->ask(
                'type-analyst',
                "Your answer could not be read: {$problem}. Reply again with the whole type, as one YAML document inside a <type> block and nothing else.",
                [new Message('user', $prompt), new Message('assistant', $response->text)],
            );
            $usage = $usage->plus($response->usage);
            [$data, $problem, $logged] = $this->readType($response->text);
        }

        if ($data === null) {
            $this->unreadable("the type analysis for {$survey->groupHandle} could not be read again ({$logged})", 'type-analyst', $response->text, ['group' => $survey->groupHandle]);

            throw new UnreadableReply('The analysis came back in a form that could not be read. Try again.', 'type-analyst', $problem);
        }

        return new Result($data, $usage);
    }

    /**
     * The type analyst's prompt for a survey.
     */
    public function typePrompt(TypeSurvey $survey): string
    {
        return "Section: {$survey->groupTitle} ({$survey->layout->studied} entries studied)\n\n"
            .($survey->title !== null && $survey->title !== '' ? "The editors call this kind of content \"{$survey->title}\". Use that as the title.\n\n" : '')
            .($survey->chosenExamples ? "The entries below were chosen by an editor as the model for this kind of content. Other entries in the section may look different; describe only these.\n\n" : '')
            ."## The fields\n\n".$survey->layout->fields."\n\n"
            ."## Existing entries\n\n".$this->examples($survey->layout)."\n\n"
            .'Write the type.';
    }

    /**
     * The kinds of content a group seems to hold, each with the entries that
     * show it. Kinds already taught or turned down, and kinds with fewer
     * than two real examples, are left out. With fewer than two samples
     * there is nothing to compare, and no call is made.
     *
     * @return Result<array<int, SuggestedKind>>
     *
     * @throws UnreadableReply
     * @throws ProviderException
     */
    public function suggestKinds(KindSurvey $survey): Result
    {
        if (count($survey->samples) < 2) {
            return new Result([]);
        }

        $numeric = $this->prompts->vocabulary()->numericIds;
        $lines = array_map(function (KindSample $sample) use ($numeric) {
            $built = array_values(array_unique(array_filter($sample->builtAs, fn (string $type) => $type !== '')));
            $opening = self::opening($sample->text, self::OPENING_LENGTH);

            return sprintf(
                '- id %s · "%s"%s%s%s%s',
                $numeric ? (string) $sample->id : '"'.$sample->id.'"',
                $sample->title,
                $sample->variantName !== null ? " · {$this->options->variantLabel}: {$sample->variantName}" : '',
                $sample->under !== null ? ' · under: '.$sample->under : '',
                $built !== [] ? ' · built as: '.implode(', ', $built) : '',
                $opening !== '' ? ' · opens: "'.$opening.'"' : '',
            );
        }, array_values($survey->samples));

        $instructions = strtr($this->prompt('kind-finder'), [
            '{{ count }}' => (string) self::KIND_COUNT,
            '{{ taught }}' => $survey->taught !== [] ? implode("\n", array_map(fn (ContentKind $kind) => "- {$kind->title}: {$kind->description}", $survey->taught)) : 'Nothing yet.',
            '{{ dismissed }}' => $survey->dismissed !== [] ? '- '.implode("\n- ", $survey->dismissed) : 'Nothing yet.',
        ]);

        $response = $this->ask('kind-finder', "Section: {$survey->groupTitle}\n\nEntries, newest first:\n".implode("\n", $lines), instructions: $instructions);
        $block = TaggedResponse::parse($response->text, 'kinds')->document;

        if ($block === null) {
            // An empty <kinds></kinds> is the model saying there is nothing
            // to add. No block at all is too, where the addon says so.
            if (preg_match('/<kinds>\s*(<\/kinds>|\z)/', $response->text) === 1 || $this->options->missingKindsIsEmpty) {
                $this->log('info', "no kinds suggested for {$survey->groupHandle}", 'kind-finder', $response->text, ['group' => $survey->groupHandle]);

                return new Result([], $response->usage);
            }

            $this->unreadable("the kinds for {$survey->groupHandle} could not be read (there was no <kinds> block)", 'kind-finder', $response->text, ['group' => $survey->groupHandle]);

            throw new UnreadableReply('Ghostwriter did not come back with any kinds. Try again.', 'kind-finder', 'there was no <kinds> block');
        }

        try {
            $found = (array) LenientYaml::parse($block);
        } catch (Throwable $exception) {
            $this->unreadable("the kinds for {$survey->groupHandle} could not be read (".self::yamlProblem($exception, forLog: true).')', 'kind-finder', $response->text, ['group' => $survey->groupHandle]);

            throw new UnreadableReply('Ghostwriter did not come back with kinds it could read. Try again.', 'kind-finder', self::yamlProblem($exception));
        }

        /** @var array<string, KindSample> $byId */
        $byId = [];

        foreach ($survey->samples as $sample) {
            $byId[(string) $sample->id] ??= $sample;
        }

        $known = array_map('mb_strtolower', [...array_map(fn (ContentKind $kind) => $kind->title, $survey->taught), ...$survey->dismissed]);
        $out = [];

        foreach ($found as $kind) {
            $title = is_array($kind) && is_scalar($kind['title'] ?? null) ? trim((string) $kind['title']) : '';

            if (! is_array($kind) || $title === '' || in_array(mb_strtolower($title), $known, true)) {
                continue;
            }

            // Only entries really among the samples, and at least two of them.
            $ids = [];

            foreach ((array) ($kind['examples'] ?? []) as $id) {
                $key = self::idKey($id, $numeric);

                if ($key !== null && isset($byId[$key]) && ! isset($ids[$key])) {
                    $ids[$key] = $byId[$key];
                }
            }

            if (count($ids) < 2) {
                continue;
            }

            $known[] = mb_strtolower($title);
            $variants = array_unique(array_map(fn (KindSample $sample) => $sample->variantHandle, array_values($ids)));

            $out[] = new SuggestedKind(
                mb_substr($title, 0, self::KIND_TITLE_LENGTH),
                self::field($kind, 'description'),
                self::field($kind, 'why'),
                array_slice(array_map(fn (KindSample $sample) => $sample->id, array_values($ids)), 0, self::KIND_EXAMPLES),
                count($variants) === 1 ? reset($variants) : null,
            );
        }

        return new Result($out, $response->usage);
    }

    /**
     * Ideas for entries the site is missing, from what its groups hold and
     * what is already planned. Ideas for other groups, untitled ones, and
     * ones already on the plan (or repeated) are left out.
     *
     * @return Result<array<int, SuggestedIdea>>
     *
     * @throws UnreadableReply
     * @throws ProviderException
     */
    public function suggestIdeas(PlanContext $context): Result
    {
        $response = $this->ask('planner', trim($context->steer) !== '' ? "What I am looking for this time: {$context->steer}" : 'Suggest what is missing.', instructions: $this->plannerInstructions($context));
        $block = TaggedResponse::parse($response->text, 'ideas')->document;

        if ($block === null) {
            $this->unreadable("the planner's ideas could not be read (there was no <ideas> block)", 'planner', $response->text);

            throw new UnreadableReply('Ghostwriter did not come back with any ideas. Try again.', 'planner', 'there was no <ideas> block');
        }

        try {
            $ideas = (array) LenientYaml::parse($block);
        } catch (Throwable $exception) {
            $this->unreadable("the planner's ideas could not be read (".self::yamlProblem($exception, forLog: true).')', 'planner', $response->text);

            throw new UnreadableReply('Ghostwriter did not come back with ideas it could read. Try again.', 'planner', self::yamlProblem($exception));
        }

        $groups = [];

        foreach ($context->groups as $group) {
            $groups[$group->handle] = array_map(fn (ContentKind $kind) => $kind->handle, $group->kinds);
        }

        $known = array_map(fn (PlannedIdea $idea) => mb_strtolower(trim($idea->title)), $context->plan);
        $groupKey = $this->prompts->vocabulary()->groupKey;
        $out = [];

        foreach ($ideas as $idea) {
            if (! is_array($idea)) {
                continue;
            }

            // The vocabulary's key first; the other addons' words are taken too.
            $handle = '';

            foreach (array_unique([$groupKey, 'collection', 'section', 'resource']) as $key) {
                if (is_scalar($idea[$key] ?? null) && (string) $idea[$key] !== '') {
                    $handle = (string) $idea[$key];

                    break;
                }
            }

            $title = self::field($idea, 'title');

            if ($title === '' || ! isset($groups[$handle]) || in_array(mb_strtolower($title), $known, true)) {
                continue;
            }

            $known[] = mb_strtolower($title);
            $kind = self::field($idea, 'type');

            $out[] = new SuggestedIdea($title, $handle, in_array($kind, $groups[$handle], true) ? $kind : null, self::field($idea, 'why'), self::field($idea, 'notes'));
        }

        return new Result($out, $response->usage);
    }

    /**
     * The planner's instructions, filled in.
     */
    public function plannerInstructions(PlanContext $context): string
    {
        $vocabulary = $this->prompts->vocabulary();
        $items = mb_strtoupper(mb_substr($vocabulary->items, 0, 1)).mb_substr($vocabulary->items, 1);

        $groups = implode("\n\n", array_map(function (PlanGroup $group) use ($items) {
            $kinds = implode("\n", array_map(fn (ContentKind $kind) => "- `{$kind->handle}`: {$kind->title}. {$kind->description}", $group->kinds));
            $lines = implode("\n", array_map(
                fn (PlanItem $item) => '- '.$item->title.($item->published ? '' : " ({$this->options->unpublishedLabel})").($item->summary !== '' ? ': '.$item->summary : ''),
                $group->items,
            ));

            return "### {$group->title} (`{$group->handle}`)\n\nKinds of content written here:\n".($kinds !== '' ? $kinds : '- none defined; leave `type` out')."\n\n{$items}:\n".($lines !== '' ? $lines : '- none yet');
        }, $context->groups));

        $plan = implode("\n", array_map(fn (PlannedIdea $idea) => "- {$idea->title} ({$idea->group}, {$idea->status})", $context->plan));

        return strtr($this->prompt('planner'), [
            '{{ count }}' => (string) $context->count,
            '{{ voice }}' => trim($context->voice) !== '' ? trim($context->voice) : "No guide has been written yet. Judge the reader from the {$vocabulary->items}.",
            '{{ '.$vocabulary->groups.' }}' => $groups,
            '{{ plan }}' => $plan !== '' ? $plan : 'Nothing yet.',
        ]);
    }

    /**
     * The style of one group's images, from a spread of them: the reply's
     * `<document>`, or the whole reply when it has none. Samples the
     * model-input guard refuses (Getty and iStock images) are left out.
     *
     * @param  array<int, ImagerySample>  $samples
     * @return Result<string>
     *
     * @throws ProviderException
     */
    public function analyseImagery(string $groupTitle, array $samples): Result
    {
        $samples = array_values(array_filter($samples, fn (ImagerySample $sample) => $this->guard->allowsImage($sample->image, $sample->asset, $sample->filename)));
        $list = implode("\n", array_map(fn (ImagerySample $sample, int $i) => ($i + 1).". {$sample->label}, on \"{$sample->on}\"", $samples, array_keys($samples)));

        $response = $this->ask('imagery-analyst', "Section: {$groupTitle}\n\nThe attached images, in order:\n{$list}", images: array_map(fn (ImagerySample $sample) => $sample->image, $samples));

        return new Result(TaggedResponse::parse($response->text, 'document')->document ?? trim($response->text), $response->usage);
    }

    /**
     * A first attempt at a kind's brief from a working title and notes, for
     * a person to correct: an answer (plain text, possibly empty) for every
     * question, by handle.
     *
     * @deprecated 1.6.0 The brief screen's "Fill in the brief". The brief is
     *             now filled in the conversation with fillBrief(), which also
     *             keeps facts the person didn't give out. Kept, unchanged,
     *             through 1.x.
     *
     * @param  array<int, string>  $titles  Titles of existing entries of this kind's group, newest first.
     * @return Result<array<string, string>>
     *
     * @throws UnreadableReply
     * @throws ProviderException
     */
    public function draftBrief(ContentKind $kind, string $title, string $notes = '', array $titles = []): Result
    {
        $instructions = $this->briefInstructions('brief-writer', $kind, $titles);

        $response = $this->ask('brief-writer', "Working title: {$title}\n\nNotes:\n".(trim($notes) !== '' ? trim($notes) : '(none)'), instructions: $instructions);
        $failed = 'Ghostwriter could not put a brief together from that. Try again, or fill it in by hand.';
        $block = TaggedResponse::parse($response->text, 'brief')->document;

        if ($block === null) {
            $this->unreadable('the brief could not be read (there was no <brief> block)', 'brief-writer', $response->text);

            throw new UnreadableReply($failed, 'brief-writer', 'there was no <brief> block');
        }

        try {
            $answers = (array) LenientYaml::parse($block);
        } catch (Throwable $exception) {
            $this->unreadable('the brief could not be read ('.self::yamlProblem($exception, forLog: true).')', 'brief-writer', $response->text);

            throw new UnreadableReply($failed, 'brief-writer', self::yamlProblem($exception));
        }

        // Only the questions that were asked, as plain text.
        $out = [];

        foreach ($kind->questions as $question) {
            $answer = $answers[$question->handle] ?? null;
            $out[$question->handle] = trim(is_scalar($answer) ? (string) $answer : '');
        }

        return new Result($out, $response->usage);
    }

    /**
     * The brief, filled in for the conversation's brief card from what the
     * person said (their quick details, or a plan idea): a working title,
     * an answer for every one of the kind's questions, and the records to
     * model it on (the request's, as the brief screen ticked them). One
     * call. For "Try again", pass `$request->tryAgain($brief, $edited)`.
     *
     * Facts about the organisation are never invented: the prompt forbids
     * it, and BriefCheck turns any figure or quotation the person didn't
     * give into `[Add: …]` for them to fill in, without asking again.
     *
     * @return Result<Brief>
     *
     * @throws UnreadableReply when there is no brief to read.
     * @throws ProviderException
     */
    public function fillBrief(BriefRequest $request): Result
    {
        $kind = $request->kind;
        $response = $this->ask('brief-filler', $this->briefFillerPrompt($request), instructions: $this->briefInstructions('brief-filler', $kind, $request->titles));
        $failed = 'Ghostwriter could not fill in the brief from that. Try again, or say a little more about it.';
        $block = TaggedResponse::parse($response->text, 'brief')->document;

        if ($block === null) {
            $this->unreadable('the brief could not be read (there was no <brief> block)', 'brief-filler', $response->text);

            throw new UnreadableReply($failed, 'brief-filler', 'there was no <brief> block');
        }

        try {
            $parsed = (array) LenientYaml::parse((string) preg_replace('/\A```(?:yaml|yml)?\s*\n(.*?)\n?```\s*\z/su', '$1', trim($block)));
        } catch (Throwable $exception) {
            $this->unreadable('the brief could not be read ('.self::yamlProblem($exception, forLog: true).')', 'brief-filler', $response->text);

            throw new UnreadableReply($failed, 'brief-filler', self::yamlProblem($exception));
        }

        $answers = [];

        foreach ($kind->questions as $question) {
            $answer = $parsed[$question->handle] ?? null;
            $answers[$question->handle] = in_array($question->handle, $request->kept, true) && $request->previous !== null
                ? ($request->previous->answers[$question->handle] ?? '')
                : trim(is_scalar($answer) ? (string) $answer : '');
        }

        $title = preg_match('/<title>(.*?)<\/title>/s', $response->text, $match) === 1 ? trim((string) preg_replace('/\s+/u', ' ', $match[1])) : '';

        // Titles the model was given or gave may be quoted, figures and all.
        [$answers, $problems] = BriefCheck::check($kind, $answers, $request->source(), $request->previous !== null ? $request->kept : [], [...$request->titles, $request->title, $title]);

        if ($problems !== []) {
            $this->log('warning', 'the brief had facts the person did not give, now left for them ('.implode('; ', $problems).')', 'brief-filler', $response->text);
        }

        $title = $request->title !== null && $request->title !== '' ? $request->title : ($title !== '' ? mb_substr($title, 0, 200) : self::opening($request->details, 80));

        return new Result(
            new Brief($title, $answers, $request->examples, $request->previous !== null ? $request->previous->attempt + 1 : 1),
            $response->usage,
        );
    }

    /**
     * What the brief filler is sent: what the person said, and for "Try
     * again" the brief they didn't take.
     */
    public function briefFillerPrompt(BriefRequest $request): string
    {
        $said = $request->title !== null && $request->title !== ''
            ? "Working title: {$request->title}\n\nNotes:\n".($request->details !== '' ? $request->details : '(none)')
            : "What your colleague said:\n".($request->details !== '' ? $request->details : '(nothing)');

        if ($request->previous === null) {
            return $said;
        }

        $previous = trim(Yaml::dump($request->previous->answers, 2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        $kept = $request->kept !== []
            ? 'Keep these exactly, your colleague wrote them: '.implode(', ', array_map(fn (string $handle) => "`{$handle}`", $request->kept)).'.'
            : 'Your colleague changed none of the answers.';

        return $said."\n\n<previous_brief>\n<title>{$request->previous->title}</title>\n{$previous}\n</previous_brief>\n\n"
            ."Your colleague asked you to try again. {$kept} Answer the rest afresh.";
    }

    /**
     * The brief writer's or filler's instructions for a kind, filled in.
     *
     * @param  array<int, string>  $titles
     */
    private function briefInstructions(string $agent, ContentKind $kind, array $titles): string
    {
        $questions = implode("\n", array_map(
            fn (Question $question) => "- `{$question->handle}`".($question->required ? ' (required)' : ' (optional)').': '.$question->label.($question->instructions === '' ? '' : ' '.$question->instructions)
                .($question->options === [] ? '' : ' One of: '.implode(', ', array_keys($question->options)).'.'),
            $kind->questions,
        ));

        return strtr($this->prompt($agent), [
            '{{ type_title }}' => $kind->title,
            '{{ type_description }}' => $kind->description,
            '{{ type_guidance }}' => $kind->guidance,
            '{{ questions }}' => $questions,
            '{{ entries }}' => $titles !== [] ? implode("\n", array_map(fn (string $title) => '- '.$title, $titles)) : 'None yet.',
        ]);
    }

    /**
     * A stock photo search for a page: two to four words from its title and
     * summary. The title itself when there is no model, the call fails, or
     * the answer is too long to be a search.
     *
     * @return Result<string>
     */
    public function photoQuery(string $title, string $summary = ''): Result
    {
        if (! $this->configured()) {
            return new Result($title);
        }

        try {
            $response = $this->ask('photo-query', "Title: {$title}\nSummary: {$summary}");
        } catch (ProviderException $exception) {
            $this->logger->warning("Ghostwriter: choosing a photo search failed: {$exception->getMessage()}", ['agent' => 'photo-query']);

            return new Result($title);
        }

        $words = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{N} -]+/u', ' ', $response->text)));

        return new Result($words !== '' && str_word_count($words) <= self::PHOTO_QUERY_WORDS ? $words : $title, $response->usage);
    }

    /**
     * Suggest edits: the review call. One `reviewer` call per batch of a
     * long page (ReviewInput::calls(), said in the confirm before it
     * runs), merged into one reply. Each call sees its units, every free
     * finding in them as a candidate to keep (with its fix) or drop in
     * context, the site digest, what was dismissed or checked fine, and up
     * to four thumbnails for images missing alt text, each through
     * ModelInputGuard (a refused image isn't attached, and a kept finding
     * asks the editor to describe it).
     *
     * The reply is read, not validated: SuggestionValidator decides what
     * is kept, and drops anything that adds a fact. A reply that can't be
     * read gives no suggestions, with a warning; one cut off keeps the
     * suggestions that closed.
     *
     * @return Result<SuggestionReply>
     *
     * @throws ProviderException
     */
    public function suggestEdits(ReviewInput $input): Result
    {
        $instructions = $this->reviewerInstructions($input);
        $reader = new SuggestionReader;
        $usage = new Usage;
        $items = [];
        $problems = [];
        $attached = [];
        $truncated = 0;

        foreach ($input->batches() as $batch) {
            $images = [];
            $ids = [];

            foreach ($batch->images as $finding) {
                $image = $input->images[$finding->id] ?? null;

                if ($image === null || count($images) >= ReviewInput::IMAGES_PER_CALL || $finding->anchor->scope !== AnchorScope::Asset) {
                    continue;
                }

                if ($this->guard->allowsImage($image, $finding->anchor->asset, $finding->anchor->asset?->filename())) {
                    $images[] = $image;
                    $ids[] = $finding->id;
                }
            }

            [$response, $read] = $this->askForItems('reviewer', ReviewPrompt::render($input, $batch, $ids), $reader, self::reviewerSchema(), $images, $instructions, "the review reply couldn't be read", ['part' => ($batch->index + 1).' of '.$batch->total]);
            $usage = $usage->plus($response->usage);
            $attached = [...$attached, ...$ids];

            if ($read['problem'] !== null) {
                $problems[] = $read['problem'];
            } elseif ($read['items'] === []) {
                // Read, with nothing in it: say so, so an empty review is never silent.
                $this->log('info', 'the review reply had nothing to suggest', 'reviewer', $response->text, ['part' => ($batch->index + 1).' of '.$batch->total, 'candidates' => (string) count($batch->findings), 'output_tokens' => (string) $response->usage->output]);
            }

            if ($read['truncated'] || $response->truncated()) {
                $truncated++;
            }

            foreach ($read['items'] as $item) {
                $items[] = ['batch' => $batch->index, 'item' => $item];
            }
        }

        return new Result(new SuggestionReply($items, $input->calls(), $truncated, $problems, $attached), $usage);
    }

    /**
     * The reviewer's instructions: the shared scoped-edit rules, the voice
     * guide and the kind, as the writer reads them. The same for every
     * call of a review, so a provider can cache them.
     */
    public function reviewerInstructions(ReviewInput $input): string
    {
        $kind = $input->writer->kind;
        $claims = $input->context->options->claims
            ? 'You may flag a claim only the editor can confirm ("award-winning", "the only", "the largest") as a `fact-to-check`, never with a value.'
            : 'Don\'t question claims ("award-winning", "the largest"): this site has turned claim checks off. A `fact-to-check` comes only from the findings.';

        return self::tidy(strtr($this->prompt('reviewer'), [
            '{{ scoped_edit_rules }}' => $this->prompt('scoped-edit'),
            '{{ voice }}' => trim($input->writer->voice) !== '' ? trim($input->writer->voice) : 'No voice guide has been written yet. Make no Voice suggestions.',
            '{{ type_title }}' => $kind->title,
            '{{ type_guidance }}' => trim($kind->guidance) !== '' ? trim($kind->guidance) : trim($kind->description),
            '{{ type_checklist }}' => $kind->checklist !== [] ? '- '.implode("\n- ", $kind->checklist) : '- It reads like the site\'s other pages.',
            '{{ cap }}' => (string) $input->cap,
            '{{ claims }}' => $claims,
            '{{ reply_language }}' => self::languageName($input->replyLanguage),
            '{{ part }}' => $input->calls() > 1 ? "- This page is long, so it is reviewed in parts. Review only the units shown; the others are reviewed separately.\n" : '',
            ...$this->answerFormat('reviewer', self::reviewerSchema(), 'suggestions'),
        ]));
    }

    /**
     * Suggest edits, the second pass: one `verifier` call per part of the
     * review that kept anything, after SuggestionValidator. Each call gets
     * the part's kept suggestions, numbered s1, s2… across the review
     * (VerifyPrompt), each with its whole paragraph, the heading it sits
     * under and the site entry it cites; the voice guide and the kind are
     * in the instructions. The reply (`<verdicts>`) is read, not applied:
     * SuggestionValidator::verify() does that, checking every fix again.
     *
     * A part whose reply can't be read gives no verdicts, with a warning
     * (its suggestions stay as the reviewer wrote them).
     *
     * @param  list<Suggestion>  $suggestions  ValidatedReview::$suggestions, in order.
     * @return Result<SuggestionReply> Items are `{id, verdict, reason, replacement?, alternatives?}`; `calls` is how many calls were made.
     *
     * @throws ProviderException
     */
    public function verifyEdits(ReviewInput $input, array $suggestions): Result
    {
        $instructions = $this->verifierInstructions($input);
        $reader = new SuggestionReader('verdicts');
        $usage = new Usage;
        $items = [];
        $problems = [];
        $truncated = 0;
        $calls = 0;
        $byBatch = [];

        foreach (array_values($suggestions) as $i => $suggestion) {
            $byBatch[$input->batchFor($suggestion->anchor)]['s'.($i + 1)] = $suggestion;
        }

        foreach ($input->batches() as $batch) {
            if (($byBatch[$batch->index] ?? []) === []) {
                continue;
            }

            [$response, $read] = $this->askForItems('verifier', VerifyPrompt::render($input, $batch, $byBatch[$batch->index]), $reader, self::verifierSchema(), [], $instructions, "the verifier's reply couldn't be read", ['part' => ($batch->index + 1).' of '.$batch->total]);
            $usage = $usage->plus($response->usage);
            $calls++;

            if ($read['problem'] !== null) {
                $problems[] = $read['problem'];
            }

            if ($read['truncated'] || $response->truncated()) {
                $truncated++;
            }

            foreach ($read['items'] as $item) {
                $items[] = ['batch' => $batch->index, 'item' => $item];
            }
        }

        return new Result(new SuggestionReply($items, $calls, $truncated, $problems), $usage);
    }

    /**
     * The verifier's instructions: the shared scoped-edit rules, the voice
     * guide and the kind. The same for every call of a review.
     */
    public function verifierInstructions(ReviewInput $input): string
    {
        $kind = $input->writer->kind;

        return self::tidy(strtr($this->prompt('verifier'), [
            '{{ scoped_edit_rules }}' => $this->prompt('scoped-edit'),
            '{{ voice }}' => trim($input->writer->voice) !== '' ? trim($input->writer->voice) : 'No voice guide has been written yet. Judge the voice by the rest of the page.',
            '{{ type_title }}' => $kind->title,
            '{{ type_guidance }}' => trim($kind->guidance) !== '' ? trim($kind->guidance) : trim($kind->description),
            '{{ reply_language }}' => self::languageName($input->replyLanguage),
            ...$this->answerFormat('verifier', self::verifierSchema(), 'verdicts'),
        ]));
    }

    /** The review reply's shape, for structured output (resources/schemas/reviewer-reply.json). */
    public static function reviewerSchema(): OutputSchema
    {
        static $schema;

        return $schema ??= OutputSchema::fromFile('review', dirname(__DIR__, 2).'/resources/schemas/reviewer-reply.json');
    }

    /** The verifier reply's shape, for structured output (resources/schemas/verifier-reply.json). */
    public static function verifierSchema(): OutputSchema
    {
        static $schema;

        return $schema ??= OutputSchema::fromFile('verdicts', dirname(__DIR__, 2).'/resources/schemas/verifier-reply.json');
    }

    /**
     * Whether the model this agent's calls go to is held to a schema
     * (structured output), so the prompt needn't ask for tags.
     */
    public function takesSchema(string $agent, OutputSchema $schema): bool
    {
        $provider = $this->provider();

        return $provider instanceof TakesSchemas && $provider->takesSchema(new TextRequest($agent, '', '', schema: $schema));
    }

    /**
     * How a prompt's "How you answer" opens and closes: bare JSON when the
     * model is held to the schema, the JSON in tags when it is only asked.
     *
     * @return array<string, string>
     */
    private function answerFormat(string $agent, OutputSchema $schema, string $tag): array
    {
        $held = $this->configured() && $this->takesSchema($agent, $schema);

        return [
            '{{ answer_intro }}' => $held ? 'Your reply is JSON in the shape you are given, like this:' : 'Only this, with nothing before or after it:',
            '{{ answer_open }}' => $held ? '' : "<{$tag}>",
            '{{ answer_close }}' => $held ? '' : "</{$tag}>",
        ];
    }

    /** Instructions with no run of blank lines left by an empty placeholder. */
    private static function tidy(string $text): string
    {
        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * One call whose reply is a list of items (SuggestionReader), sent with
     * its schema for structured output. A reply that can't be read, and
     * wasn't cut off, is asked for once more with what was wrong quoted;
     * the second reply stands, read or not. Both calls' usage is counted.
     *
     * @param  array<int, Image>  $images
     * @param  array<string, string>  $context  For the log.
     * @return array{0: TextResponse, 1: array{items: list<array<string, mixed>>, truncated: bool, problem: ?string}}
     *
     * @throws ProviderException
     */
    private function askForItems(string $agent, string $prompt, SuggestionReader $reader, OutputSchema $schema, array $images, string $instructions, string $what, array $context): array
    {
        $response = $this->ask($agent, $prompt, images: $images, instructions: $instructions, schema: $schema);
        $read = $reader->read($response->text);

        if ($read['problem'] === null || $read['truncated'] || $response->truncated()) {
            if ($read['problem'] !== null) {
                $this->unreadable("{$what} ({$read['problem']})", $agent, $response->text, $context);
            }

            return [$response, $read];
        }

        $this->unreadable("{$what} ({$read['problem']}); asking again once", $agent, $response->text, $context);

        $again = $this->ask($agent, $prompt."\n\nYour last answer to this couldn't be read: {$read['problem']}. Answer again, in full, exactly in the format asked.", images: $images, instructions: $instructions, schema: $schema);
        $read = $reader->read($again->text);

        if ($read['problem'] !== null) {
            $this->unreadable("{$what} again ({$read['problem']})", $agent, $again->text, $context);
        }

        return [$again->withUsage($response->usage->plus($again->usage)), $read];
    }

    /**
     * "Write another": two more versions of a suggestion's words, from one
     * small `reworder` call. The versions are unvalidated: EditReviews
     * keeps only those SuggestionValidator::acceptsVersion() passes.
     *
     * @return Result<list<string>>
     *
     * @throws ProviderException
     */
    public function reword(RewordRequest $request): Result
    {
        $instructions = strtr($this->prompt('reworder'), [
            '{{ scoped_edit_rules }}' => $this->prompt('scoped-edit'),
            '{{ voice }}' => trim($request->voice) !== '' ? trim($request->voice) : 'No voice guide has been written yet. Write plainly.',
        ]);
        $response = $this->ask('reworder', $request->prompt(), instructions: $instructions);
        preg_match_all('/<version>(.*?)(?:<\/version>|$)/s', $response->text, $matches);
        $shown = array_map(fn (string $version) => mb_strtolower(trim($version)), $request->shown);
        $versions = [];

        foreach ($matches[1] as $version) {
            $version = trim($version, " \t\n\r\0\x0B\"'“”");

            if ($version !== '' && ! in_array(mb_strtolower($version), $shown, true) && ! in_array($version, $versions, true)) {
                $versions[] = $version;
            }
        }

        if ($versions === []) {
            $this->unreadable('the reworder gave no new version', 'reworder', $response->text);
        }

        return new Result(array_slice($versions, 0, 2), $response->usage);
    }

    /** A language's name in English, for the model: "en_GB" is "English". */
    private static function languageName(string $locale): string
    {
        $names = ['en' => 'English', 'de' => 'German', 'fr' => 'French', 'nl' => 'Dutch', 'es' => 'Spanish', 'it' => 'Italian', 'pt' => 'Portuguese', 'cy' => 'Welsh', 'da' => 'Danish', 'sv' => 'Swedish', 'nb' => 'Norwegian', 'pl' => 'Polish'];
        $language = Phrases::language($locale);

        return $names[$language] ?? $locale;
    }

    /**
     * One "fix that writes" for a gap in an entry (Finish this page), made
     * only when an editor presses a button that says it uses Ghostwriter:
     * a summary, a shorter text, a sentence written around a missing fact,
     * or alt text. The answer goes into the form, never straight into the
     * saved entry.
     *
     * Facts come only from the editor: GapRequest refuses to be made for
     * one, the prompt forbids adding any, and an answer that still holds a
     * marker, or (except alt text) has a figure the given text doesn't, is
     * not used.
     *
     * @return Result<string>
     *
     * @throws GapRefused when the image's library allows no model to see it.
     * @throws UnreadableReply when the answer can't be used.
     * @throws ProviderException
     */
    public function fillGap(GapRequest $request): Result
    {
        $images = [];

        if ($request->image !== null) {
            if (! $this->guard->allowsImage($request->image, $request->asset, $request->filename)) {
                throw GapRefused::image();
            }

            $images[] = $request->image;
        }

        $response = $this->ask('gap-filler', $request->prompt(), images: $images);
        $text = preg_match('/<result>(.*?)(?:<\/result>|$)/s', $response->text, $match) === 1 ? $match[1] : $response->text;
        $text = trim($text, " \t\n\r\0\x0B\"'“”‘’");

        if (in_array($request->task, [GapRequest::SUMMARY, GapRequest::ALT, GapRequest::WRITE_AROUND], true)) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        }

        $failed = 'Ghostwriter\'s answer couldn\'t be used. Try again, or write it yourself.';

        if (Markers::has($text) || Markers::leftovers($text) !== [] || str_contains($text, '[[')) {
            $this->unreadable('the answer held a marker', 'gap-filler', $response->text, ['task' => $request->task]);

            throw new UnreadableReply($failed, 'gap-filler', 'the answer held a marker');
        }

        if ($request->task !== GapRequest::ALT && ($added = self::newFigures($text, $request->text)) !== []) {
            $this->unreadable('the answer added a figure ('.implode(', ', $added).')', 'gap-filler', $response->text, ['task' => $request->task]);

            throw new UnreadableReply($failed, 'gap-filler', 'the answer added a figure the text did not have');
        }

        if ($request->limit !== null && mb_strlen($text) > $request->limit) {
            $text = Slug::clip($text, $request->limit);
        }

        return new Result($text, $response->usage);
    }

    /**
     * Figures in an answer that the text it came from doesn't have,
     * compared by what they say (Figures): "£1.2m" is in a text that says
     * "£1,200,000", and "8 weeks" in one that says "eight weeks".
     *
     * @return list<string>
     */
    private static function newFigures(string $answer, string $source): array
    {
        $known = Figures::known(Markers::withoutAsks($source));
        $added = array_filter(Figures::find($answer), fn (array $figure) => ! Figures::given($figure['values'], $known));

        return array_values(array_unique(array_map(fn (array $figure) => $figure['figure'], $added)));
    }

    /**
     * The summary line of a YAML draft (`summary`, `excerpt`, `description`
     * or `intro`), for photoQuery(); empty when it has none.
     */
    public static function summaryOf(?string $draft): string
    {
        return $draft !== null && preg_match('/^(?:summary|excerpt|description|intro):\s*(.+)$/mu', $draft, $m) === 1 ? trim($m[1], " \t\"'") : '';
    }

    /**
     * The next turn of a writing session. The conversation's last message is
     * the person's latest (on the first turn, the brief); the current draft
     * goes in front of it.
     *
     * @throws Truncated when the draft is cut off even with more room.
     * @throws ProviderException
     */
    public function write(Conversation $conversation, WriterContext $context): TaggedResponse
    {
        $messages = $conversation->messages;
        $latest = array_pop($messages);
        $draft = $conversation->draft ?? '';

        $prompt = ($draft !== '' ? "<current_draft>\n{$draft}\n</current_draft>\n\n" : '').($latest->content ?? '');

        $response = $this->ask('writer', $prompt, $messages, instructions: $this->writerInstructions($context));

        return TaggedResponse::parse($response->text, 'draft', $response->usage->input, $response->usage->output);
    }

    /**
     * Up to `$brief->count` other layouts of a draft, from one call to the
     * layout planner, which sees summaries of the units and extras, the
     * blocks it may use and the site's patterns, never the voice guide or
     * examples. The plans are unvalidated (Arrange\PlanValidator decides),
     * numbered p1, p2… An unreadable reply gives none, with a warning; a
     * cut-off one keeps the plans already complete.
     *
     * @return Result<list<Plan>>
     *
     * @throws ProviderException
     */
    public function planLayouts(LayoutBrief $brief): Result
    {
        $instructions = strtr($this->prompt('layout-planner'), ['{{ count }}' => (string) $brief->count]);
        $response = $this->ask('layout-planner', $brief->prompt(), instructions: $instructions);
        $block = preg_match('/<plans>(.*?)(?:<\/plans>|$)/s', $response->text, $m) === 1 ? $m[1] : null;

        if ($block !== null && $response->truncated()) {
            // Keep the plans that are whole: drop the one the cut-off ended in.
            $block = (string) preg_replace('/\n- [^\n]*(?:\n(?!- ).*)*\z/u', '', rtrim($block));
        }

        $reader = new PlanReader;
        $plans = array_slice($reader->read($block, $brief->schema), 0, max(0, $brief->count));

        if ($plans === []) {
            $this->unreadable("the layout planner's reply had no usable plans ({$reader->problem})", 'layout-planner', $response->text);
        }

        return new Result($plans, $response->usage);
    }

    /**
     * The questionnaire answers as the opening message of a session: the
     * message the writer starts from. With a working title (the brief
     * card's), it comes first.
     *
     * @param  array<string, mixed>  $answers  By question handle.
     */
    public function brief(ContentKind $kind, array $answers, ?string $title = null): string
    {
        $lines = ["Here is the brief for a new {$this->prompts->vocabulary()->item}: {$kind->title}.", ''];

        if ($title !== null && trim($title) !== '') {
            array_push($lines, '**Working title**', trim($title), '');
        }

        foreach ($kind->questions as $question) {
            $answer = $answers[$question->handle] ?? '';
            $answer = trim(is_scalar($answer) ? (string) $answer : '');

            $lines[] = '**'.$question->label.'**';
            $lines[] = $answer !== '' ? $answer : '(not answered)';
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    /**
     * The writer's instructions for a kind, filled in.
     */
    public function writerInstructions(WriterContext $context): string
    {
        return $this->writerBase($context).$this->extrasSection($context);
    }

    /**
     * The reviser's instructions: the writer's (voice, rules, gap markers,
     * the fields, the examples) without the extras section, then the
     * `reviser` prompt, which asks for a `<changes>` block instead.
     */
    public function reviserInstructions(WriterContext $context): string
    {
        return $this->writerBase($context)."\n\n".$this->prompt('reviser');
    }

    /**
     * Revises the draft from comments (Review\RevisionRequest): one call to
     * the `reviser` agent, whatever the number of comments. The reply is
     * read but not validated (Review\RevisionValidator decides what is
     * applied). An unreadable reply gives no items, with a warning.
     *
     * @return Result<RevisionReply>
     *
     * @throws Truncated when the reply is cut off even with more room.
     * @throws ProviderException
     */
    public function revise(RevisionRequest $request): Result
    {
        $response = $this->ask('reviser', $request->prompt(), instructions: $this->reviserInstructions($request->writer));
        $reply = RevisionReply::read($response->text);

        if ($reply->problem !== '') {
            $this->unreadable("the reviser's reply had no usable changes ({$reply->problem})", 'reviser', $response->text);
        }

        return new Result($reply, $response->usage);
    }

    private function writerBase(WriterContext $context): string
    {
        $kind = $context->kind;

        return strtr($this->prompt('writer'), [
            '{{ voice }}' => trim($context->voice) !== '' ? trim($context->voice) : 'No guide has been written yet. Write plainly and specifically, and match the existing entries shown below.',
            '{{ type_title }}' => $kind->title,
            '{{ type_description }}' => $kind->description,
            '{{ type_guidance }}' => $kind->guidance,
            '{{ type_checklist }}' => $kind->checklist !== [] ? '- '.implode("\n- ", $kind->checklist) : '- It reads like the existing entries.',
            '{{ fields }}' => $context->layout->fields,
            '{{ examples }}' => $this->examples($context->layout),
            '{{ images }}' => $context->images,
        ]);
    }

    /**
     * The extras the writer may prepare with its draft, for the block types
     * this site has (Arrange\Extras\ExtraSlots). Empty when the layout has
     * no schema, or the schema has no place for any extra, so the writer's
     * instructions are then exactly as they were.
     */
    public function extrasSection(WriterContext $context): string
    {
        $slots = ExtraSlots::for($context->layout->schema);

        if ($slots->isEmpty()) {
            return '';
        }

        return "\n\n".strtr($this->prompt('writer-extras'), ['{{ extras }}' => $slots->describe()]);
    }

    /**
     * The extras in a writer's reply, keeping only items whose every fact
     * has a source (Arrange\Extras\ExtrasReader). Sources are the person's
     * words, the draft and the examples the writer was shown; `$exampleIds`
     * are those examples' entry ids, in order, when the adapter knows them.
     *
     * @param  array<int, int|string|null>  $exampleIds
     */
    public function extras(TaggedResponse $response, Conversation $conversation, WriterContext $context, array $exampleIds = []): Extras
    {
        return (new ExtrasReader($this->logger))->read(
            $response->extras,
            ExtraSlots::for($context->layout->schema),
            ExtraSources::fromWriter($conversation, $response->document ?? $conversation->draft, $context->layout, $exampleIds),
        );
    }

    /**
     * Send one request to the model.
     *
     * The agent is the prompt's name: it gives the instructions (unless they
     * are given, filled in), and core's Agents table the token limit and the
     * effort. A reply that runs out of room is asked for once more with
     * twice the room, up to MAX_TOKENS_CEILING. If it is still cut off, the
     * WHOLE agents throw Truncated and the rest keep what came back. The
     * response's usage counts every call made. With a schema, the provider
     * holds the reply to it where it can (TextResponse::$structured).
     *
     * @param  array<int, Message|array<string, mixed>>  $history
     * @param  array<int, Image>  $images
     *
     * @throws Truncated
     * @throws ProviderException
     */
    public function ask(string $agent, string $prompt, array $history = [], array $images = [], ?string $instructions = null, ?int $timeout = null, ?OutputSchema $schema = null): TextResponse
    {
        $request = new TextRequest($agent, $instructions ?? $this->prompt($agent), $prompt, self::messages($history), array_values($images), timeout: $timeout, schema: $schema);
        $provider = $this->provider();
        $response = $provider->text($request);

        if (! $response->truncated()) {
            return $response;
        }

        $limit = $request->resolvedMaxTokens();
        $more = min(self::MAX_TOKENS_CEILING, $limit * 2);
        $usage = $response->usage;

        if ($more > $limit) {
            $this->logger->warning("Ghostwriter: the {$agent} reply ran out of room at {$limit} tokens; asking again with {$more}.", ['agent' => $agent, 'provider' => $response->provider, 'model' => $response->model]);

            $response = $provider->text($request->withMaxTokens($more));
            $usage = $usage->plus($response->usage);
            $limit = $more;
        }

        $response = $response->withUsage($usage);

        if (! $response->truncated()) {
            return $response;
        }

        if (in_array($agent, StudioOptions::WHOLE, true)) {
            throw new Truncated($this->options->cutOffMessages[$agent], $response->provider);
        }

        $this->logger->warning("Ghostwriter: the {$agent} reply ran out of room at {$limit} tokens; keeping what came back.", ['agent' => $agent, 'provider' => $response->provider, 'model' => $response->model]);

        return $response;
    }

    /**
     * The first characters of some prose, on one line.
     */
    public static function opening(string $text, int $length): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_substr($text, 0, $length)));
    }

    /**
     * @param  array<int|string, Message|array<string, mixed>>  $messages
     * @return array<int, Message>
     */
    public static function messages(array $messages): array
    {
        return array_values(array_map(
            fn (Message|array $message) => $message instanceof Message
                ? $message
                : new Message(is_scalar($message['role'] ?? null) ? (string) $message['role'] : 'user', is_scalar($message['content'] ?? null) ? (string) $message['content'] : ''),
            $messages,
        ));
    }

    private function provider(): TextProvider
    {
        return $this->model instanceof Providers ? $this->model->text() : $this->model;
    }

    private function examples(Layout $layout): string
    {
        if ($layout->examples === []) {
            return 'Nothing has been published here yet, so there are no examples. Follow the fields and the guidance.';
        }

        $examples = array_values($layout->examples);

        return implode("\n\n", array_map(function (array $example, int $i) {
            $yaml = trim(Yaml::dump($example, $this->options->exampleDepth, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

            if (mb_strlen($yaml) > self::EXAMPLE_LIMIT) {
                $yaml = mb_substr($yaml, 0, self::EXAMPLE_LIMIT)."\n# (example cut short)";
            }

            return '<example number="'.($i + 1)."\">\n{$yaml}\n</example>";
        }, $examples, array_keys($examples)));
    }

    /**
     * The type the analyst wrote, or why it could not be read: told to the
     * model in full, and to the log without quoting the reply.
     *
     * @return array{0: array<string, mixed>|null, 1: string, 2: string}
     */
    private function readType(string $text): array
    {
        $yaml = TaggedResponse::parse($text, 'type')->document;

        if ($yaml === null) {
            return [null, 'there was no <type> block', 'there was no <type> block'];
        }

        // Models sometimes put the YAML in a code fence inside the block.
        $yaml = (string) preg_replace('/\A```(?:yaml|yml)?\s*\n(.*?)\n?```\s*\z/su', '$1', trim($yaml));

        try {
            $data = LenientYaml::parse($yaml);
        } catch (Throwable $exception) {
            return [null, self::yamlProblem($exception), self::yamlProblem($exception, forLog: true)];
        }

        if (! is_array($data) || empty($data['questions']) || ! is_array($data['questions'])) {
            return [null, 'it had no questions', 'it had no questions'];
        }

        /** @var array<string, mixed> $data */
        return [$data, '', ''];
    }

    /**
     * Why YAML didn't parse. For the model, the parser's whole message; for
     * the log, only where, since the parser quotes the text near the fault.
     */
    private static function yamlProblem(Throwable $exception, bool $forLog = false): string
    {
        if (! $forLog) {
            return 'the YAML did not parse ('.$exception->getMessage().')';
        }

        return $exception instanceof ParseException && $exception->getParsedLine() > 0
            ? "the YAML did not parse at line {$exception->getParsedLine()}"
            : 'the YAML did not parse';
    }

    /**
     * A reply that couldn't be read: a warning saying why, with the reply
     * only when the options allow it.
     *
     * @param  array<string, string>  $context
     */
    private function unreadable(string $what, string $agent, string $reply, array $context = []): void
    {
        $this->log('warning', $what, $agent, $reply, $context);
    }

    /**
     * @param  'info'|'warning'  $level
     * @param  array<string, string>  $context
     */
    private function log(string $level, string $what, string $agent, string $reply, array $context = []): void
    {
        $context = ['agent' => $agent] + $context;

        if ($this->options->logReplies) {
            $context['reply'] = $reply;
        }

        $this->logger->log($level, "Ghostwriter: {$what}.", $context);
    }

    /**
     * A sample ID as the model wrote it, as the key it was listed under.
     */
    private static function idKey(mixed $id, bool $numeric): ?string
    {
        if (! is_scalar($id) || is_bool($id)) {
            return null;
        }

        $id = trim((string) $id);

        return $numeric && is_numeric($id) ? (string) (int) $id : $id;
    }

    /**
     * @param  array<mixed>  $item
     */
    private static function field(array $item, string $key): string
    {
        return is_scalar($item[$key] ?? null) ? trim((string) $item[$key]) : '';
    }
}
