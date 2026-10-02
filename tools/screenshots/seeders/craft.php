<?php

/**
 * Seeds the Craft test site for the docs screenshots, run from the site's
 * root by CraftSite::seed(). Everything is written straight into the
 * database (and a placeholder key into .env); nothing is queued and no
 * model is called. The runner's State puts it all back afterwards.
 */

use craft\base\Element;
use craft\console\Application;
use craft\db\Query;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\db\Expression;

$root = getcwd();

require $root.'/bootstrap.php';

// The image button only offers "Make one" with an image model's key. A
// placeholder is enough to show the tab; nothing is ever sent with it.
$env = (string) file_get_contents($root.'/.env');
$env = preg_match('/^OPENAI_API_KEY=.*$/m', $env)
    ? (string) preg_replace('/^OPENAI_API_KEY=.*$/m', 'OPENAI_API_KEY=sk-test-screenshots-placeholder', $env)
    : rtrim($env)."\nOPENAI_API_KEY=sk-test-screenshots-placeholder\n";
file_put_contents($root.'/.env', $env);
putenv('OPENAI_API_KEY=sk-test-screenshots-placeholder');
$_ENV['OPENAI_API_KEY'] = $_SERVER['OPENAI_API_KEY'] = 'sk-test-screenshots-placeholder';

/** @var Application $app */
$app = require CRAFT_VENDOR_PATH.'/craftcms/cms/bootstrap/console.php';

$fixtures = __DIR__.'/craft';
$plugin = Plugin::getInstance();
$db = Craft::$app->getDb();
$me = 1;

$ago = fn (string $when) => new DateTime($when);
$atom = fn (string $when) => (new DateTime($when))->format(DATE_ATOM);
$stamp = fn (string $when) => Db::prepareDateForDb(new DateTime($when));
$id = fn (int $bytes) => bin2hex(random_bytes($bytes));

/*
 * New, unsaved entries for the writing panel to open on, as "New entry"
 * makes them.
 */
$newEntry = function (string $sectionHandle, array $title = []) use ($me): Entry {
    $section = Craft::$app->getEntries()->getSectionByHandle($sectionHandle);
    $entry = new Entry;
    $entry->sectionId = $section->id;
    $entry->typeId = $section->getEntryTypes()[0]->id;
    $entry->siteId = 1;
    $entry->setAuthorId($me);
    $entry->slug = ElementHelper::tempSlug();
    $entry->title = $title['title'] ?? null;

    if (isset($title['fields'])) {
        $entry->setFieldValues($title['fields']);
    }

    $entry->setScenario(Element::SCENARIO_ESSENTIALS);

    if (! Craft::$app->getDrafts()->saveElementAsDraft($entry, $me, null, null, false)) {
        throw new RuntimeException("Couldn't make a new {$sectionHandle} entry: ".implode(' ', $entry->getFirstErrors()));
    }

    return $entry;
};

$cpPath = function (Entry $entry): string {
    $url = (string) $entry->getCpEditUrl();
    $trigger = Craft::$app->getConfig()->getGeneral()->cpTrigger ?: 'admin';

    return '/'.$trigger.'/'.ltrim(substr($url, (int) strpos($url, '/'.$trigger.'/') + strlen($trigger) + 2), '/');
};

$with = fn (string $path, string $session) => $path.(str_contains($path, '?') ? '&' : '?').'ghostwriter='.$session;

// Called again just before "Use this draft": a copy of the page draft on a
// second new page, made late so it never shows in the lists of pieces.
if (($argv[2] ?? null) === 'used') {
    $source = (new Query)->from('{{%ghostwriter_sessions}}')->where(['id' => $argv[3] ?? ''])->one() ?: throw new RuntimeException('No draft session to copy.');
    $entry = $newEntry('pages');
    $data = ['id' => $id(13), 'element_id' => (int) $entry->id] + Json::decode($source['data']);
    $db->createCommand()->insert('{{%ghostwriter_sessions}}', ['id' => $data['id'], 'userId' => $me, 'elementId' => $data['element_id'], 'data' => Json::encode($data), 'dateCreated' => $source['dateCreated'], 'dateUpdated' => $source['dateUpdated'], 'uid' => StringHelper::UUID()])->execute();

    echo Json::encode(['used' => $with($cpPath($entry), $data['id'])])."\n";

    exit(0);
}

