<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Studio;

use Closure;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;
use NineteenNinetyFour\Ghostwriter\Core\Studio\BriefRequest;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ImagerySample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\KindSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanContext;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanGroup;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlanItem;
use NineteenNinetyFour\Ghostwriter\Core\Studio\PlannedIdea;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\StudioOptions;
use NineteenNinetyFour\Ghostwriter\Core\Studio\TypeSurvey;
use NineteenNinetyFour\Ghostwriter\Core\Studio\VoiceSample;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;

/**
 * Representative inputs per addon, for the parity fixtures in
 * tests/Fixtures/studio/{addon}/{case}.json. Each case is the job to run,
 * its inputs, and the replies the fake gives; the fixture holds the inputs
 * as data and the exact requests core sends for them.
 *
 * The data is shaped like each addon's own: Statamic's UUID entry IDs and
 * blueprints, Craft's numeric IDs and entry types, Filament's records.
 */
final class ParityCases
{
    /**
     * @return array<string, array{Vocabulary, StudioOptions}>
     */
    public static function addons(): array
    {
        return [
            'statamic' => [Vocabulary::statamic(), StudioOptions::statamic()],
            'craft' => [Vocabulary::craft(), StudioOptions::craft()],
            'filament' => [Vocabulary::filament(), StudioOptions::filament()],
        ];
    }

    /**
     * @return array<string, array{job: string, inputs: array<int, mixed>, replies: array<string, array<int, string>>, run: Closure(Studio): mixed}>
     */
    public static function cases(string $addon): array
    {
        $words = match ($addon) {
            'craft' => ['group' => 'News', 'handle' => 'news', 'ids' => [101, 102, 103], 'variants' => ['article', 'pressRelease'], 'variantNames' => ['Article', 'Press release'], 'images' => 'This site has no image tools switched on. Leave image fields out of the draft; a person adds images afterwards. If asked for images, say so plainly.'],
            'filament' => ['group' => 'Posts', 'handle' => 'posts', 'ids' => ['7', '9', '12'], 'variants' => [null, null], 'variantNames' => [null, null], 'images' => 'Leave image and upload fields out of the draft; a person adds images afterwards.'],
            default => ['group' => 'Articles', 'handle' => 'articles', 'ids' => ['3f1c9a2e-5b7d-4e8f-9a01-1b2c3d4e5f60', '8a7b6c5d-4e3f-4a1b-9c8d-7e6f5a4b3c2d', 'c0ffee00-1234-4abc-8def-001122334455'], 'variants' => ['article', 'case_study'], 'variantNames' => ['Article', 'Case study'], 'images' => "## Images\n\nImage fields: hero_image. Leave them out of the draft; describe what each should show in an <images> block."],
        };
        $several = $addon !== 'filament';
        [$one, $two, $three] = $words['ids'];

        $kind = ContentKind::fromArray('project', [
            'title' => 'Project write-up',
            'description' => 'One project, told from the brief to the result.',
            'guidance' => "Lead with the outcome.\nName the client only when the brief does.",
            'checklist' => ['Says what was made.', 'Has one figure.'],
            'questions' => [
                ['handle' => 'client', 'label' => 'Who was it for?', 'instructions' => 'As the client would name themselves.', 'required' => true],
                ['handle' => 'scope', 'label' => 'What was the work?', 'options' => ['site' => 'A website', 'app' => 'An app']],
                ['handle' => 'result', 'label' => 'What changed?', 'required' => false],
            ],
        ]);
        $layout = Layout::fromPattern(
            "- `title` (text, required): The project's name.\n- `summary` (long text): One or two sentences.\n- `body` (rich text): The write-up, in markdown.",
            ['entries' => 3, 'examples' => [
                ['title' => 'Harbour Trust', 'summary' => 'A new site for the harbour.', 'body' => "## The brief\n\nThey wanted visitors.\n\n## What we made\n\nA map: \"tides\" and all."],
                ['title' => 'Mill Lane', 'summary' => "Rebuilt in 'six weeks'.", 'body' => 'Short.', 'meta' => ['seo' => ['title' => 'Mill', 'tags' => ['a', 'b']]]],
            ]],
        );
        $writer = new WriterContext($kind, "# Voice\n\nWarm, plain, specific.", $layout, $words['images']);
        $png = new Image(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true) ?: '', 'image/png');
        $type = "<type>\ntitle: Project write-up\ndescription: One project.\nquestions:\n  - handle: client\n    label: Who was it for?\n</type>";

