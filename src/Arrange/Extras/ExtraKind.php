<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras;

/**
 * The optional pieces the writer may prepare with a draft, beside it rather
 * than in it, for block types the site has. A layout may use them; an
 * extra no layout uses never reaches the entry.
 */
enum ExtraKind: string
{
    /** Numbers worth pulling out: "4 visits a winter". */
    case Stats = 'stats';

    /** Questions and their answers. */
    case Faq = 'faq';

    /** A sentence worth setting large, from the draft or the brief. */
    case PullQuote = 'pull_quote';

    /** A few short points: what the page comes down to. */
    case AtAGlance = 'at_a_glance';

    /** A caption for an image. */
    case Caption = 'caption';

    /** A second call to action. */
    case Cta = 'cta';

    /** What a client said, in their words. */
    case Testimonial = 'testimonial';

    /** A short intro or standfirst. */
    case Intro = 'intro';

    /** What the writer is told an extra of this kind is. */
    public function describe(): string
    {
        return match ($this) {
            self::Stats => 'numbers worth pulling out; each item has `text` ("4 visits a winter"), and may split it into `value` ("4") and `label` ("visits a winter")',
            self::Faq => 'questions a reader would ask; each item has `question` and `text`, the answer',
            self::PullQuote => 'one sentence worth setting large, quoted exactly; `text`, and `attribution` when someone said it',
            self::AtAGlance => 'a few short points the page comes down to; one `text` per item',
            self::Caption => 'a caption for an image; `text`, and `for`, the image field it belongs to',
            self::Cta => 'a second call to action; `text`, and `button`, its label',
            self::Testimonial => 'what a client said, in their own words; `text` and `attribution`',
            self::Intro => 'a short intro or standfirst; one `text`',
        };
    }

    /**
     * The named parts an item of this kind may have besides its text.
     *
     * @return list<string>
     */
    public function parts(): array
    {
        return match ($this) {
            self::Stats => ['value', 'label'],
            self::Faq => ['question'],
            self::PullQuote, self::Testimonial => ['attribution'],
            self::Caption => ['for'],
            self::Cta => ['button'],
            self::AtAGlance, self::Intro => [],
        };
    }

    /**
     * The parts that say where something goes rather than state anything,
     * so they are never checked for facts.
     *
     * @return list<string>
     */
    public static function addresses(): array
    {
        return ['for'];
    }
}