// Ghostwriter's own tables start empty, so only what is seeded shows.
foreach (['{{%ghostwriter_documents}}', '{{%ghostwriter_state}}', '{{%ghostwriter_sessions}}', '{{%ghostwriter_files}}'] as $table) {
    $db->createCommand()->delete($table)->execute();
}

/*
 * Maya Lindqvist, who started the shared conversation. Craft Solo refuses a
 * second user through its own API, so she is written in directly; the
 * database restore takes her out again.
 */
$now = Db::prepareDateForDb(new DateTime);
$db->createCommand()->insert('{{%elements}}', ['type' => 'craft\\elements\\User', 'enabled' => true, 'archived' => false, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID()])->execute();
$maya = (int) $db->getLastInsertID();
$db->createCommand()->insert('{{%elements_sites}}', ['elementId' => $maya, 'siteId' => 1, 'enabled' => true, 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID()])->execute();
$db->createCommand()->insert('{{%users}}', [
    'id' => $maya, 'active' => true, 'pending' => false, 'locked' => false, 'suspended' => false, 'admin' => false,
    'username' => 'maya', 'fullName' => 'Maya Lindqvist', 'firstName' => 'Maya', 'lastName' => 'Lindqvist', 'email' => 'maya@northfold.test',
    'hasDashboard' => false, 'passwordResetRequired' => false, 'dateCreated' => $now, 'dateUpdated' => $now,
])->execute();

$blank = $newEntry('journal');
$asking = $newEntry('journal');
$sharedEntry = $newEntry('journal');
$failedEntry = $newEntry('journal');
$pictured = $newEntry('journal', ['title' => 'Planting tulips and alliums in October', 'fields' => [
    'excerpt' => 'October is the month for bulbs. Here is how we plant tulips and alliums so they come back, and what to do with them once they have flowered.',
    'category' => 'seasonal',
]]);
$pageEntry = $newEntry('pages');

/*
 * Guides, the learned kind and the content plan.
 */
$document = function (string $kind, string $handle, string $body, string $when) use ($db, $stamp): void {
    $db->createCommand()->insert('{{%ghostwriter_documents}}', ['kind' => $kind, 'handle' => $handle, 'body' => $body, 'dateCreated' => $stamp($when), 'dateUpdated' => $stamp($when), 'uid' => StringHelper::UUID()])->execute();
};

$state = function (string $name, array $value, string $when = 'now') use ($db, $stamp): void {
    $db->createCommand()->insert('{{%ghostwriter_state}}', ['name' => $name, 'value' => Json::encode($value), 'dateCreated' => $stamp($when), 'dateUpdated' => $stamp($when), 'uid' => StringHelper::UUID()])->execute();
};

$document('guide', 'voice', trim((string) file_get_contents($fixtures.'/voice.md')), '-3 days');
$document('guide', 'imagery', trim((string) file_get_contents($fixtures.'/imagery.md')), '-2 days');
$document('type', 'project-story', (string) file_get_contents($fixtures.'/project-story.yaml'), '-2 days');

$live = fn (string $section) => (int) Entry::find()->section($section)->status('live')->count();
$scanned = fn (array $ids) => array_map(fn (Entry $entry) => ['title' => (string) $entry->title, 'section' => $entry->getSection()->handle], Entry::find()->id($ids)->status(null)->fixedOrder()->all());

