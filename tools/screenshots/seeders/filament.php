<?php

/**
 * Seeds the Filament test app with every state the docs shots need, written
 * straight into Ghostwriter's tables: no job is queued and no model called.
 * Run from the app's root by FilamentSite::seed(); prints what it made as
 * JSON on its last line.
 *
 *   php seeders/filament.php <site url>
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Filament\Kinds\ContentType;

require getcwd().'/vendor/autoload.php';

$app = require getcwd().'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$url = rtrim($argv[1] ?? (string) config('app.url'), '/');
$files = __DIR__.'/filament';
$prefix = (string) config('ghostwriter.table_prefix', 'ghostwriter_');
$workspace = ['tenant_type' => '', 'tenant_id' => ''];
$now = now();

// One setting fixed in config for the run (settings-locked). It also keeps
// Get started from suggesting kinds when it opens.
$config = base_path('config/ghostwriter.php');
$text = (string) file_get_contents($config);
$locked = preg_replace("~// 'suggest_kinds_automatically' => true,~", "'suggest_kinds_automatically' => false,", $text, 1, $count);

if ($count !== 1) {
    throw new RuntimeException('Could not lock suggest_kinds_automatically in config/ghostwriter.php.');
}

file_put_contents($config, $locked);

// A placeholder image key, so the image button shows "Make one". Nothing is called with it.
$env = base_path('.env');
$text = (string) file_get_contents($env);

if (preg_match('/^OPENAI_API_KEY=\s*$/m', $text)) {
    file_put_contents($env, preg_replace('/^OPENAI_API_KEY=\s*$/m', 'OPENAI_API_KEY=sk-test-screenshots-placeholder', $text, 1));
} elseif (! preg_match('/^OPENAI_API_KEY=/m', $text)) {
    file_put_contents($env, rtrim($text)."\nOPENAI_API_KEY=sk-test-screenshots-placeholder\n");
}

$json = fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$at = fn (int $minutes): string => $now->copy()->subMinutes($minutes)->format('Y-m-d H:i:s');
$iso = fn (int $minutes): string => $now->copy()->subMinutes($minutes)->toIso8601String();
$ulid = fn (): string => strtolower((string) Str::ulid());

$state = function (string $key, array $value, int $minutes = 0) use ($prefix, $workspace, $json, $at): void {
    DB::table($prefix.'states')->updateOrInsert($workspace + ['key' => $key], ['value' => $json($value), 'created_at' => $at($minutes), 'updated_at' => $at($minutes)]);
};

$admin = DB::table('users')->orderBy('id')->value('id');

// The second person in the shared conversation, fictional, removed by the restore.
$maya = DB::table('users')->where('email', 'maya@northfold.test')->value('id') ?? DB::table('users')->insertGetId([
    'name' => 'Maya Lindqvist',
    'email' => 'maya@northfold.test',
    'password' => Hash::make(Str::random(40)),
    'is_admin' => false,
    'created_at' => $at(60 * 24 * 30),
    'updated_at' => $at(60 * 24 * 30),
]);

foreach (['guides', 'kinds', 'ideas', 'sessions'] as $table) {
    DB::table($prefix.$table)->where($workspace)->delete();
}

// Guides.
foreach (['voice' => [60 * 26, 'voice.md'], 'imagery' => [60 * 25, 'imagery.md']] as $kind => [$minutes, $file]) {
    DB::table($prefix.'guides')->insert($workspace + ['kind' => $kind, 'body' => (string) file_get_contents("{$files}/{$file}"), 'created_at' => $at(60 * 30), 'updated_at' => $at($minutes)]);
}

$scanned = DB::table('posts')->where('status', 'published')->orderByDesc('published_at')->limit(10)->get(['id', 'title'])
    ->map(fn ($post) => ['resource' => 'posts', 'key' => (string) $post->id, 'title' => $post->title])->all();

$state('guide:voice', [
    'status' => 'idle',
    'error' => null,
    'task' => null,
    'messages' => [
        ['role' => 'user', 'content' => 'We say “garden”, never “outdoor space”. Please add that.'],
        ['role' => 'assistant', 'content' => 'I’ve updated the guide: “outdoor space” and “outdoor room” are now on the list of words to avoid, with “garden” in their place.'],
    ],
    'scanned' => $scanned,
], 60 * 26);

$state('guide:imagery', ['status' => 'idle', 'error' => null, 'task' => null, 'messages' => [], 'scanned' => array_slice($scanned, 0, 8)], 60 * 25);

// The learned kind.
$projectStory = [
    'title' => 'Project story',
    'description' => 'A write-up of one garden Northfold designed: the site and what the client wanted, what we did, and how it has turned out.',
    'resource' => 'posts',
    'examples' => ['1', '5', '11'],
    'questions' => [
        ['handle' => 'place', 'label' => 'Which garden is it, and where?', 'instructions' => 'The place as we would name it to a client: a street, a village, a school.', 'type' => 'text', 'required' => true],
        ['handle' => 'brief', 'label' => 'What did the client want?', 'instructions' => 'In their words, if you have them.', 'type' => 'textarea', 'required' => true],
        ['handle' => 'site', 'label' => 'What made the site difficult?', 'instructions' => 'Slope, shade, soil, access, budget.', 'type' => 'textarea', 'required' => true],
        ['handle' => 'work', 'label' => 'What did we do?', 'instructions' => 'The main moves, the materials and the planting, and who helped build it.', 'type' => 'textarea', 'required' => true],
        ['handle' => 'since', 'label' => 'How has it turned out?', 'instructions' => 'How long since planting, what worked, and what we would change.', 'type' => 'textarea', 'required' => false],
    ],
    'guidance' => "Open on the place: one or two sentences on the site as we first found it.\n\nThen what the client wanted, the difficulty, and what we did about it, under two to four short plain headings such as \"Paths before planting\".\n\nEnd honestly: how it has settled, and what we would do differently. Between 450 and 700 words.",
    'checklist' => [
        'The garden is named and placed in the first paragraph.',
        'Every measurement, plant and material comes from the brief.',
        'Anyone who helped build it is credited.',
        'It ends with how the garden has turned out, not a sales line.',
    ],
];

DB::table($prefix.'kinds')->insert($workspace + [
    'resource' => 'posts',
    'handle' => 'project-story',
    'title' => $projectStory['title'],
    'description' => $projectStory['description'],
    'definition' => $json($projectStory),
    'created_at' => $at(60 * 24),
    'updated_at' => $at(60 * 24),
]);

// Kinds suggested, with nothing due, so nothing looks again on its own.
$state('kinds:posts', [
    'status' => 'idle',
    'error' => null,
    'checked_at' => $iso(60 * 24),
    'records' => 12,
    'suggestions' => [
        ['id' => 'a1c4e6f80b21', 'title' => 'Seasonal advice', 'description' => 'A practical guide to one job in the garden at one time of year: what to do, when, and why it matters.', 'why' => 'Five posts open by naming a month or a season and promise to explain what to do in the garden and when, ending with an offer of a site walk.', 'examples' => ['2', '9', '6']],
        ['id' => 'b2d5f7a91c32', 'title' => 'Studio news', 'description' => 'A short announcement about Northfold itself, such as a move or someone new joining the studio.', 'why' => 'Posts about the studio itself, such as the move to Wharf Lane and Priya joining, give the news in the first line and the background after it.', 'examples' => ['10', '7', '3']],
    ],
    'dismissed' => [],
    'learning' => ['status' => 'idle', 'error' => null, 'queue' => []],
], 60 * 24);

$state('kinds:pages', [
    'status' => 'idle',
    'error' => null,
    'checked_at' => $iso(60 * 24),
    'records' => 5,
    'suggestions' => [
        ['id' => 'c3e6a8b02d43', 'title' => 'Service page', 'description' => 'A page about one thing the studio offers: who it is for, how it works, and how to start.', 'why' => 'Services and Approach follow one recipe: a hero with a call to action, headed sections, a client quote and a closing call to action.', 'examples' => ['2', '3', '1']],
    ],
    'dismissed' => [],
    'learning' => ['status' => 'idle', 'error' => null, 'queue' => []],
], 60 * 24);

// Sessions.
$generic = fn (string $resource, string $label) => ContentType::generic($resource, $label);
$brief = function (ContentType $type, array $answers): string {
    $lines = ["Here is the brief for a new record: {$type->title}.", ''];

    foreach ($type->questions as $question) {
        $answer = trim((string) ($answers[$question['handle']] ?? ''));
        $lines[] = '**'.$question['label'].'**';
        $lines[] = $answer !== '' ? $answer : '(not answered)';
        $lines[] = '';
    }

    return trim(implode("\n", $lines));
};
$words = fn (string $yaml): int => Draft::parse($yaml)->wordCount();

$session = function (array $row) use ($prefix, $workspace, $json, $at, $ulid): array {
    $minutes = $row['minutes'];
    unset($row['minutes']);

    foreach (['answers', 'messages', 'examples'] as $key) {
        if (isset($row[$key])) {
            $row[$key] = $json($row[$key]);
        }
    }

    $row = array_merge($workspace, ['ulid' => $ulid(), 'status' => 'idle', 'editing' => false, 'created_at' => $at($minutes + 30), 'updated_at' => $at($minutes)], $row);
    $row['id'] = DB::table($prefix.'sessions')->insertGetId($row);

    return $row;
};

$posts = $generic('posts', 'Posts');
$pages = $generic('pages', 'Pages');

// Questions: Ghostwriter asks two things before it writes.
$answers = [
    'subject' => 'A wildlife pond for a small garden',
    'reader' => 'People with a small town garden who would like more wildlife but think a pond needs a lot of room. They should come away ready to dig one this autumn.',
    'points' => "Even a pond the size of a washing-up bowl brings in frogs, damselflies and birds.\nOne sloping side, so creatures can get in and out.\nNo fish.\nFill it with rainwater, not tap water.\nAutumn is the best time to dig.",
    'shape' => '',
    'must_not_appear' => 'Pond liners by brand name.',
];
$questions = $session([
    'user_id' => $admin, 'touched_by' => $admin, 'run_by' => $admin,
    'resource' => 'posts', 'kind' => $posts->handle, 'answers' => $answers, 'examples' => ['4', '12'],
    'messages' => [
        ['role' => 'user', 'content' => $brief($posts, $answers), 'at' => $iso(26), 'by' => $admin],
        ['role' => 'assistant', 'content' => "Two things before I write, so the piece is right.\n\nFirst, is there a Northfold garden with a small pond we can mention, or should this stay as general advice?\n\nSecond, should it end with an offer of a site walk, as the other advice posts do?", 'at' => $iso(25), 'asks' => true],
    ],
    'minutes' => 25,
]);

// Draft: a page built with blocks, from an idea on the plan.
$winter = (string) file_get_contents("{$files}/winter-garden-care.yaml");
$answers = [
    'subject' => 'Winter garden care: a page for our winter maintenance visits, November to February.',
    'reader' => 'Existing clients and neighbours of gardens we have designed. They should book a winter visit.',
    'points' => "Cutting back only what has collapsed; leaving seedheads.\nMulching, pruning, bare-root planting, checking stakes after gales.\nA one-page note of what to do before spring.\nA few mornings free between November and February.",
    'shape' => 'Like the Services page: hero, text, image, quote, call to action.',
    'must_not_appear' => 'Prices.',
];
$draft = $session([
    'user_id' => $admin, 'touched_by' => $admin, 'run_by' => $admin,
    'resource' => 'pages', 'kind' => $pages->handle, 'answers' => $answers, 'examples' => ['2'],
    'messages' => [
        ['role' => 'user', 'content' => $brief($pages, $answers), 'at' => $iso(14), 'by' => $admin],
        ['role' => 'assistant', 'content' => 'Here is a first draft, following the Services page block for block. The quote is a placeholder in a client’s voice: swap in a real one before you publish.', 'at' => $iso(12), 'draft' => ['change' => 'written', 'words' => $words($winter)]],
    ],
    'draft' => $winter,
    'minutes' => 12,
]);

// Shared: started by Maya, answered by the person signed in.
$tulips = (string) file_get_contents("{$files}/tulips.yaml");
$answers = [
    'subject' => 'Planting tulips and alliums in October',
    'reader' => 'Gardeners planning spring bulbs. They should know how deep, how many and when.',
    'points' => "Alliums from late September, tulips at the end of October or in November.\nCold soil keeps tulip fire away.\nTulips at least twenty centimetres deep.\nAbout fifty tulips to a square metre.",
    'shape' => 'Like our other seasonal advice posts.',
    'must_not_appear' => '',
];
$shared = $session([
    'user_id' => $maya, 'touched_by' => $admin, 'run_by' => $admin,
    'resource' => 'posts', 'kind' => $posts->handle, 'answers' => $answers, 'examples' => ['2', '9'],
    'messages' => [
        ['role' => 'user', 'content' => $brief($posts, $answers), 'at' => $iso(95), 'by' => $maya],
        ['role' => 'assistant', 'content' => 'Should it mention bulbs in pots as well as in borders, or keep to borders?', 'at' => $iso(94), 'asks' => true],
        ['role' => 'user', 'content' => 'Both, please. Most of our clients have at least a few pots by the back door.', 'at' => $iso(90), 'by' => $maya],
        ['role' => 'assistant', 'content' => 'Here is a draft covering borders and pots.', 'at' => $iso(88), 'draft' => ['change' => 'written', 'words' => $words($tulips) - 40]],
        ['role' => 'user', 'content' => 'Could you add a line on leaving the allium seedheads standing? They look as good in August as the flowers do in May.', 'at' => $iso(62), 'by' => $admin],
        ['role' => 'assistant', 'content' => 'Done: the allium section now ends by leaving the seedheads standing through the summer.', 'at' => $iso(60), 'draft' => ['change' => 'updated', 'words' => $words($tulips)]],
    ],
    'draft' => $tulips,
    'minutes' => 60,
]);

// Failed: the provider was busy.
$answers = [
    'subject' => 'Hedges for exposed gardens',
    'reader' => 'People with gardens on the coast or high ground, where the wind shreds anything soft. They should choose a hedge that will cope.',
    'points' => "Hawthorn, blackthorn, sea buckthorn and field maple.\nPlant bare-root from November.\nA windbreak that filters the wind does better than a solid wall.",
    'shape' => '',
    'must_not_appear' => '',
];
$failed = $session([
    'user_id' => $admin, 'touched_by' => $admin, 'run_by' => $admin,
    'resource' => 'posts', 'kind' => $posts->handle, 'answers' => $answers, 'examples' => [],
    'messages' => [
        ['role' => 'user', 'content' => $brief($posts, $answers), 'at' => $iso(185), 'by' => $admin],
        ['role' => 'assistant', 'content' => 'Is this for coastal gardens, high moorland gardens, or both?', 'at' => $iso(184), 'asks' => true],
        ['role' => 'user', 'content' => 'Both. Most of the ones we see are in the Cheviot foothills, but we have two on the coast at Seahouses.', 'at' => $iso(181), 'by' => $admin],
    ],
    'status' => 'failed',
    'error' => 'The provider is busy right now. Try again in a minute.',
    'minutes' => 180,
]);

// Editing the About page: a change asked for, and the revised draft.
$about = (string) file_get_contents("{$files}/about-revised.yaml");
$editing = $session([
    'user_id' => $admin, 'touched_by' => $admin, 'run_by' => $admin,
    'resource' => 'pages', 'record_key' => '1', 'kind' => $pages->handle, 'editing' => true,
    'messages' => [
        ['role' => 'user', 'content' => __('ghostwriter::panel.editing_brief'), 'at' => $iso(45), 'editing' => true],
        ['role' => 'assistant', 'content' => __('ghostwriter::panel.editing_ready'), 'at' => $iso(45), 'editing' => true],
        ['role' => 'user', 'content' => 'Make the opening warmer, and say a little more about the Wharf Lane workshop: that clients are welcome to drop in.', 'at' => $iso(41), 'by' => $admin],
        ['role' => 'assistant', 'content' => 'I’ve rewritten the hero’s subheading and the first section so they open on the six of you in the workshop, and added that clients are welcome to come by. The rest is as it was.', 'at' => $iso(40), 'draft' => ['change' => 'updated', 'words' => $words($about)]],
    ],
    'draft' => $about,
    'minutes' => 40,
]);

// The content plan.
$idea = function (array $row, int $minutes) use ($prefix, $workspace, $at): int {
    return DB::table($prefix.'ideas')->insertGetId(array_merge($workspace, ['kind' => null, 'why' => null, 'notes' => null, 'status' => 'open', 'source' => 'added', 'session_id' => null, 'created_at' => $at($minutes), 'updated_at' => $at($minutes)], $row));
};

$idea(['resource' => 'posts', 'title' => 'Ten plants for winter colour', 'why' => 'Winter interest comes up often on site walks.', 'status' => 'dismissed', 'source' => 'suggested'], 60 * 50);
$idea(['resource' => 'pages', 'title' => 'Winter garden care', 'why' => 'Clients ask what happens between November and February, and no page says.', 'status' => 'drafted', 'source' => 'suggested', 'session_id' => $draft['id']], 60 * 48);
$idea(['resource' => 'pages', 'title' => 'Planting design', 'why' => 'Planting design is named on Services but has no page of its own, and it is the work Priya leads.', 'source' => 'suggested'], 60 * 47);
$idea(['resource' => 'posts', 'title' => 'Bulbs for a shady corner', 'why' => 'Three of the most-read advice posts are about planting, but none covers shade, which comes up on nearly every site walk.', 'source' => 'suggested'], 60 * 46);
$idea(['resource' => 'posts', 'kind' => 'project-story', 'title' => 'A courtyard garden in Jesmond, after its first winter', 'why' => 'The journal has four project stories, none from the last year.', 'source' => 'suggested'], 60 * 45);
$idea(['resource' => 'posts', 'title' => 'What happens on a site walk', 'why' => 'People ask what a site walk involves before they book one.', 'notes' => 'Walk through a real one: what we look at, what we ask, and what you get afterwards. Link to the site walk post from 2023.'], 60 * 20);

$state('plan', [
    'status' => 'idle',
    'error' => null,
    'pending' => [
        ['title' => 'Making a wildlife hedge from scratch', 'resource' => 'posts', 'kind' => null, 'why' => 'The hedging questions on site walks have no post to point to, and bare-root season starts in November.', 'notes' => 'Which native species, how to plant a double row, and the first three years of cutting.'],
        ['title' => 'The Gateshead playground, three years on', 'resource' => 'posts', 'kind' => 'project-story', 'why' => 'The 2022 project story promised a return visit, and the planting has now filled in.', 'notes' => null],
        ['title' => 'Garden maintenance visits', 'resource' => 'pages', 'kind' => null, 'why' => 'After care is listed on Services but there is no page saying what a visit includes or how to book one.', 'notes' => null],
    ],
], 5);

// Photos found for a post's hero image, judged: two best matches.
$thumb = fn (string $file): string => "{$url}/storage/seed/{$file}";
$photo = fn (string $file, string $id, string $credit, string $licence, string $title, string $term, ?string $reason = null) => [
    'source' => 'openverse', 'id' => $id, 'thumb' => $thumb($file), 'credit' => $credit.' via Openverse', 'credit_url' => null,
    'licence' => $licence, 'title' => $title, 'description' => null, 'tags' => [], 'width' => 1600, 'height' => 1000,
    'url' => $thumb($file), 'term' => $term, 'picked' => $reason !== null, 'reason' => $reason, 'alt' => $title, 'asset_title' => $title,
];

$find = $ulid();
$state("image:{$find}", [
    'id' => $find,
    'mode' => 'find',
    'user_id' => $admin,
    'status' => 'ready',
    'error' => null,
    'terms' => ['allium seedheads in a border', 'tulip bulbs in terracotta pots'],
    'options' => [
        $photo('october.jpg', 'f3a1c2d4-0001-4b6e-9a51-5d2e7c1a0b01', 'hollinsfield', 'CC BY 2.0', 'Seedheads in a late border', 'allium seedheads in a border', 'low autumn sun over soft hills, the same muted greens and ochres as the journal'),
        $photo('winter.jpg', 'f3a1c2d4-0002-4b6e-9a51-5d2e7c1a0b02', 'fellside.garden', 'CC0', 'Frosted border at first light', 'allium seedheads in a border', 'layered hills and a pale sky, calm and seasonal like the other posts'),
        $photo('mulch.jpg', 'f3a1c2d4-0003-4b6e-9a51-5d2e7c1a0b03', 'tynebank', 'CC BY 2.0', 'Border after mulching', 'allium seedheads in a border'),
        $photo('walled-garden.jpg', 'f3a1c2d4-0004-4b6e-9a51-5d2e7c1a0b04', 'hollinsfield', 'CC BY-SA 2.0', 'Walled garden in October', 'tulip bulbs in terracotta pots'),
        $photo('pruning.jpg', 'f3a1c2d4-0005-4b6e-9a51-5d2e7c1a0b05', 'greenlonning', 'CC0', 'Pots by a garden wall', 'tulip bulbs in terracotta pots'),
        $photo('rain-garden.jpg', 'f3a1c2d4-0006-4b6e-9a51-5d2e7c1a0b06', 'tynebank', 'CC BY 2.0', 'Planting in a front garden', 'tulip bulbs in terracotta pots'),
    ],
    'judged' => true,
    'none_fit' => false,
    'with_references' => true,
    'file' => null,
    'created_at' => $now->timestamp - 300,
], 5);

// A picture made: one of the app's own images stands in for it.
$make = $ulid();
$made = "ghostwriter/images/{$make}.jpeg";
Storage::disk('local')->put($made, Storage::disk('public')->get('seed/winter.jpg'));
$state("image:{$make}", [
    'id' => $make,
    'mode' => 'make',
    'user_id' => $admin,
    'status' => 'ready',
    'error' => null,
    'terms' => [],
    'options' => [],
    'file' => $made,
    'extension' => 'jpeg',
    'direction' => 'Allium seedheads standing in a frosty border, low sun',
    'created_at' => $now->timestamp - 120,
], 2);

echo json_encode([
    'questions' => $questions['ulid'],
    'draft' => $draft['ulid'],
    'shared' => $shared['ulid'],
    'failed' => $failed['ulid'],
    'editing' => $editing['ulid'],
    'find' => $find,
    'make' => $make,
    'about' => 1,
    'post' => 2,
]).PHP_EOL;
