<?php

/*
 * A live smoke test of the Shutterstock adapter: one search, and one
 * look-up and preview of the first result, against the sandbox
 * (api-sandbox.shutterstock.com). Search only: nothing is licensed, and
 * no connected account is needed. Run it on a developer's machine; it is
 * not shipped (tools/ is export-ignored) and not run in CI.
 *
 *     php tools/smoke/shutterstock.php [path/to/.env] [search words]
 *
 * The consumer key and secret are read from SHUTTERSTOCK_API_KEY and
 * SHUTTERSTOCK_API_SECRET in the .env file (default
 * ~/Dev/gw-test-filament/.env) and are never printed. Only counts, IDs and
 * yes/no checks are printed; no answer is saved. Never commit anything it
 * shows.
 */

use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\GuzzleHttpClients;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Paid\Shutterstock;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\SearchQuery;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Testing\InMemoryLibraryTokens;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$envFile = $argv[1] ?? (getenv('HOME').'/Dev/gw-test-filament/.env');
$words = $argv[2] ?? 'pottery';

/** The value of NAME=value in a .env file: quotes taken off, nothing expanded. */
$read = function (string $file, string $name): string {
    foreach (is_readable($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
        if (preg_match('/^\s*(?:export\s+)?'.preg_quote($name, '/').'\s*=\s*(.*)$/', $line, $m)) {
            $value = trim($m[1]);

            if (preg_match('/^(["\'])(.*)\1$/', $value, $q)) {
                return $q[2];
            }

            return trim((string) preg_replace('/\s+#.*$/', '', $value));
        }
    }

    return '';
};

$key = $read($envFile, 'SHUTTERSTOCK_API_KEY');
$secret = $read($envFile, 'SHUTTERSTOCK_API_SECRET');

// Whatever is printed, the key and secret are taken out first.
$say = function (string $line) use ($key, $secret): void {
    echo str_replace(array_filter([$key, $secret, base64_encode($key.':'.$secret)]), '***', $line), "\n";
};

if ($key === '' || $secret === '') {
    $say("SHUTTERSTOCK_API_KEY and SHUTTERSTOCK_API_SECRET aren't both set in {$envFile}.");

    exit(2);
}

$library = new Shutterstock(new GuzzleHttpClients, $key, $secret, new InMemoryLibraryTokens, sandbox: true);
$ok = true;
$check = function (string $what, bool $passed) use ($say, &$ok): void {
    $say(($passed ? 'ok   ' : 'FAIL ').$what);
    $ok = $ok && $passed;
};

$say('Shutterstock sandbox: search for "'.$words.'"');

try {
    $photos = $library->search(new SearchQuery($words, perPage: 5));
    $check('search answered: '.count($photos).' photos', $photos !== []);

    foreach ($photos as $photo) {
        $check("photo {$photo->id}: https thumbnail, paid offer \"{$photo->offer()->label()}\", ".($photo->editorial ? 'editorial' : 'commercial'),
            str_starts_with($photo->thumb, 'https://') && ! $photo->isFree() && ! $photo->editorial && $photo->width !== null);
    }

    if ($photos !== []) {
        $first = $library->photo($photos[0]->id);
        $check("look-up of {$first->id} by ID", $first->id === $photos[0]->id);

        $preview = $library->preview($first->id);
        $check('preview is Shutterstock\'s own https address, not stored', ! $preview->isStored() && str_starts_with((string) $preview->url, 'https://'));
    }
} catch (Throwable $exception) {
    $check(get_class($exception).': '.$exception->getMessage(), false);
}

$say($ok ? 'Smoke test passed.' : 'Smoke test failed.');

exit($ok ? 0 : 1);
