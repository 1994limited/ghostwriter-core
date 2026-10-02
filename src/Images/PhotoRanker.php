<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Limits;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Has a model judge photo candidates for one place, through the
 * `photo-picker` agent. The model sees each candidate's thumbnail, the
 * search that found it and what its library says it shows, with the words
 * of the page.
 *
 * - With reference images (the pictures already in that place on other
 *   pages), it matches their style as well as the subject.
 * - Without, it judges the subject alone: which photos belong with these
 *   words.
 *
 * Either way it leaves clear misses out, and may say none fit, suggesting
 * better searches (Ranking::$retryTerms). Up to MAX_IMAGES images go in
 * one request (Ai\Limits), references first; candidates that don't fit, or
 * whose thumbnails won't load, aren't shown and are left out of a judged
 * list.
 *
 * Without a model, or if the call fails, the candidates come back unranked
 * (the top result of each search first) and none is picked.
 */
final class PhotoRanker
{
    /** How many judged photos are picked for the shortlist. */
    public const SHORTLIST = 3;

    /** How many reference images are shown. */
    public const REFERENCES = 3;

    /** Seconds the model may take. */
    public const TIMEOUT = 60;

    /** How much of the page's own words the model is shown. */
    private const TEXT_LENGTH = 1500;

    private readonly LoggerInterface $logger;

    private readonly Shrinker $shrinker;

    /**
     * @param  Providers|TextProvider|null  $model  The registry (its text model is used when it has a key), a provider, or null for no judging.
     */
    public function __construct(
        private readonly StockSearch $stock,
        private readonly Providers|TextProvider|null $model,
        private readonly PromptLibrary $prompts,
        ?LoggerInterface $logger = null,
        ?Shrinker $shrinker = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
        $this->shrinker = $shrinker ?? new Shrinker;
    }

    /** Whether there is a model to judge with. */
    public function canJudge(): bool
    {
        return $this->textProvider() !== null;
    }

    /**
     * @param  array<int, Photo>  $candidates
     * @param  array<int, Image|string>  $references  Images, or their bytes, already in that place; at most REFERENCES are shown.
     * @param  bool  $mayRetry  Ask for better searches if none fit. False for a second round.
     */
    public function rank(array $candidates, PhotoContext $context, array $references = [], bool $mayRetry = true): Ranking
    {
        $candidates = array_values($candidates);
        $unjudged = new Ranking(self::unranked($candidates), false);
        $model = $this->textProvider();

        if ($candidates === [] || $model === null) {
            return $unjudged;
        }

        try {
            $shown = $this->references($references);
            $thumbs = $this->stock->thumbnails($candidates);
            $attachments = $shown;
            $seen = [];

            foreach ($candidates as $i => $photo) {
                $small = $thumbs[$i] !== null ? $this->shrinker->small($thumbs[$i]) : null;

                if ($small !== null && Limits::fits([...$attachments, $small])) {
                    $attachments[] = $small;
                    $seen[] = $photo;
                }
            }

            if ($seen === []) {
                return $unjudged;
            }

            $response = $model->text(new TextRequest(
                agent: 'photo-picker',
                instructions: $this->prompts->get('photo-picker'),
                prompt: $this->prompt($context, $seen, count($shown), $mayRetry),
                images: $attachments,
                timeout: self::TIMEOUT,
            ));

            return $this->read($response->text, $seen, $candidates, $shown !== [], $mayRetry) ?? $unjudged;
        } catch (Throwable $exception) {
            $this->logger->warning("Ghostwriter: judging photographs failed: {$exception->getMessage()}");

            return $unjudged;
        }
    }

    /**
     * Without a judge, the top result of each search is as fair as any: one
     * from each search in turn for the first SHORTLIST, then the rest in the
     * order found. None is picked.
     *
     * @param  array<int, Photo>  $candidates
     * @return array<int, Photo>
     */
    public static function unranked(array $candidates): array
    {
        $byTerm = [];

        foreach ($candidates as $photo) {
            $byTerm[$photo->term][] = $photo;
        }

        $first = [];

        for ($i = 0; count($first) < self::SHORTLIST && $byTerm !== [] && $i < max(array_map('count', $byTerm)); $i++) {
            foreach ($byTerm as $photos) {
                if (isset($photos[$i]) && count($first) < self::SHORTLIST) {
                    $first[$photos[$i]->key()] = $photos[$i];
                }
            }
        }

        $rest = array_filter($candidates, fn (Photo $photo) => ! isset($first[$photo->key()]));

        return array_map(fn (Photo $photo) => $photo->unjudged(), [...array_values($first), ...array_values($rest)]);
    }