$state('voice', [
    'status' => 'idle', 'error' => null, 'task' => null,
    'messages' => [
        ['role' => 'user', 'content' => 'We never use exclamation marks. Add that.'],
        ['role' => 'assistant', 'content' => 'Done. "Exclamation marks" is now the first thing under "Never".'],
    ],
    'scanned' => $scanned([17, 19, 21, 23, 25, 27, 29, 31, 33, 35, 37, 39, 53, 67, 81, 41, 95]),
    'pending' => [],
], '-3 days');
$state('imagery', ['status' => 'idle', 'error' => null, 'task' => null, 'messages' => [], 'scanned' => $scanned([17, 19, 21, 25, 27, 29, 31, 33, 35, 53, 67, 81]), 'pending' => []], '-2 days');

// Every section looked at just now, with as many live entries as it has,
// so nothing is due another look and Get started asks the model nothing.
$state('kinds', [
    'journal' => [
        'status' => 'idle', 'error' => null, 'checkedAt' => $atom('-1 day'), 'entries' => $live('journal'),
        'suggestions' => [
            [
                'id' => 'a1c3e5f7b9d2', 'title' => 'Seasonal advice', 'entryType' => 'article', 'examples' => [19, 37, 29],
                'description' => 'A practical piece on what to do in the garden at one time of year, in the order we would do it.',
                'why' => 'Three entries are built around a season and work through a list of jobs, each with a short reason and a note of what can be left.',
            ],
            [
                'id' => 'b2d4f6a8c1e3', 'title' => 'Studio news', 'entryType' => 'article', 'examples' => [39, 23, 31],
                'description' => 'A short, first-person update about Northfold itself: an anniversary, someone joining, a first show garden.',
                'why' => 'Three entries are about the studio rather than a client\'s garden or a technique, and share a warm, brief shape.',
            ],
        ],
        'dismissed' => [],
    ],
    'pages' => [
        'status' => 'idle', 'error' => null, 'checkedAt' => $atom('-1 day'), 'entries' => $live('pages'),
        'suggestions' => [
            [
                'id' => 'c3e5a7b9d1f2', 'title' => 'Service page', 'entryType' => 'page', 'examples' => [67, 81, 53],
                'description' => 'A page about one thing the studio offers: a welcome line, what it involves, a testimonial and a prompt to get in touch.',
                'why' => 'Services, Approach and About share the same blocks in the same order, and each ends by pointing the reader to the Contact page.',
            ],
        ],
        'dismissed' => [],
    ],
], '-1 day');
$state('types', ['journal' => ['status' => 'idle', 'error' => null], 'pages' => ['status' => 'idle', 'error' => null]], '-2 days');
$state('onboarding', ['hidden' => false], '-3 days');

/*
 * Sessions. Each message carries when it was sent; a person's says who.
 */
$session = function (array $data, string $when) use ($db, $stamp, $atom, $id): array {
    $data += [
        'id' => $id(13), 'answers' => [], 'messages' => [], 'draft' => null, 'status' => 'idle', 'error' => null,
        'element_id' => null, 'site_id' => 1, 'user_id' => 1, 'touched_by' => null, 'run_by' => null,
        'usage' => ['input' => 0, 'output' => 0], 'examples' => [], 'images' => [], 'source' => null,
        'entry_type' => null, 'applied_at' => null,
    ];
    $data['created_at'] ??= $atom($when.' -20 minutes');
    $data['updated_at'] = $atom($when);

    $db->createCommand()->insert('{{%ghostwriter_sessions}}', [
        'id' => $data['id'], 'userId' => $data['user_id'], 'elementId' => $data['element_id'], 'data' => Json::encode($data),
        'dateCreated' => $stamp($when.' -20 minutes'), 'dateUpdated' => $stamp($when), 'uid' => StringHelper::UUID(),
    ])->execute();

    return $data;
};

$brief = function (array $answers, array $labels, string $what = 'Something new'): string {
    $text = "Here is the brief for a new entry: {$what}.";

    foreach ($labels as $handle => $label) {
        if (($answers[$handle] ?? '') !== '') {
            $text .= "\n\n**{$label}**\n{$answers[$handle]}";
        }
    }

    return $text;
};

$generic = [
    'subject' => 'What is this about?',
    'reader' => 'Who is it for, and what should they do after reading?',
    'points' => 'What must it say?',
    'shape' => 'Anything about its shape or length?',
    'must_not_appear' => 'What must not appear?',
];

