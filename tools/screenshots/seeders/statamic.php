<?php

/**
 * Seeds the Statamic test site with every state the docs shots need, without
 * a model call: guides, kinds, the content plan, sessions, image requests,
 * a second user and the dashboard widget. Run inside the site's root by
 * StatamicSite::seed(); prints what it made as JSON on its last line.
 *
 *   php seeders/statamic.php <signed-in email>
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Ai\Studio;
use NineteenNinetyFour\Ghostwriter\Blueprints\SchemaReader;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Drafts\FormBaseline;
use NineteenNinetyFour\Ghostwriter\Types\TypeRepository;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Symfony\Component\Yaml\Yaml;

$root = getcwd();

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$email = $argv[1] ?? '';
$here = __DIR__.'/statamic';
$storage = storage_path('ghostwriter');
$resources = resource_path('ghostwriter');

$me = User::findByEmail($email) ?? throw new RuntimeException("No user {$email} on this site.");
$meId = (string) $me->id();

// A clean slate: the run keeps a copy of what was here and puts it back.
File::deleteDirectory($storage);
File::deleteDirectory($resources);
File::ensureDirectoryExists($storage.'/sessions');
File::ensureDirectoryExists($storage.'/images/files');
File::ensureDirectoryExists($resources.'/types');

$json = fn (string $path, array $data) => File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$at = fn (string $ago) => Carbon::now()->sub($ago)->toIso8601String();
$entry = fn (string $collection, string $slug) => Entry::query()->where('collection', $collection)->where('slug', $slug)->first();
$id = fn (string $collection, string $slug) => (string) $entry($collection, $slug)?->id();
$words = fn (string $draft) => Draft::parse($draft)->wordCount();

// .env: the model provider fixed in config (so the settings screen shows a
// locked field), a placeholder image key so "Make one" is offered, and Pro
// switched on, which a second user needs.
$env = (string) File::get($root.'/.env');

foreach (['GHOSTWRITER_PROVIDER' => 'anthropic', 'OPENAI_API_KEY' => 'sk-test-screenshots-placeholder', 'STATAMIC_PRO_ENABLED' => 'true'] as $key => $value) {
    $env = preg_match("/^{$key}=.*$/m", $env)
        ? preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $env)
        : rtrim($env)."\n{$key}={$value}\n";
}

File::put($root.'/.env', $env);

// The second person in the shared conversation, and the widget on the
// signed-in person's dashboard.
$maya = User::findByEmail('maya@northfold.test') ?? User::make()->email('maya@northfold.test');
$maya->set('name', 'Maya Lindqvist')->makeSuper()->save();
$mayaId = (string) $maya->id();

$me->setPreference('widgets', [['type' => 'ghostwriter', 'limit' => 5, 'width' => 100]])->save();

// Guides.
File::put($resources.'/voice.md', File::get($here.'/voice.md'));
File::put($resources.'/imagery.md', File::get($here.'/imagery.md'));

// Written a couple of days ago, as the screens say.
touch($resources.'/voice.md', Carbon::now()->subDays(2)->timestamp);
touch($resources.'/imagery.md', Carbon::now()->subDays(2)->timestamp);

$scanned = Entry::query()->where('published', true)->get()
    ->filter(fn ($item) => in_array($item->collectionHandle(), ['journal', 'pages'], true))
    ->map(fn ($item) => ['title' => (string) $item->get('title'), 'collection' => $item->collectionHandle()])
    ->values()->all();

$json($storage.'/voice.json', ['status' => 'idle', 'error' => null, 'task' => null, 'messages' => [], 'scanned' => $scanned, 'pending' => []]);
$json($storage.'/imagery.json', ['status' => 'idle', 'error' => null, 'task' => null, 'messages' => [], 'scanned' => $scanned, 'pending' => []]);

// A learned kind.
$kind = Yaml::parse((string) File::get($here.'/project-story.yaml'));
$kind['examples'] = array_values(array_filter(array_map(fn (string $slug) => $id('journal', $slug), $kind['examples'])));
File::put($resources.'/types/project-story.yaml', Yaml::dump($kind, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

// Suggested kinds, checked just now so nothing is due to be checked again.
$published = fn (string $collection) => Entry::query()->where('collection', $collection)->where('published', true)->count();
$suggestion = fn (string $title, string $description, string $why, string $collection, array $slugs) => [
    'id' => Str::lower(Str::random(12)),
    'title' => $title,
    'description' => $description,
    'why' => $why,
    'examples' => array_values(array_filter(array_map(fn (string $slug) => $id($collection, $slug), $slugs))),
    'blueprint' => $collection,
];

$json($storage.'/kinds.json', [
    'journal' => [
        'status' => 'idle',
        'error' => null,
        'checked_at' => $at('2 hours'),
        'entries' => $published('journal'),
        'suggestions' => [
            $suggestion(
                'Seasonal advice',
                'A practical list of the jobs worth doing in the garden at one time of year, in the order we would do them, each with a line on why it matters now.',
                'Three entries are built around one month or season, open on a familiar moment of the gardening year, and run through numbered jobs under short headings.',
                'journal',
                ['what-to-do-in-the-garden-in-october', 'ten-jobs-for-the-first-warm-weekend', 'why-we-leave-the-seedheads-standing'],
            ),
            $suggestion(
                'Studio news',
                'A short, friendly update from the studio itself: a move, a new member of the team, or how we work, told in the first person plural.',
                'Three entries are about Northfold rather than a garden: they are shorter, open on something that has changed at the studio, and end with an invitation to visit or get in touch.',
                'journal',
                ['weve-moved-into-the-old-dairy', 'priya-joins-the-studio', 'how-a-planting-plan-comes-together'],
            ),
        ],
        'dismissed' => [],
    ],
    'pages' => [
        'status' => 'idle',
        'error' => null,
        'checked_at' => $at('2 hours'),
        'entries' => $published('pages'),
        'suggestions' => [
            $suggestion(
                'Service page',
                'A page about one thing the studio offers: what it is, who it suits and what happens, opening on a hero with a button and closing on a call to action.',
                'Services, Approach and Contact all explain one part of working with the studio with the same blocks in the same order: hero, text, image, quote, text, call to action.',
                'pages',
                ['services', 'approach', 'contact'],
            ),
        ],
        'dismissed' => [],
    ],
]);

// Sessions.
$studio = app(Studio::class);
$types = app(TypeRepository::class);
$session = function (array $data) use ($storage, $json, $studio, $types): string {
    $data += [
        'id' => (string) Str::ulid(),
        'answers' => [],
        'draft' => null,
        'status' => 'idle',
        'error' => null,
        'entry_id' => null,
        'usage' => ['input' => 0, 'output' => 0],
        'examples' => [],
        'images' => [],
        'source' => null,
        'blueprint' => null,
        'applied_at' => null,
        'touched_by' => null,
        'run_by' => null,
    ];

    // The brief is the first message, written as the panel writes it.
    if (($data['messages'][0]['content'] ?? null) === null && $data['source'] === null) {
        $data['messages'][0]['content'] = $studio->brief($types->find($data['type']), Session::fromArray($data, Format::Statamic));
    }

    $data['run_by'] ??= $data['messages'][0]['by'] ?? null;

    $json($storage.'/sessions/'.$data['id'].'.json', [
        'id' => $data['id'],
        'type' => $data['type'],
        'answers' => $data['answers'],
        'messages' => $data['messages'],
        'draft' => $data['draft'],
        'status' => $data['status'],
        'error' => $data['error'],
        'entry_id' => $data['entry_id'],
        'user_id' => $data['user_id'],
        'usage' => $data['usage'],
        'examples' => $data['examples'],
        'images' => $data['images'],
        'source' => $data['source'],
        'blueprint' => $data['blueprint'],
        'applied_at' => $data['applied_at'],
        'touched_by' => $data['touched_by'],
        'run_by' => $data['run_by'],
        'created_at' => $data['created_at'],
        'updated_at' => $data['updated_at'],
    ]);

    return $data['id'];
};

// Questions: Ghostwriter asks two things before it drafts.
$questions = $session([
    'type' => 'any:journal',
    'user_id' => $meId,
    'answers' => [
        'subject' => 'A wildlife pond for a small garden',
        'reader' => 'Garden owners with a small back garden in the north who like the idea of a pond but think they have no room. They should come away ready to dig one this autumn.',
        'points' => 'Even a tiny pond is worth it, and can be dug, lined and planted in an afternoon. Autumn is a good time to dig. Shallow sloping sides so frogs and hedgehogs can get in and out. Fill with rainwater, not tap water. No fish. Native plants only, a few to start with.',
        'shape' => 'About 800 words, with short headings.',
        'must_not_appear' => 'Prices, product names.',
    ],
    'messages' => [
        ['role' => 'user', 'content' => null, 'at' => $at('25 minutes'), 'by' => $meId],
        [
            'role' => 'assistant',
            'content' => "Before I draft, two things I can't work out from the brief:\n\n1. **Which plants?** You've said native plants only, a few to start with. Which ones do you suggest to clients for a small pond in the north? I'd rather name the ones you'd actually use than guess.\n2. **One of your own?** Is there a pond you've made, in a client's garden or at the studio, that I can use as the opening scene? A single detail is enough, such as when it went in and what turned up first.",
            'at' => $at('24 minutes'),
            'asks' => true,
        ],
    ],
    'created_at' => $at('25 minutes'),
    'updated_at' => $at('24 minutes'),
]);

// A draft on a page with a page builder, with a photo chosen for its hero.
$winter = (string) File::get($here.'/winter-garden-care.yaml');
$photo = fn (string $file, string $term, string $credit, array $extra = []) => [
    'source' => 'openverse',
    'id' => (string) Str::uuid(),
    'thumb' => '/assets/'.$file,
    'credit' => $credit.' via Openverse',
    'credit_url' => null,
    'licence' => 'CC0',
    'title' => Str::headline(pathinfo($file, PATHINFO_FILENAME)),
    'width' => 1600,
    'height' => 1000,
    'url' => '/assets/'.$file,
    'term' => $term,
] + $extra;

$heroOptions = [
    $photo('pages/hero-evening.jpg', 'winter hills at dusk', 'Fell and Field', ['picked' => true, 'reason' => 'Layered hills under a low winter sun, the same soft gradients and muted greens as the other page heroes.']),
    $photo('journal/winter-structure.jpg', 'frosted seedheads border', 'Hedgerow Prints', ['picked' => true, 'reason' => 'Seedheads standing in a quiet border, which suits the page, in the site\'s earthy palette.']),
    $photo('journal/downland-morning.jpg', 'misty downland morning', 'Low Sun Studio'),
    $photo('pages/hero-landscape.jpg', 'winter hills at dusk', 'Fell and Field'),
    $photo('journal/walled-garden-dusk.jpg', 'walled garden in winter', 'Hedgerow Prints'),
    $photo('journal/autumn-borders.jpg', 'frosted seedheads border', 'Low Sun Studio'),
];

$draft = $session([
    'type' => 'any:pages',
    'user_id' => $meId,
    'answers' => [
        'subject' => 'Winter garden care: a new service of four visits between November and February to prune, mulch and protect, while leaving seedheads standing.',
        'reader' => 'Owners of gardens we have designed, and of established gardens in Northumberland and the Tyne Valley. They should book a winter visit.',
        'points' => 'Four visits, November to February. Pruning fruit trees and wisteria, mulching, checking stakes and young trees after gales, dividing perennials. We leave seedheads and hollow stems for wildlife. A short note after each visit.',
        'shape' => 'Like the Services page: hero, text, image, quote, text, call to action.',
        'must_not_appear' => 'Prices.',
    ],
    'messages' => [
        ['role' => 'user', 'content' => null, 'at' => $at('3 hours'), 'by' => $meId],
        [
            'role' => 'assistant',
            'content' => "A couple of questions first:\n\n1. Is there a client quote I can use for the quote block, or should I leave it out?\n2. Which areas do you cover for winter visits?",
            'at' => $at('3 hours'),
            'asks' => true,
        ],
        ['role' => 'user', 'content' => "1. Yes: \"We used to clear everything in October. Now the garden has something to look at all winter, and the spring comes up stronger.\" Client, Tyne Valley.\n2. Northumberland, Durham and the Tyne Valley.", 'at' => $at('2 hours'), 'by' => $meId],
        [
            'role' => 'assistant',
            'content' => "I've written it in the same shape as the Services page: a hero with a booking button, what winter care is for, the jobs on each visit, an image, your client's quote, who it suits, and a call to action that's honest about gardens that don't need it.\n\nThe image block has a caption but no picture yet; there's one chosen for the hero below.",
            'at' => $at('2 hours'),
            'asks' => false,
            'draft' => ['change' => 'written', 'words' => $words($winter), 'was' => null],
        ],
    ],
    'draft' => trim($winter),
    'usage' => ['input' => 18420, 'output' => 2216],
    'images' => [
        'page_builder:hero:0:image' => [
            'status' => 'done',
            'path' => 'pages/hero-evening.jpg',
            'url' => '/assets/pages/hero-evening.jpg',
            'error' => null,
            'credit' => 'Fell and Field via Openverse',
            'query' => 'winter hills at dusk; frosted seedheads border; misty downland morning',
            'options' => $heroOptions,
            'judged' => true,
            'none_fit' => false,
            'with_references' => true,
        ],
    ],
    'touched_by' => $meId,
    'created_at' => $at('3 hours'),
    'updated_at' => $at('2 hours'),
]);

// Shared: started by Maya, carried on by the signed-in person.
$trial = <<<'YAML'
title: 'A trial garden behind the old dairy'
excerpt: 'Before a plant goes into anyone else''s garden, it spends a year or two in ours. Here''s what we''re growing out the back, and why.'
category: studio
body: |-
  Behind the old dairy there's a strip of ground that used to be a muck heap. Since March it's been our trial garden.

  ## Why we test plants first

  A plant that does well in a nursery in the south can sulk for years in a cold, wet Northumberland garden. We'd rather find that out on our own ground than in a client's border.

  ## What's in it this year

  - Grasses for exposed gardens, including Deschampsia cespitosa 'Goldtau'
  - Three hardy salvias we've never trusted this far north
  - A row of hedging, cut back hard to see which recovers fastest

  ## Come and see it

  We open the gate on the first Saturday of each month, from ten until one. Bring wellies.
YAML;

$trialFirst = <<<'YAML'
title: 'A trial garden behind the old dairy'
excerpt: 'Before a plant goes into anyone else''s garden, it spends a year or two in ours.'
category: studio
body: |-
  Behind the old dairy there's a strip of ground that used to be a muck heap. Since March it's been our trial garden.

  ## Why we test plants first

  A plant that does well in a nursery in the south can sulk for years in a cold, wet Northumberland garden. We'd rather find that out on our own ground than in a client's border.

  ## What's in it this year

  Grasses for exposed gardens, three hardy salvias and a row of hedging.
YAML;

$shared = $session([
    'type' => 'any:journal',
    'user_id' => $mayaId,
    'answers' => [
        'subject' => 'Our new trial garden behind the old dairy: what it is, why we test plants before using them in clients\' gardens, and what is in it this year.',
        'reader' => 'Clients and neighbours. They should feel welcome to come and look round on an open morning.',
        'points' => 'Started in March on the old muck heap. Grasses for exposed gardens, hardy salvias, a hedging trial.',
        'shape' => 'Short, like the other studio news.',
        'must_not_appear' => '',
    ],
    'messages' => [
        ['role' => 'user', 'content' => null, 'at' => $at('1 day'), 'by' => $mayaId],
        ['role' => 'assistant', 'content' => 'Is the trial garden open to visitors? If so, when, and should the piece invite people along?', 'at' => $at('1 day'), 'asks' => true],
        ['role' => 'user', 'content' => 'Yes. First Saturday of each month, ten until one.', 'at' => $at('23 hours'), 'by' => $mayaId],
        ['role' => 'assistant', 'content' => "I've written a short studio piece that opens on the old muck heap, explains why we test plants first, and lists what's in the ground this year.", 'at' => $at('23 hours'), 'asks' => false, 'draft' => ['change' => 'written', 'words' => $words($trialFirst), 'was' => null]],
        ['role' => 'user', 'content' => 'Name one of the grasses, make the list a bulleted list, and end with the open mornings. "Bring wellies" as the last line, please.', 'at' => $at('40 minutes'), 'by' => $meId],
        ['role' => 'assistant', 'content' => "Done. I've named Deschampsia cespitosa 'Goldtau', turned the list into bullets and added a closing section on the open mornings, ending on \"Bring wellies.\"", 'at' => $at('39 minutes'), 'asks' => false, 'draft' => ['change' => 'updated', 'words' => $words($trial), 'was' => $words($trialFirst)]],
    ],
    'draft' => $trial,
    'usage' => ['input' => 22105, 'output' => 1830],
    'touched_by' => $meId,
    'run_by' => $meId,
    'created_at' => $at('1 day'),
    'updated_at' => $at('39 minutes'),
]);

// Failed: the provider was busy.
$failed = $session([
    'type' => 'any:journal',
    'user_id' => $meId,
    'answers' => [
        'subject' => 'Hedges for exposed gardens',
        'reader' => 'People with a windswept garden who want shelter without a fence. They should know what to plant this winter.',
        'points' => 'Which hedges stand up to wind and salt on the coast and the high ground inland. Hawthorn, blackthorn, sea buckthorn, Griselinia on the coast. Plant bare-root between November and March. Windbreak netting for the first two years.',
        'shape' => '',
        'must_not_appear' => 'Leylandii recommendations.',
    ],
    'messages' => [
        ['role' => 'user', 'content' => null, 'at' => $at('5 hours'), 'by' => $meId],
        ['role' => 'assistant', 'content' => 'Should I mention our work at Craster as the example of a coastal hedge, or keep it general?', 'at' => $at('5 hours'), 'asks' => true],
        ['role' => 'user', 'content' => 'Yes, use Craster. The Griselinia and sea buckthorn went in there in 2023.', 'at' => $at('4 hours'), 'by' => $meId],
    ],
    'status' => 'failed',
    'error' => 'The provider is busy right now. Try again in a minute.',
    'run_by' => $meId,
    'created_at' => $at('5 hours'),
    'updated_at' => $at('4 hours'),
]);

// Editing: the About page, with a revised draft.
$about = $entry('pages', 'about');
$aboutDraft = trim(Yaml::dump(
    ['title' => (string) $about->get('title')] + app(EntrySimplifier::class)->simplify(app(FormBaseline::class)->data($about, null), app(SchemaReader::class)->read($about->blueprint())),
    20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
));
// This page's lists hold their text straight in each item, which the draft
// leaves out; put the words back so the draft reads as the page does.
$bullets = [];
$collect = function (array $node, bool $inList) use (&$collect, &$bullets): void {
    $inList = $inList || ($node['type'] ?? null) === 'bulletList';

    if ($inList && ($node['type'] ?? null) === 'text' && is_string($node['text'] ?? null)) {
        $bullets[] = $node['text'];
    }

    foreach ($node as $child) {
        if (is_array($child)) {
            $collect($child, $inList);
        }
    }
};
$collect((array) $about->get('page_builder'), false);
$aboutDraft = preg_replace_callback('/^(\s*)- $/m', function (array $m) use (&$bullets) {
    return $m[1].'- '.(array_shift($bullets) ?? '');
}, $aboutDraft);

$revised = str_replace(
    ['We take on around twenty new gardens a year. Get in touch early if you have a season in mind.', "'Contact the studio'"],
    ["We take on around twenty new gardens a year, and spring 2027 is already filling up. If you have a season in mind, get in touch early and we'll come and see the garden.", "'Book a first visit'"],
    $aboutDraft,
);

$editing = $session([
    'type' => 'any:pages',
    'user_id' => $meId,
    'source' => (string) $about->id(),
    'blueprint' => $about->blueprint()->handle(),
    'messages' => [
        ['role' => 'user', 'content' => 'This entry already exists on the site. Its content as it stands is the current draft. I will ask for changes to it.', 'at' => $at('15 minutes'), 'by' => $meId],
        ['role' => 'assistant', 'content' => 'I have the entry as it stands. Tell me what to change.', 'at' => $at('15 minutes'), 'editing' => true],
        ['role' => 'user', 'content' => 'Make the closing call to action warmer, and say that spring 2027 is already filling up. The button should say "Book a first visit".', 'at' => $at('12 minutes'), 'by' => $meId],
        ['role' => 'assistant', 'content' => "I've rewritten the last block: it now says spring 2027 is filling up and offers to come and see the garden, and the button reads \"Book a first visit\". Nothing else on the page has changed.", 'at' => $at('11 minutes'), 'asks' => false, 'draft' => ['change' => 'updated', 'words' => $words($revised), 'was' => $words($aboutDraft)]],
    ],
    'draft' => $revised,
    'usage' => ['input' => 9620, 'output' => 1104],
    'touched_by' => $meId,
    'created_at' => $at('15 minutes'),
    'updated_at' => $at('11 minutes'),
]);

// The content plan.
$idea = fn (string $title, string $collection, string $why, string $notes, string $status = 'open', string $source = 'suggested', ?string $session = null, ?string $type = null) => [
    'id' => (string) Str::ulid(),
    'title' => $title,
    'collection' => $collection,
    'type' => $type,
    'why' => $why,
    'notes' => $notes,
    'status' => $status,
    'source' => $source,
    'session' => $session,
    'created_at' => Carbon::now()->subDays(2)->toDateString(),
];

$ideas = [
    $idea('What to plant under a big tree', 'journal', 'Several case studies mention dry shade under mature trees, but nothing tells a reader what will actually grow there.', 'Open on the bare patch under a sycamore that every client seems to have. Name the plants we use in dry shade, and be straight about what won\'t work.'),
    $idea('Making a garden on heavy clay', 'journal', 'Clay comes up in the Corbridge and Hebden Bridge stories, but there is no piece on working with it.', 'Practical and encouraging: grit, timing, and the plants that like it. [Need: the studio\'s own clay-soil plant list.]'),
    $idea('A year in the trial garden', 'journal', 'Readers asked how the trial beds did after the first winter.', 'A look back at the first year: what thrived, what sulked, and what has made it into client gardens.', 'open', 'added'),
    $idea('Questions we\'re often asked', 'pages', 'The Contact page invites questions, but there is no page answering the common ones before people get in touch.', 'Short answers in the studio\'s voice: how long a design takes, whether we build, how far we travel. No prices.'),
    $idea('Winter garden care', 'pages', 'The Services page stops at design and build, and nothing offers help once a garden is planted.', 'A page for the new winter visits: what is done, who it suits, and how to book.', 'drafted', 'suggested', $draft),
    $idea('Our favourite garden centres', 'journal', 'Readers often ask where to buy plants locally.', 'A short round-up of nurseries in the north east.', 'dismissed'),
];

File::put($resources.'/ideas.yaml', Yaml::dump(['ideas' => $ideas], 4, 2));

$json($storage.'/plan.json', [
    'status' => 'idle',
    'error' => null,
    'task' => null,
    'messages' => [],
    'scanned' => $scanned,
    'pending' => [
        ['title' => 'Choosing paving that weathers well', 'collection' => 'journal', 'type' => null, 'why' => 'Materials come up in every project story, but no piece helps a reader choose them.', 'notes' => 'Local stone, gravel and reclaimed brick: how each looks after five northern winters.'],
        ['title' => 'Planting for a north-facing garden', 'collection' => 'journal', 'type' => null, 'why' => 'Shade is the most common problem in the case studies, and the journal has no piece on it.', 'notes' => 'Open on the client who thought nothing would grow. Name the plants we rely on.'],
        ['title' => 'Working with us from a distance', 'collection' => 'pages', 'type' => null, 'why' => 'The site mentions gardens in Cumbria and Yorkshire, but no page explains how the studio works further afield.', 'notes' => 'Site visits, video calls and how planting days are arranged.'],
    ],
]);

// The image button's requests on the tulips entry (or another journal
// entry), both made by the signed-in person.
$target = $entry('journal', 'planting-tulips-and-alliums-in-october') ?? $entry('journal', 'what-to-do-in-the-garden-in-october');
$slot = ['journal', null, 'hero_image', null, (string) $target->id(), (string) $target->get('title'), '', (string) $target->get('excerpt')];

$found = [
    $photo('journal/autumn-borders.jpg', 'allium seedheads in a border', 'Low Sun Studio', ['picked' => true, 'reason' => 'A warm autumn landscape in flat, layered shapes, the closest match to the hero images on the other journal entries.', 'alt' => 'Layered hills under a low autumn sun']),
    $photo('journal/meadow-summer.jpg', 'tulip bulbs in terracotta pots', 'Hedgerow Prints', ['picked' => true, 'reason' => 'Soft greens and a low horizon, in the same simple style as the journal\'s images.', 'alt' => 'Green hills in soft light']),
    $photo('journal/orchard-spring.jpg', 'allium seedheads in a border', 'Fell and Field', ['alt' => 'Rolling hills in spring']),
    $photo('journal/downland-morning.jpg', 'tulip bulbs in terracotta pots', 'Low Sun Studio', ['alt' => 'Downland on a misty morning']),
    $photo('journal/coastal-garden.jpg', 'allium seedheads in a border', 'Hedgerow Prints', ['alt' => 'Hills by the coast']),
    $photo('journal/walled-garden-dusk.jpg', 'tulip bulbs in terracotta pots', 'Fell and Field', ['alt' => 'Hills at dusk']),
];

$findId = (string) Str::ulid();
$json($storage.'/images/'.$findId.'.json', [
    'id' => $findId,
    'status' => 'done',
    'error' => null,
    'created_at' => $at('3 minutes'),
    'user' => $meId,
    'slot' => $slot,
    'mode' => 'find',
    'terms' => ['allium seedheads in a border', 'tulip bulbs in terracotta pots'],
    'options' => $found,
    'judged' => true,
    'none_fit' => false,
    'with_references' => true,
]);

$makeId = (string) Str::ulid();
$made = $storage.'/images/files/'.$makeId.'.jpg';
File::copy(public_path('assets/journal/winter-structure.jpg'), $made);
$json($storage.'/images/'.$makeId.'.json', [
    'id' => $makeId,
    'status' => 'done',
    'error' => null,
    'created_at' => $at('2 minutes'),
    'user' => $meId,
    'slot' => $slot,
    'mode' => 'make',
    'direction' => 'An October border at dusk, with allium seedheads against low hills',
    'file' => $made,
    'mime' => 'image/jpeg',
]);

$create = fn (string $collection) => parse_url(Collection::findByHandle($collection)->createEntryUrl(), PHP_URL_PATH);
$edit = fn ($item) => parse_url($item->editUrl(), PHP_URL_PATH);

echo json_encode([
    'me' => $meId,
    'maya' => $mayaId,
    'sessions' => compact('questions', 'draft', 'shared', 'failed', 'editing'),
    'images' => ['find' => $findId, 'make' => $makeId],
    'create' => ['journal' => $create('journal'), 'pages' => $create('pages')],
    'about' => $edit($about),
    'image_entry' => $edit($target),
]), "\n";
