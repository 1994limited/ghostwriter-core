<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Revisit;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\Sleeper;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Http\SystemSleeper;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The opt-in weekly check of links to other sites. Off by default
 * (RevisitOptions::$externalLinks); with it off, run() does nothing and
 * makes no request.
 *
 * - Each address is checked at most once a week (EVERY days), however
 *   many pages link to it, and at most LIMIT addresses a run; the rest
 *   wait for the next.
 * - **Politeness:** one request at a time, at least PER_HOST seconds
 *   between two requests to the same host, a TIMEOUT per request, and a
 *   User-Agent that says what it is (HttpLinkProbe).
 * - A link is shown as broken only after two Broken checks in a row
 *   (LinkResult::FAILURES); timeouts, refusals and server errors are
 *   Unknown and never shown.
 * - Results go into each linking row's `external`, and the row is
 *   re-scored, so they show in the list at once and as Link findings in
 *   the next review (CheckContext::$external).
 *
 * Schedule it weekly beside the daily index (Statamic and Filament's
 * scheduler, Craft's cron command).
 */
final class ExternalLinkCheck
{
    public const EVERY = 7;

    public const PER_HOST = 1.0;

    public const TIMEOUT = 10;

    public const LIMIT = 500;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly LinkProbe $probe,
        private readonly RevisitScanner $scanner,
        private readonly Sleeper $sleeper = new SystemSleeper,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /**
     * Checks the links due, writes the results into the rows, and returns
     * how many addresses were checked.
     */
    public function run(RevisitStore $store, RevisitOptions $options, DateTimeImmutable $now, int|string|null $site = null): int
    {
        if (! $options->externalLinks) {
            return 0;
        }

        $due = [];
        $rows = [];

        foreach ($store->all($site) as $row) {
            $rows[] = $row;

            foreach ($row->external as $url => $result) {
                if ($result->due($now, self::EVERY)) {
                    $due[$url] = $result;
                }
            }
        }

        $checked = $this->check(array_slice($due, 0, self::LIMIT, true));

        if ($checked === []) {
            return 0;
        }

        foreach ($rows as $row) {
            $external = $row->external;
            $changed = false;

            foreach ($external as $url => $result) {
                if (isset($checked[$url])) {
                    $external[$url] = $checked[$url]->after($result);
                    $changed = true;
                }
            }

            if ($changed) {
                $store->put($this->scanner->rescore($row->with(external: $external), $now));
            }
        }

        $broken = count(array_filter($checked, fn (LinkResult $result) => $result->status === LinkStatus::Broken));
        $this->logger->info('Ghostwriter: checked '.count($checked).' links to other sites; '.$broken.' not found.');

        return count($checked);
    }

    /**
     * Each address once, round-robin across hosts so no host is asked
     * twice within PER_HOST seconds.
     *
     * @param  array<string, LinkResult>  $due
     * @return array<string, LinkResult>
     */
    private function check(array $due): array
    {
        $byHost = [];

        foreach (array_keys($due) as $url) {
            $byHost[Links::host((string) parse_url($url, PHP_URL_HOST))][] = $url;
        }

        $results = [];
        $last = [];
        $clock = 0.0;

        while ($byHost !== []) {
            foreach ($byHost as $host => $urls) {
                $wait = isset($last[$host]) ? self::PER_HOST - ($clock - $last[$host]) : 0.0;

                if ($wait > 0) {
                    $this->sleeper->sleep($wait);
                    $clock += $wait;
                }

                $url = array_shift($urls);
                $started = microtime(true);
                $results[$url] = $this->probe->probe($url, self::TIMEOUT);
                $clock += microtime(true) - $started;
                $last[$host] = $clock;

                if ($urls === []) {
                    unset($byHost[$host]);
                } else {
                    $byHost[$host] = $urls;
                }
            }
        }

        return $results;
    }
}