$words = fn (string $yaml) => Draft::parse($yaml)->wordCount();

// Questions: Ghostwriter asks two things before it writes.
$answers = [
    'subject' => "A wildlife pond for a small garden\nWhy even a small garden has room for a pond, and how to make one that wildlife will use.",
    'reader' => 'People with ordinary town gardens who like the idea of a pond but think they have no room. After reading they should feel able to dig one this winter.',
    'points' => "- A pond the size of a door is enough.\n- One shallow, sloping side so frogs and hedgehogs can get in and out.\n- Fill it with rainwater, not tap water.\n- No fish.\n- Plant it with natives: water mint, marsh marigold, flowering rush.",
    'shape' => '',
    'must_not_appear' => '',
];
$questions = $session([
    'type' => 'any:journal', 'element_id' => (int) $asking->id, 'answers' => $answers,
    'messages' => [
        ['role' => 'user', 'content' => $brief($answers, $generic), 'at' => $atom('-40 minutes'), 'by' => $me],
        ['role' => 'assistant', 'content' => "Thanks, this is a good brief. Two things before I write, so the piece rests on your own experience rather than general advice:\n\n1. **Have you built one like this?** If so, where, roughly how big, and what turned up in the first year? A named project would make the piece much stronger.\n2. **When should people dig?** You said this winter. Is there a month you would recommend, and anything to wait for, such as the ground drying out?\n\nAnswer as briefly as you like. If you'd rather I went ahead, I'll mark those places [like this] for you to fill in.", 'at' => $atom('-38 minutes'), 'asks' => true],
    ],
    'usage' => ['input' => 9214, 'output' => 186],
], '-38 minutes');

// Draft: a page with a page builder, ready to use.
$pageYaml = trim((string) file_get_contents($fixtures.'/winter-garden-care.yaml'));
$answers = [
    'subject' => "Winter garden care\nA page for our new winter service: a visit a month from November to February to plant, divide, mulch and prune.",
    'reader' => 'Existing clients and people whose gardens we built. After reading they should get in touch to book a first visit in October.',
    'points' => "- Four visits, one a month, November to February.\n- Two of us for a morning each time.\n- We leave seed heads for wildlife.\n- A first walk-round in October, and a list for spring in February.\n- Price: [Add: the price for a season]",
    'shape' => 'Like the Services page: hero, a text section, a picture, a quote, how it works, then the button.',
    'must_not_appear' => '',
];
$draft = $session([
    'type' => 'any:pages', 'element_id' => (int) $pageEntry->id, 'answers' => $answers, 'draft' => $pageYaml, 'examples' => [67],
    'messages' => [
        ['role' => 'user', 'content' => $brief($answers, $generic), 'at' => $atom('-2 hours -10 minutes'), 'by' => $me],
        ['role' => 'assistant', 'content' => "One question first: is there a client quote about winter work I can use? If not, I'll leave a place for one.", 'at' => $atom('-2 hours -8 minutes'), 'asks' => true],
        ['role' => 'user', 'content' => 'Jean in Hexham said: "We used to dread the garden in winter. Now it is the part of the year we look forward to." Use that.', 'at' => $atom('-2 hours -5 minutes'), 'by' => $me],
        ['role' => 'assistant', 'content' => "Here's a draft, built like the Services page: a hero, what we do, a picture, Jean's quote, how it works, and a prompt to get in touch.\n\nThe price is marked **[price]** for you to fill in.", 'at' => $atom('-2 hours'), 'draft' => ['change' => 'written', 'words' => $words($pageYaml), 'was' => null]],
    ],
    'usage' => ['input' => 21480, 'output' => 1102],
], '-2 hours');