        $cases = [
            'voice' => [
                'job' => 'analyseVoice',
                'inputs' => [[
                    new VoiceSample('Harbour Trust & the "tides"', $words['handle'], "We made a site.\n\nIt works."),
                    new VoiceSample('About us', 'pages', 'We are small <and> proud.'),
                ]],
                'replies' => ['voice-analyst' => ["# Voice\n\nWarm."]],
            ],
            'voice-refine' => [
                'job' => 'refineVoice',
                'inputs' => ["# Voice\n\nWarm.", [['role' => 'user', 'content' => 'Make it warmer.'], ['role' => 'assistant', 'content' => 'Done.']], 'Now shorter.'],
                'replies' => ['voice-editor' => ["<reply>Shorter.</reply>\n<document>\n# Voice\n</document>"]],
            ],
            'type' => [
                'job' => 'analyseType',
                'inputs' => [new TypeSurvey($words['group'], $words['handle'], $layout, 'Project write-up', true)],
                'replies' => ['type-analyst' => [$type]],
            ],
            'type-reask' => [
                'job' => 'analyseType',
                'inputs' => [new TypeSurvey($words['group'], $words['handle'], new Layout($layout->fields, [], 0))],
                'replies' => ['type-analyst' => ["<type>\ntitle: Project\nquestions: none\n</type>", $type]],
            ],
            'kinds' => [
                'job' => 'suggestKinds',
                'inputs' => [new KindSurvey($words['group'], $words['handle'], [
                    new KindSample($one, 'Harbour Trust', "A new site for the harbour.\n\nThey wanted   visitors.", ['hero', 'long_form', 'cards', 'hero'], $several ? 'Work' : null, $words['variants'][0], $several ? $words['variantNames'][0] : null),
                    new KindSample($two, 'Mill Lane', str_repeat('Rebuilt in six weeks. ', 15), ['hero'], null, $words['variants'][0], $several ? $words['variantNames'][0] : null),
                    new KindSample($three, 'Award win', '', [], null, $words['variants'][1], $several ? $words['variantNames'][1] : null),
                ], [new ContentKind('news', 'News', 'Short company news.')], ['Press release'])],
                'replies' => ['kind-finder' => ["<kinds>\n- title: Project write-up\n  description: One project.\n  why: Two share a hero and cards.\n  examples: ".json_encode([$one, $two])."\n</kinds>"]],
            ],
            'ideas' => [
                'job' => 'suggestIdeas',
                'inputs' => [new PlanContext(
                    [
                        new PlanGroup($words['group'], $words['handle'], [$kind, new ContentKind('news', 'News', 'Short company news.')], [
                            $addon === 'statamic' ? new PlanItem('Harbour Trust', true, 'A new site for the harbour.') : PlanItem::fromProse('Harbour Trust', true, "A new site for the harbour.\n\nThey wanted visitors."),
                            new PlanItem('Mill Lane', false),
                        ]),
                        new PlanGroup('Pages', 'pages'),
                    ],
                    [new PlannedIdea('Rebuild or refresh?', $words['handle'], 'idea'), new PlannedIdea('Our team', 'pages', 'drafted')],
                    "# Voice\n\nWarm.",
                    'something about pricing',
                    8,
                )],
                'replies' => ['planner' => ["<ideas>\n- title: How to brief us\n  ".Vocabulary::{$addon}()->groupKey.": {$words['handle']}\n  type: project\n  why: Nothing on briefing.\n  notes: Start with the budget.\n</ideas>"]],
            ],
            'ideas-empty' => [
                'job' => 'suggestIdeas',
                'inputs' => [new PlanContext([new PlanGroup($words['group'], $words['handle'])])],
                'replies' => ['planner' => ['<ideas>[]</ideas>']],
            ],
            'imagery' => [
                'job' => 'analyseImagery',
                'inputs' => [$words['group'], [new ImagerySample('Hero image', 'Harbour Trust', $png), new ImagerySample('Gallery', 'Mill "Lane"', $png)]],
                'replies' => ['imagery-analyst' => ["<document>\nWarm close-ups.\n</document>"]],
            ],
            'brief-draft' => [
                'job' => 'draftBrief',
                'inputs' => [$kind, 'Kiln opening', "Opens in May.\nFor the trust.", ['Harbour Trust', 'Mill Lane']],
                'replies' => ['brief-writer' => ["<brief>\nclient: The Harbour Trust\nscope: site\nresult: More visitors.\n</brief>"]],
            ],
            'brief-fill' => [
                'job' => 'fillBrief',
                'inputs' => [BriefRequest::fromDetails($kind, 'Kiln opening. Opens in May, for the trust.', ['Harbour Trust', 'Mill Lane'], [$one, $two])],
                'replies' => ['brief-filler' => ["<title>The new kiln opens</title>\n<brief>\nclient: The Harbour Trust\nscope: site\nresult: \"[Add: what changed for the trust]\"\n</brief>"]],
            ],
            'brief-try-again' => [
                'job' => 'fillBrief',
                'inputs' => [BriefRequest::fromIdea($kind, 'Kiln opening', 'Nothing on the kiln yet.', ['Harbour Trust'], [$one])->tryAgain(
                    new Brief('Kiln opening', ['client' => 'The trust', 'scope' => 'site', 'result' => 'More visitors.'], [$one]),
                    ['client' => 'The Harbour Trust'],
                )],
                'replies' => ['brief-filler' => ["<title>Kiln opening</title>\n<brief>\nclient: Someone else\nscope: app\nresult: \"[Add: what changed]\"\n</brief>"]],
            ],
            'write-first' => [
                'job' => 'write',
                'inputs' => [new Conversation([['role' => 'user', 'content' => 'Here is the brief for a new entry: Project write-up.']]), $writer],
                'replies' => ['writer' => ["<reply>A first go.</reply>\n<draft>\ntitle: Kiln\n</draft>"]],
            ],
            'write-turn' => [
                'job' => 'write',
                'inputs' => [new Conversation([
                    ['role' => 'user', 'content' => 'Here is the brief.'],
                    ['role' => 'assistant', 'content' => 'A first go.'],
                    ['role' => 'user', 'content' => 'Shorter, and name the kiln.'],
                ], "title: Kiln\nbody: |\n  Long."), new WriterContext($kind, '', new Layout($layout->fields), $words['images'])],
                'replies' => ['writer' => ["<reply>Shorter.</reply>\n<draft>\ntitle: Kiln\n</draft>"]],
            ],
            'photo-query' => [
                'job' => 'photoQuery',
                'inputs' => ['Kiln opening', 'A new kiln for the pottery.'],
                'replies' => ['photo-query' => ["Pottery kiln\n"]],
            ],
        ];

