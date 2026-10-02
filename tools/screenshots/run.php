#!/usr/bin/env php
<?php

/**
 * Docs screenshots for the three Ghostwriter addons, taken from the local
 * test sites with headless Chrome. See README.md.
 *
 *   php tools/screenshots/run.php statamic|filament|craft [--only=id,id] [--no-store] [--keep] [--list]
 */

require __DIR__.'/lib/DevToolsSocket.php';
require __DIR__.'/lib/Chrome.php';
require __DIR__.'/lib/Browser.php';
require __DIR__.'/lib/State.php';
require __DIR__.'/lib/Frame.php';
require __DIR__.'/lib/Site.php';

$args = array_slice($argv, 1);
$cms = null;
$options = [];

foreach ($args as $arg) {
    if (str_starts_with($arg, '--')) {
        [$key, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, true);
        $options[$key] = $value;
    } else {
        $cms = $arg;
    }
}

$sites = ['statamic', 'filament', 'craft'];

if (! in_array($cms, $sites, true)) {
    fwrite(STDERR, "Usage: php tools/screenshots/run.php statamic|filament|craft [--only=id,id] [--no-store] [--keep] [--list]\n");
    exit(1);
}

// Sibling checkouts by default: ../gw-test-<cms> and ../ghostwriter-<cms> beside core.
$dev = dirname(__DIR__, 3);
$root = getenv('GW_SHOT_SITE') ?: "{$dev}/gw-test-{$cms}";
$addon = getenv('GW_SHOT_ADDON') ?: "{$dev}/ghostwriter-{$cms}";
$url = rtrim(getenv('GW_SHOT_URL') ?: "http://gw-test-{$cms}.test", '/');

// Only ever local development hosts: this signs in and rewrites state.
$host = (string) parse_url($url, PHP_URL_HOST);

if (! preg_match('/(^localhost$|^127\.0\.0\.1$|\.test$|\.localhost$)/', $host)) {
    fwrite(STDERR, "Refusing {$url}: the screenshot tool only runs against local .test or localhost sites.\n");
    exit(1);
}

foreach (['site' => $root, 'addon' => $addon] as $what => $path) {
    if (! is_dir($path)) {
        fwrite(STDERR, "No {$what} at {$path}; set GW_SHOT_".strtoupper($what).".\n");
        exit(1);
    }
}

require __DIR__."/sites/{$cms}.php";
$class = ucfirst($cms).'Site';
/** @var Site $site */
$site = new $class($root, $addon, $url);

$shots = $site->shots();
$only = isset($options['only']) ? explode(',', (string) $options['only']) : null;

if (isset($options['list'])) {
    foreach ($shots as $id => $shot) {
        echo $id.(($shot['scheme'] ?? 'light') === 'dark' ? ' (dark)' : '')."\n";
    }

    foreach ($site->store() as $id => $_) {
        echo "store/{$id}\n";
    }

    exit(0);
}

$state = $site->state();
$keep = isset($options['keep']);

if (isset($options['restore'])) {
    $state->restore();
    exit(0);
}

echo "Saving {$cms}'s state.\n";
$state->save();

$restored = false;
$restore = function () use ($state, $keep, &$restored): void {
    if ($restored) {
        return;
    }

    $restored = true;

    if ($keep) {
        echo 'Left the seeded state in place (--keep). Put it back with: php tools/screenshots/run.php '.$GLOBALS['cms']." --restore\n";

        return;
    }

    $state->restore();
};

register_shutdown_function($restore);

if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);

    foreach ([SIGINT, SIGTERM] as $signal) {
        pcntl_signal($signal, function () {
            echo "\nStopped.\n";
            exit(130);
        });
    }
}

$images = $addon.'/docs/images';
$store = $addon.'/docs/store';
$failures = [];

try {
    echo "Seeding.\n";
    $site->context = $site->seed();

    $browser = new Browser(Chrome::launch());
    $site->signIn($browser);
    echo "Signed in.\n";

    $take = function (array $shot, string $path, ?string $scheme = null) use ($browser, $site): void {
        $scheme ??= $shot['scheme'] ?? 'light';
        $browser->viewport(Browser::WIDTH, $shot['height'] ?? Browser::HEIGHT);
        $browser->scheme($scheme);

        $url = $shot['url'] instanceof Closure ? ($shot['url'])($site->context) : $shot['url'];
        $browser->go($site->absolute($url));
        $site->theme($browser, $scheme);
        $site->tidy($browser);

        if (isset($shot['steps'])) {
            ($shot['steps'])($browser, $site);
        }

        foreach ((array) ($shot['ready'] ?? []) as $ready) {
            str_starts_with($ready, 'css:') ? $browser->waitForSelector(substr($ready, 4)) : $browser->waitForText($ready);
        }

        $browser->settle($shot['wait'] ?? 0.6);
        $site->tidy($browser);

        if (! empty($shot['hide'])) {
            $browser->hide($shot['hide']);
        }

        $browser->capture($path, $shot['clip'] ?? null, $shot['pad'] ?? 0);
    };

    foreach ($shots as $id => $shot) {
        if ($only && ! in_array($id, $only, true)) {
            continue;
        }

        try {
            $take($shot, "{$images}/{$id}.png");
            echo "  {$id}\n";
        } catch (Throwable $e) {
            $failures[$id] = $e->getMessage();
            echo "  {$id}: FAILED: {$e->getMessage()}\n";
            $browser->capture($state->scratch()."/failed-{$id}.png");
        }
    }

    if (! isset($options['no-store'])) {
        $frame = new Frame($browser, $state->scratch());

        foreach ($site->store() as $id => [$shot, $headline, $line]) {
            if ($only && ! in_array("store/{$id}", $only, true) && ! in_array('store', $only, true)) {
                continue;
            }

            try {
                $shot = is_string($shot) ? $shots[$shot] : $shot;
                unset($shot['clip']);
                $raw = $state->scratch()."/store-{$id}.png";
                $take($shot + ['height' => Browser::HEIGHT], $raw, 'light');
                $frame->make($raw, "{$store}/{$id}.png", $site->name(), $headline, $line);
                echo "  store/{$id}\n";
            } catch (Throwable $e) {
                $failures["store/{$id}"] = $e->getMessage();
                echo "  store/{$id}: FAILED: {$e->getMessage()}\n";
            }
        }
    }
} finally {
    $restore();
}

if ($failures) {
    echo count($failures)." shot(s) failed. Screens at the moment of failure are in {$state->scratch()}.\n";
    exit(1);
}

echo "Done: {$images}".(isset($options['no-store']) ? '' : " and {$store}").".\n";