// Shared: Maya started it; the signed-in person carried it on.
$tulipYaml = trim((string) file_get_contents($fixtures.'/tulips.yaml'));
$firstYaml = str_replace("\n\n  ## A note on squirrels\n\n  They will dig up a freshly planted pot within the hour. A piece of chicken wire over the top for the first month saves a lot of bad language.", '', $tulipYaml);
$answers = [
    'subject' => "Planting tulips and alliums in October\nA seasonal piece on planting bulbs now for spring: alliums first, tulips at the end of the month.",
    'reader' => 'Keen gardeners who read the seasonal posts. They should plant some bulbs this month.',
    'points' => "- Alliums from late September, 15 cm deep, in groups through the border.\n- Tulips late, once the soil is cold, about 20 cm deep.\n- Pots with grit for heavy soil.\n- Feed after flowering.",
    'shape' => 'Like "What to do in the garden in late autumn".',
    'must_not_appear' => '',
];
$shared = $session([
    'type' => 'any:journal', 'element_id' => (int) $sharedEntry->id, 'user_id' => $maya, 'touched_by' => $me, 'answers' => $answers, 'draft' => $tulipYaml, 'examples' => [19],
    'messages' => [
        ['role' => 'user', 'content' => $brief($answers, $generic), 'at' => $atom('-1 day -1 hour'), 'by' => $maya],
        ['role' => 'assistant', 'content' => "Here's a first draft, shaped like the late autumn piece.", 'at' => $atom('-1 day -58 minutes'), 'draft' => ['change' => 'written', 'words' => $words($firstYaml), 'was' => null]],
        ['role' => 'user', 'content' => 'Can we name a variety? Ours is \'Purple Sensation\'.', 'at' => $atom('-1 day -50 minutes'), 'by' => $maya],
        ['role' => 'assistant', 'content' => "Done: *Allium* 'Purple Sensation' is now named.", 'at' => $atom('-1 day -49 minutes'), 'draft' => ['change' => 'updated', 'words' => $words($firstYaml) + 9, 'was' => $words($firstYaml)]],
        ['role' => 'user', 'content' => 'Add a line about squirrels. Chicken wire for the first month.', 'at' => $atom('-25 minutes'), 'by' => $me],
        ['role' => 'assistant', 'content' => 'Added "A note on squirrels" before the closing quote.', 'at' => $atom('-24 minutes'), 'draft' => ['change' => 'updated', 'words' => $words($tulipYaml), 'was' => $words($firstYaml) + 9]],
    ],
    'usage' => ['input' => 38102, 'output' => 2410],
], '-24 minutes');

// Failed: the provider was busy when the answers went in.
$answers = [
    'subject' => "Hedges for exposed gardens\nWhich hedges stand up to wind on high, open sites, and how to get them going.",
    'reader' => 'People with gardens on the hills above the Tyne. They should choose a hedge and plant it bare-root this winter.',
    'points' => "- Hawthorn, blackthorn, field maple, hazel: a mixed native hedge.\n- Plant bare-root, November to March.\n- A windbreak mesh for the first two winters.",
    'shape' => '',
    'must_not_appear' => '',
];
$failed = $session([
    'type' => 'any:journal', 'element_id' => (int) $failedEntry->id, 'answers' => $answers, 'status' => 'failed',
    'error' => 'The provider is busy right now. Try again in a minute.', 'run_by' => $me,
    'messages' => [
        ['role' => 'user', 'content' => $brief($answers, $generic), 'at' => $atom('-1 hour -12 minutes'), 'by' => $me],
        ['role' => 'assistant', 'content' => 'Before I write: is there a garden of ours on an exposed site I can mention, and how did its hedge do?', 'at' => $atom('-1 hour -10 minutes'), 'asks' => true],
        ['role' => 'user', 'content' => 'Yes, the farmhouse garden above Haydon Bridge. The hawthorn and hazel took well; the beech sulked for two years.', 'at' => $atom('-1 hour -5 minutes'), 'by' => $me],
    ],
    'usage' => ['input' => 8122, 'output' => 64],
], '-1 hour -5 minutes');