        foreach ($cases as $name => $case) {
            $job = $case['job'];
            $inputs = $case['inputs'];
            $cases[$name]['run'] = fn (Studio $studio) => $studio->{$job}(...$inputs);
        }

        return $cases;
    }

    /**
     * Inputs as plain data for the fixture: objects as their public
     * properties under their class's short name, images as type and bytes.
     */
    public static function export(mixed $value): mixed
    {
        if ($value instanceof Image) {
            return ['@' => 'Image', 'mime' => $value->mime, 'base64' => base64_encode($value->data)];
        }

        if (is_object($value)) {
            $vars = get_object_vars($value);

            // A Layout made from described text, as these cases are, has no schema (core 0.4).
            if ($value instanceof Layout && $value->schema === null) {
                unset($vars['schema']);
            }

            // Nor does a sample say where it came from (core 1.1, for the model-input guard).
            if ($value instanceof ImagerySample) {
                $vars = array_filter($vars, fn ($var, string $key) => $var !== null || ! in_array($key, ['asset', 'filename'], true), ARRAY_FILTER_USE_BOTH);
            }

            return ['@' => (new \ReflectionClass($value))->getShortName()] + array_map(self::export(...), $vars);
        }

        return is_array($value) ? array_map(self::export(...), $value) : $value;
    }
}
