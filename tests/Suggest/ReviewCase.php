<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use NineteenNinetyFour\Ghostwriter\Core\Studio\ContentKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Layout;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Studio;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewInput;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SiteDigest;

/** The Services page's review, as an addon would set it up. */
final class ReviewCase
{
    public const VOICE = "# Northfold's voice\n\n## Who we are\n\nGarden designers in Northumberland.\n\n## What this voice never does\n\nNo jargon (\"leverage\", \"solutions\", \"bespoke\"). No exclamation marks.\n\n## How it sounds\n\nPlain, warm and specific.";

    public static function writer(string $voice = self::VOICE): WriterContext
    {
        return new WriterContext(new ContentKind('page', 'Page', 'A page about what Northfold does.', 'Say what we do, for whom, and how to start.', ['Says who it is for.', 'Ends with how to get in touch.']), $voice, new Layout(''), '');
    }

    public static function input(?CheckContext $context = null, string $voice = self::VOICE, int $wordsPerCall = ReviewInput::WORDS_PER_CALL, bool $withImage = true): ReviewInput
    {
        $context ??= Northfold::context();
        $findings = Findings::standard()->find($context);
        $images = [];

        foreach ($findings as $finding) {
            if ($finding->kind === 'missing-alt' && $withImage) {
                $images[$finding->id] = new Image(base64_decode(FakeProvider::PNG), 'image/png');
            }
        }

        return new ReviewInput($context, self::writer($voice), $findings, SiteDigest::build($context, $findings), $images, 'en', 12, $wordsPerCall);
    }

    public static function studio(FakeProvider $fake): Studio
    {
        return new Studio($fake, new PromptLibrary(Vocabulary::statamic()));
    }

    /** The reply a good model gives for the Services page: the mockup's seven suggestions. */
    public static function reply(): string
    {
        return "<suggestions>\n".json_encode(['suggestions' => [
            ['finding' => 'f1', 'category' => 'out-of-date', 'unit' => 'u2', 'quote' => 'New for 2024', 'reason' => 'It says 2024 as if it were this year.', 'source' => ['kind' => 'finding'], 'replacement' => 'Every winter', 'alternatives' => ['From November to February', 'Each winter']],
            ['finding' => 'f2', 'category' => 'fact-to-check', 'unit' => 'u4', 'quote' => 'team of 6', 'reason' => 'The team may have changed since March 2024.', 'source' => ['kind' => 'finding'], 'fact' => ['ask' => 'Number of designers', 'template' => 'team of {answer}', 'without' => 'team', 'answer' => 'number']],
            ['finding' => 'f3', 'category' => 'clarity', 'unit' => 'u4', 'quote' => 'In terms of the actual process involved, what typically happens is that we will first of all come out and visit the garden in person, after which we will then go away and produce a concept.', 'reason' => 'Thirty-six words to say we visit, then draw.', 'source' => ['kind' => 'general'], 'replacement' => 'First we visit the garden, then we draw a concept.', 'alternatives' => ['We start with a visit, then produce a concept.']],
            ['finding' => 'f4', 'category' => 'link', 'unit' => 'u4', 'quote' => 'our 2023 show garden', 'reason' => 'The show garden page was deleted; the Corbridge garden is its follow-up.', 'source' => ['kind' => 'site-entry', 'entry' => 'e1'], 'replacement' => 'a walled garden we designed in Corbridge', 'link' => ['entry' => 'e1']],
            ['finding' => 'f5', 'category' => 'accessibility', 'unit' => 'i1', 'reason' => 'Describes what the photo shows.', 'source' => ['kind' => 'image'], 'replacement' => 'Image of stone, gravel and timber samples laid out on a workbench'],
            ['finding' => 'f6', 'category' => 'seo', 'unit' => 'u6', 'reason' => 'Over the limit by 35 characters.', 'source' => ['kind' => 'finding'], 'replacement' => 'From a planting plan to a full design and build, across Northumberland, Durham and the Tyne Valley.'],
            ['category' => 'voice', 'unit' => 'u3', 'quote' => 'We leverage our expertise to deliver bespoke garden solutions', 'reason' => 'Three words the guide rules out.', 'source' => ['kind' => 'voice-guide', 'heading' => 'What this voice never does'], 'replacement' => 'We design gardens and help them grow', 'alternatives' => ['Gardens designed, planted and looked after', 'We design and plant gardens']],
        ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n</suggestions>";
    }
}