    /**
     * Read the model's answer: "none: a; b; c", or one line per photo that
     * belongs ("4: why"), or a plain list of numbers ("4, 11, 2", as older
     * site overrides of the prompt ask for). Null when it can't be read.
     *
     * @param  array<int, Photo>  $seen  The candidates the model was shown, in the order numbered.
     * @param  array<int, Photo>  $candidates
     */
    private function read(string $answer, array $seen, array $candidates, bool $withReferences, bool $mayRetry): ?Ranking
    {
        $answer = trim($answer, " \t\n\r`*");

        if (preg_match('/^none\b[\s:.\-–—]*(.*)$/isu', $answer, $none)) {
            return new Ranking(self::unranked($candidates), true, true, $mayRetry ? PhotoFinder::terms($none[1]) : [], $withReferences);
        }

        /** @var array<int, string|null> $reasons By number, best first. */
        $reasons = [];

        foreach (preg_split('/\R/u', $answer) ?: [] as $line) {
            $line = trim($line);

            if (preg_match('/^[#\s]*\d+(?:\s*[,;]\s*#?\d+)*[\s.]*$/u', $line)) {
                preg_match_all('/\d+/', $line, $numbers);

                foreach ($numbers[0] as $number) {
                    $reasons[(int) $number] ??= null;
                }
            } elseif (preg_match('/^(?:[-*•]\s*)?#?(\d+)\s*[:.)\-–—]\s*(.*)$/u', $line, $match)) {
                $reasons[(int) $match[1]] ??= trim($match[2], " \t*\"'");
            }
        }

        $fits = [];

        foreach ($reasons as $number => $reason) {
            if (isset($seen[$number - 1])) {
                $fits[] = [$seen[$number - 1], $reason];
            }
        }

        if ($fits === []) {
            $this->logger->warning('Ghostwriter: the photo picker\'s answer could not be read: '.Slug::clip($answer, 200));

            return null;
        }

        // The best from each search first, so the shortlist differs; then
        // the rest in the model's order.
        $first = [];
        $terms = [];

        foreach ($fits as $i => [$photo]) {
            if (count($first) < self::SHORTLIST && ! isset($terms[$photo->term])) {
                $terms[$photo->term] = true;
                $first[$i] = $fits[$i];
            }
        }

        foreach ($fits as $i => $fit) {
            if (count($first) >= self::SHORTLIST) {
                break;
            }

            $first[$i] ??= $fit;
        }

        $ordered = [...array_values($first), ...array_values(array_diff_key($fits, $first))];
        $photos = array_map(fn (array $fit, int $i) => $fit[0]->judged($i < self::SHORTLIST, $fit[1]), $ordered, array_keys($ordered));

        return new Ranking($photos, true, false, [], $withReferences);
    }

    /**
     * @param  array<int, Photo>  $seen
     */
    private function prompt(PhotoContext $context, array $seen, int $references, bool $mayRetry): string
    {
        $parts = ['The page is titled "'.Slug::clip($context->title, 200).'"'.($context->label !== '' ? ' and the picture goes in '.Slug::clip($context->label, 120) : '').'.'];

        if ($context->summary !== '') {
            $parts[] = 'Summary: '.Slug::clip($context->summary, 400);
        }

        if ($context->blockText !== '') {
            $parts[] = "Words in that part of the page:\n".$this->excerpt($context->blockText);
        } elseif ($context->pageText !== '') {
            $parts[] = "The page says:\n".$this->excerpt($context->pageText);
        }

        $list = implode("\n", array_map(function (Photo $photo, int $i) {
            $summary = $photo->summary();

            return ($i + 1).'. from the search "'.$photo->term.'"'.($summary !== '' ? '; the library describes it as: '.$summary : '');
        }, $seen, array_keys($seen)));

        $parts[] = $references > 0
            ? "The first {$references} image(s) are the references: the pictures already used in this place on other pages. The ".count($seen)." after them are the candidates, in this order:\n{$list}"
            : 'There are no reference images. The '.count($seen)." images are the candidates, in this order:\n{$list}";

        if ($context->style !== '') {
            $parts[] = "The site's own description of its images in this section:\n".$this->excerpt($context->style);
        }

        $parts[] = ($references > 0
            ? 'Choose by style and subject: list each candidate that would sit well beside the references and suits the page, best first.'
            : 'Choose by subject alone: list each candidate that belongs with these words, best first.')
            .($mayRetry
                ? ' If none of them belongs, reply with none, a colon and three better searches.'
                : ' These come from a second round of searches, after nothing in the first belonged. If none of these belongs either, reply with only the word none.');

        return implode("\n\n", $parts);
    }

    /**
     * @param  array<int, Image|string>  $references
     * @return array<int, Image>
     */
    private function references(array $references): array
    {
        $shown = [];

        foreach ($references as $reference) {
            if (count($shown) >= self::REFERENCES) {
                break;
            }

            $small = $this->shrinker->small($reference instanceof Image ? $reference->data : $reference);

            if ($small !== null && Limits::fits([...$shown, $small])) {
                $shown[] = $small;
            }
        }

        return $shown;
    }

    private function excerpt(string $text): string
    {
        $text = trim((string) preg_replace("/[ \t]+/u", ' ', $text));

        return mb_strlen($text) > self::TEXT_LENGTH ? rtrim(mb_substr($text, 0, self::TEXT_LENGTH)).'…' : $text;
    }

    private function textProvider(): ?TextProvider
    {
        if (! $this->model instanceof Providers) {
            return $this->model;
        }

        try {
            return $this->model->configured() ? $this->model->text() : null;
        } catch (NotConfigured) {
            return null;
        }
    }
}
