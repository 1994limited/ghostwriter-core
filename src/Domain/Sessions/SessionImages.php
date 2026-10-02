<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;

/**
 * The images chosen for a draft's image fields, kept on the session by
 * field key (Statamic's "images under a draft"; D6 brings it to the
 * others). Each field's entry moves through:
 *
 *     (empty) → working → done | failed       a picture being made
 *     (empty) → done                          a photograph chosen, or copied from another field
 *
 * A field keeps the photographs it was offered (`query`, `options`,
 * `judged`, `none_fit`, `with_references`) after one is chosen, in case of
 * a change of mind.
 *
 * Images may be chosen while a turn runs (F2): make the change with
 * SessionGuard::change(), under the session's lock, and have the turn's
 * job save its own image changes with mergeTurn(), which keeps a choice
 * made meanwhile. Hand edits to the draft are different: they go through
 * SessionGuard::edit(), which refuses them while a turn runs (F3).
 */
final class SessionImages
{
    public const EMPTY = 'empty';

    public const WORKING = 'working';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** What a field keeps of the photographs it was offered, once one is chosen. */
    public const OFFERED = ['query' => 1, 'options' => 1, 'judged' => 1, 'none_fit' => 1, 'with_references' => 1];

    public static function status(Session $session, string $key): string
    {
        $status = $session->images[$key]['status'] ?? null;

        return is_string($status) ? $status : self::EMPTY;
    }

    /**
     * A picture starts being made for the field.
     *
     * @throws Conflict when one already is
     */
    public static function startMaking(Session $session, string $key): void
    {
        if (self::status($session, $key) === self::WORKING) {
            throw new Conflict('That image is already being made.');
        }

        $session->images[$key] = ['status' => self::WORKING, 'error' => null] + ($session->images[$key] ?? []);
    }

    /**
     * The picture made for the field, or why it couldn't be.
     *
     * @param  array<string, mixed>  $made  Where it is kept: `path`, `url`.
     */
    public static function made(Session $session, string $key, array $made): void
    {
        $session->images[$key] = ['status' => self::DONE, 'error' => null] + $made + ($session->images[$key] ?? []);
    }

    public static function failed(Session $session, string $key, string $error): void
    {
        $session->images[$key] = ['status' => self::FAILED, 'error' => $error] + ($session->images[$key] ?? []);
    }

    /**
     * Photographs offered for the field (a search's results), before one is chosen.
     *
     * @param  array<string, mixed>  $offered  `query`, `options`, `judged`, `none_fit`, `with_references`.
     */
    public static function offer(Session $session, string $key, array $offered): void
    {
        $session->images[$key] = array_intersect_key($offered, self::OFFERED) + ($session->images[$key] ?? []);
    }

    /**
     * A photograph chosen for the field, now kept as an asset.
     */
    public static function choose(Session $session, string $key, string $path, ?string $url, ?string $credit = null): void
    {
        $session->images[$key] = ['status' => self::DONE, 'path' => $path, 'url' => $url, 'error' => null, 'credit' => $credit]
            + array_intersect_key($session->images[$key] ?? [], self::OFFERED);
    }

    /**
     * The image already chosen for one field used in another as well, as
     * sites often do with a hero image and a thumbnail.
     *
     * @throws Conflict when that field has no image yet
     */
    public static function copy(Session $session, string $to, string $from): void
    {
        $source = $session->images[$from] ?? [];

        if (($source['status'] ?? null) !== self::DONE || empty($source['path'])) {
            throw new Conflict('That field has no image yet.');
        }

        $session->images[$to] = ['status' => self::DONE, 'error' => null]
            + array_intersect_key($source, ['path' => 1, 'url' => 1, 'credit' => 1])
            + array_intersect_key($session->images[$to] ?? [], self::OFFERED);
    }

    /**
     * A turn's image changes, laid over the session as it stands now. The
     * draft and conversation wait for the turn, but images can be chosen
     * while it runs: those choices are kept, and the turn's own changes
     * land only on fields nobody touched since it began.
     *
     * @param  array<string, array<string, mixed>>  $turn  The images as the turn left them.
     * @param  array<string, array<string, mixed>>  $before  The images as the turn found them.
     */
    public static function mergeTurn(Session $latest, array $turn, array $before): void
    {
        foreach (array_keys($turn + $before) as $key) {
            $changedByTurn = ($turn[$key] ?? null) !== ($before[$key] ?? null);
            $changedSince = ($latest->images[$key] ?? null) !== ($before[$key] ?? null);

            if (! $changedByTurn || $changedSince) {
                continue;
            }

            if (array_key_exists($key, $turn)) {
                $latest->images[$key] = $turn[$key];
            } else {
                unset($latest->images[$key]);
            }
        }
    }
}
