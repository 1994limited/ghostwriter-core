<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * The one publish guard for "Finish this page" and stock photos: one
 * finder run, one message. It refuses (or, in warn mode, warns about) a
 * page going live while any gap that blocks remains: a fact to add, a link
 * to choose or to a page that's gone, an image placeholder, a leftover
 * template token, or a stock photo previewed but not licensed (the
 * UnlicensedStock detector asks the ledger).
 *
 *     $readiness = PublishReadiness::standard(OnPublish::fromConfig(config('ghostwriter.publish.on_unfinished'), config('ghostwriter.stock.on_publish')))
 *         ->check($context);
 *     if ($readiness->blocked()) { throw ValidationException::withMessages($readiness->byField(fn ($m) => __($m->key, $m->params))); }
 *
 * Each addon calls it from its own hook, and only when the entry is going
 * live (Statamic `EntrySaving` for a published or scheduled entry; Craft
 * `Entry::EVENT_BEFORE_SAVE` on a canonical entry in the live scenario;
 * Filament the trait's save hook with `publishedWhen`, else warn only).
 * Nothing it does calls a model.
 */
final class PublishReadiness
{
    private readonly GapFinder $finder;

    public function __construct(?GapFinder $finder = null, private readonly OnPublish $mode = OnPublish::Block)
    {
        $this->finder = $finder ?? GapFinder::standard();
    }

    public static function standard(OnPublish $mode = OnPublish::Block): self
    {
        return new self(GapFinder::standard(), $mode);
    }

    public function mode(): OnPublish
    {
        return $this->mode;
    }

    public function check(GapContext $context): Readiness
    {
        $report = $this->finder->find($context);

        return new Readiness($report->blocking(), $this->mode, $report);
    }
}