// Editing the About page: one change asked for, the revised draft back.
$aboutYaml = trim((string) file_get_contents($fixtures.'/about-revised.yaml'));
$editing = $session([
    'type' => 'any:pages', 'element_id' => 53, 'source' => 53, 'draft' => $aboutYaml,
    'messages' => [
        ['role' => 'user', 'content' => 'This entry already exists on the site. Its content as it stands is the current draft. I will ask for changes to it.', 'at' => $atom('-15 minutes')],
        ['role' => 'assistant', 'content' => 'I have the entry as it stands. Tell me what to change.', 'at' => $atom('-15 minutes'), 'editing' => true],
        ['role' => 'user', 'content' => 'Make "Who we are" shorter, and add a belief about keeping rainwater.', 'at' => $atom('-12 minutes'), 'by' => $me],
        ['role' => 'assistant', 'content' => "Done:\n\n- **Who we are** is down to two short paragraphs; the team and where we work are still there.\n- **What we believe** has a new line about slowing rain down before it leaves the garden.\n\nNothing else has changed.", 'at' => $atom('-11 minutes'), 'draft' => ['change' => 'updated', 'words' => $words($aboutYaml), 'was' => $words($aboutYaml) + 21]],
    ],
    'usage' => ['input' => 12840, 'output' => 690],
], '-11 minutes');

/*
 * The content plan: four open ideas, one in progress, one dismissed, and
 * three suggestions waiting to be looked over.
 */
$ideas = [
    ['title' => 'What a garden design costs, and where the money goes', 'section' => 'journal', 'why' => 'Nothing on the site helps a reader set a budget before they get in touch, and it is the question we are asked most.', 'source' => 'suggested'],
    ['title' => 'A year in the Prudhoe school garden', 'section' => 'journal', 'type' => 'project-story', 'why' => '"A school garden in Prudhoe" ends as the planting goes in. A year on, there is a story in what the children grew.', 'source' => 'suggested'],
    ['title' => 'Our favourite plants for clay soil', 'section' => 'journal', 'notes' => 'Most of our gardens are on heavy clay. Ten plants that cope, with a line on each.', 'source' => 'added'],
    ['title' => 'Gardens for schools and community groups', 'section' => 'pages', 'why' => 'The voice guide names schools and community groups as core readers, but no page speaks to them directly.', 'source' => 'suggested'],
    ['title' => 'Winter garden care', 'section' => 'pages', 'why' => 'Clients ask what we do between November and February. A page for the winter visits would answer it.', 'source' => 'suggested', 'status' => 'drafted', 'session' => $draft['id']],
    ['title' => 'Ten things we have learned in ten years', 'section' => 'journal', 'why' => '"Ten years of Northfold" already covers the anniversary.', 'source' => 'suggested', 'status' => 'dismissed'],
];

foreach (array_reverse($ideas) as $i => $idea) {
    $ideaId = $id(8);
    $document('idea', $ideaId, Json::encode(['id' => $ideaId] + $idea + ['type' => null, 'why' => '', 'notes' => '', 'status' => 'open', 'session' => null, 'createdAt' => (new DateTime('-'.($i + 2).' days'))->format('Y-m-d')]), '-'.(6 - $i).' days');
}

$state('plan', [
    'status' => 'idle', 'error' => null, 'task' => null, 'messages' => [], 'scanned' => [],
    'pending' => [
        ['title' => 'Making a rain garden: a step-by-step guide', 'section' => 'journal', 'type' => null, 'why' => '"Rain gardens for ordinary front gardens" makes the case; nothing yet shows a reader how to build one.', 'notes' => 'Digging, the soil mix, planting, and what it costs.'],
        ['title' => 'Spring bulbs for pots', 'section' => 'journal', 'type' => null, 'why' => 'The seasonal posts cover borders, but many readers garden in pots on a yard or a balcony.', 'notes' => 'Plant in October and November for flowers from March to May.'],
        ['title' => 'How we work with schools', 'section' => 'pages', 'type' => null, 'why' => 'The Prudhoe school garden shows the work; a page would explain how a school can start.', 'notes' => 'Who to talk to, how long it takes, and how a garden is paid for.'],
    ],
], '-1 hour');

/*
 * Pictures for the hero image of the tulips entry: a judged search with
 * six results, and a made picture. The thumbnails are the site's own
 * images, so nothing is fetched from elsewhere.
 */
