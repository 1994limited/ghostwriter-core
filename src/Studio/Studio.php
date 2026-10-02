<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\Truncated;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Message;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextResponse;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\ModelInputGuard;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Text\LenientYaml;
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
     * @param  array<int, string>  $titles  Titles of existing entries of this kind's group, newest first.
     * @return Result<array<string, string>>
     *
     * @throws UnreadableReply
     * @throws ProviderException
     */
    public function draftBrief(ContentKind $kind, string $title, string $notes = '', array $titles = []): Result
    {
        $questions = implode("\n", array_map(
            fn (Question $question) => "- `{$question->handle}`".($question->required ? ' (required)' : ' (optional)').': '.$question->label.($question->instructions === '' ? '' : ' '.$question->instructions)
                .($question->options === [] ? '' : ' One of: '.implode(', ', array_keys($question->options)).'.'),
            $kind->questions,
        ));

        $instructions = strtr($this->prompt('brief-writer'), [
            '{{ type_title }}' => $kind->title,
            '{{ type_description }}' => $kind->description,
            '{{ type_guidance }}' => $kind->guidance,
            '{{ questions }}' => $questions,
            '{{ entries }}' => $titles !== [] ? implode("\n", array_map(fn (string $title) => '- '.$title, $titles)) : 'None yet.',
        ]);

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
     * The questionnaire answers as the opening message of a session.
     *
     * @param  array<string, mixed>  $answers  By question handle.
     */
    public function brief(ContentKind $kind, array $answers): string
    {
        $lines = ["Here is the brief for a new {$this->prompts->vocabulary()->item}: {$kind->title}.", ''];

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
     * Send one request to the model.
     *
     * The agent is the prompt's name: it gives the instructions (unless they
     * are given, filled in), and core's Agents table the token limit and the
     * effort. A reply that runs out of room is asked for once more with
     * twice the room, up to MAX_TOKENS_CEILING. If it is still cut off, the
     * WHOLE agents throw Truncated and the rest keep what came back. The
     * response's usage counts every call made.
     *
     * @param  array<int, Message|array<string, mixed>>  $history
     * @param  array<int, Image>  $images
     *
     * @throws Truncated
     * @throws ProviderException
     */
    public function ask(string $agent, string $prompt, array $history = [], array $images = [], ?string $instructions = null, ?int $timeout = null): TextResponse
    {
        $request = new TextRequest($agent, $instructions ?? $this->prompt($agent), $prompt, self::messages($history), array_values($images), timeout: $timeout);
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

        $response = new TextResponse($response->text, $response->stopReason, $usage, $response->provider, $response->model);

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