$images = rtrim($argv[1] ?? 'http://gw-test-craft.test', '/').'/uploads/images/';
$photo = fn (string $file, string $credit, string $term, ?string $reason = null) => [
    'source' => 'openverse', 'id' => $id(16), 'thumb' => $images.$file, 'credit' => $credit, 'credit_url' => null,
    'licence' => 'CC0', 'term' => $term, 'picked' => $reason !== null, 'reason' => $reason, 'alt' => '',
];
$found = $id(13);
$state('image:'.$found, [
    'userId' => $me, 'fieldId' => 4, 'elementId' => (int) $pictured->id, 'siteId' => 1, 'label' => 'Hero image', 'mode' => 'find',
    'terms' => ['allium seedheads in a border', 'tulip bulbs in terracotta pots'],
    'id' => $found, 'status' => 'ready', 'error' => null,
    'options' => [
        $photo('spring-bulbs.jpg', 'Ellen Hart', 'tulip bulbs in terracotta pots', 'Bulbs in flower against soft hills: the same flat, layered style as the journal\'s other pictures.'),
        $photo('woodland-edge.jpg', 'Tom Ridley', 'allium seedheads in a border', 'Low light over layered hills, close to the soft, flat style of the journal\'s other pictures.'),
        $photo('meadow-summer.jpg', 'Ana Sousa', 'allium seedheads in a border'),
        $photo('walled-garden-corbridge.jpg', 'J. Marsh', 'allium seedheads in a border'),
        $photo('winter-structure.jpg', 'Priya Nair', 'allium seedheads in a border'),
        $photo('planting-plan.jpg', 'Sam Okafor', 'tulip bulbs in terracotta pots'),
    ],
    'judged' => true, 'noneFit' => false, 'withReferences' => true, 'file' => null, 'createdAt' => time(),
]);

$made = $id(13);
$picture = (string) file_get_contents($root.'/web/uploads/images/late-autumn-border.jpg');
$state('image:'.$made, [
    'userId' => $me, 'fieldId' => 4, 'elementId' => (int) $pictured->id, 'siteId' => 1, 'label' => 'Hero image', 'mode' => 'make',
    'direction' => 'Allium seed heads in a border at dusk, low sun behind soft hills',
    'id' => $made, 'status' => 'ready', 'error' => null, 'terms' => [], 'options' => [],
    'file' => $made.'.jpg', 'mime' => 'image/jpeg', 'createdAt' => time(),
]);
$db->createCommand()->insert('{{%ghostwriter_files}}', ['id' => $made.'-made', 'mime' => 'image/jpeg', 'extension' => 'jpg', 'data' => base64_encode($picture), 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID()])->execute();

/*
 * The Ghostwriter widget, first on the signed-in person's dashboard.
 */
$db->createCommand()->update('{{%widgets}}', ['sortOrder' => new Expression('[[sortOrder]] + 1')], ['userId' => $me])->execute();
$db->createCommand()->insert('{{%widgets}}', [
    'userId' => $me, 'type' => 'nineteenninetyfour\\ghostwriter\\widgets\\GhostwriterWidget', 'sortOrder' => 1, 'colspan' => 1,
    'settings' => Json::encode(['limit' => 5]), 'dateCreated' => $now, 'dateUpdated' => $now, 'uid' => StringHelper::UUID(),
])->execute();

echo Json::encode([
    'blank' => $cpPath($blank),
    'questions' => $with($cpPath($asking), $questions['id']),
    'draft' => $with($cpPath($pageEntry), $draft['id']),
    'draftSession' => $draft['id'],
    'shared' => $with($cpPath($sharedEntry), $shared['id']),
    'failed' => $with($cpPath($failedEntry), $failed['id']),
    'editing' => $with($cpPath(Entry::find()->id(53)->status(null)->one()), $editing['id']),
    'pictured' => $cpPath($pictured),
    'found' => $found,
    'made' => $made,
    'maya' => $maya,
])."\n";
