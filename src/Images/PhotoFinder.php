<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\NotConfigured;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Providers;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextProvider;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use Psr\Log\LoggerInterface;

/**
 * Finds photographs for one place on a page, the whole way through:
 *
 * 1. Searches: the ones given (typed by a person), or three chosen by the
 *    `photo-researcher` agent from the page's words. Without a model, the
 *    page's title.
 * 2. Each search is run (StockSearch), up to PER_TERM results each.
 * 3. A model judges them (PhotoRanker): style and subject against the
 *    reference images when there are any, subject alone when not. Clear
 *    misses are left out.
 * 4. If it finds none that belong, it names better searches; they are run
 *    and judged once more. If still none belong, the unranked results come
 *    back with `noneFit` set, so the person can be told.
 *
 * An addon supplies the words (PhotoContext), the images already in that
 * place (bytes, optional), and stores whichever photo is chosen, after
 * StockSearch::fetch().
 *
 *     $finder = new PhotoFinder($stock, $providers, $prompts, $logger);
 *     $results = $finder->find(PhotoContext::make($title, $label, $blockText, $pageText, shape: 'landscape'), $referenceBytes);
 *     foreach ($results as $photo) { $photo->picked; $photo->alt(); ... }
 *     $file = $stock->fetch($source, $id);   // when one is chosen
 *     $file->photo->filenameBase().'.'.$file->extension;
 */
final class PhotoFinder
{
    /** Results kept from each search. */
    public const PER_TERM = 6;

    /** The most searches run in one round. */
    public const MAX_TERMS = 3;

    private readonly PhotoRanker $ranker;

    /**
     * @param  Providers|TextProvider|null  $model  The registry (its text model is used when it has a key), a provider, or null to search without a model.
     */
    public function __construct(
        private readonly StockSearch $stock,
        private readonly Providers|TextProvider|null $model,
        private readonly PromptLibrary $prompts,
        ?LoggerInterface $logger = null,
        ?PhotoRanker $ranker = null,
    ) {
        $this->ranker = $ranker ?? new PhotoRanker($stock, $model, $prompts, $logger);
    }

    public function stock(): StockSearch
    {
        return $this->stock;
    }

    public function ranker(): PhotoRanker
    {
        return $this->ranker;
    }

    /** Whether any photo library can be searched. */
    public function canFind(): bool
    {
        return $this->stock->sources() !== [];
    }

    /**
     * @param  array<int, Image|string>  $references  Images, or their bytes, already in that place; none is fine.
     * @param  array<int, string>|string|null  $terms  Searches to run (a list, or text separated by semicolons); null to have them chosen.
     *
     * @throws ProviderException when the searches are to be chosen and the model call fails.
     */
    public function find(PhotoContext $context, array $references = [], array|string|null $terms = null): PhotoResults
    {
        $terms = $terms === null ? $this->searchTerms($context) : self::terms(is_array($terms) ? implode(';', $terms) : $terms);
        $candidates = $this->candidates($terms, $context);
        $ranking = $this->ranker->rank($candidates, $context, $references);

        if (! $ranking->judged) {
            return new PhotoResults($ranking->photos, $terms, withReferences: false);
        }

        if (! $ranking->noneFit) {
            return new PhotoResults($ranking->photos, $terms, judged: true, withReferences: $ranking->withReferences);
        }

        // Nothing belongs. One more round with the model's own searches,
        // then settle for what there is, saying so.
        $retry = array_values(array_diff($ranking->retryTerms, $terms));
        $second = array_values(array_filter(
            $this->candidates($retry, $context),
            fn (Photo $photo) => ! in_array($photo->key(), array_map(fn (Photo $first) => $first->key(), $candidates), true),
        ));

        if ($second === []) {
            return new PhotoResults($ranking->photos, [...$terms, ...$retry], noneFit: true, retried: $retry !== [], withReferences: $ranking->withReferences);
        }

        $again = $this->ranker->rank($second, $context, $references, mayRetry: false);
        $searched = [...$terms, ...$retry];

        if ($again->judged && ! $again->noneFit) {
            return new PhotoResults($again->photos, $searched, judged: true, retried: true, withReferences: $again->withReferences);
        }

        return new PhotoResults(PhotoRanker::unranked([...$second, ...$candidates]), $searched, noneFit: true, retried: true, withReferences: $ranking->withReferences);
    }

    /**
     * Three searches for a photograph that suits the place, chosen by the
     * model from the words around it. Without a model, or if it answers
     * with nothing usable, the page's title.
     *
     * @return array<int, string>
     *
     * @throws ProviderException when the model call fails.
     */
    public function searchTerms(PhotoContext $context): array
    {
        $fallback = self::terms($context->title);
        $model = $this->textProvider();

        if ($model === null) {
            return $fallback;
        }

        $prompt = "Page title: {$context->title}\n\nThe picture goes in: {$context->label}\n\n"
            .($context->summary !== '' ? "Summary: {$context->summary}\n\n" : '')
            .($context->blockText !== '' ? "Words in that part of the page:\n\n{$context->blockText}\n\n" : '')
            ."The whole page:\n\n".($context->pageText !== '' ? $context->pageText : '(nothing written yet)')
            .($context->style !== '' ? "\n\nThe site's own description of its images in this section:\n\n{$context->style}" : '');

        $answer = $model->text(new TextRequest('photo-researcher', $this->prompts->get('photo-researcher'), $prompt, timeout: PhotoRanker::TIMEOUT))->text;

        return self::terms($answer) ?: $fallback;
    }

    /**
     * Searches from a model's reply or from what a person typed: up to
     * MAX_TERMS, separated by semicolons or new lines, lower case, letters,
     * numbers, spaces, hyphens and apostrophes only.
     *
     * @return array<int, string>
     */
    public static function terms(string $text): array
    {
        $terms = array_map(fn (string $term) => trim((string) preg_replace('/[^\p{L}\p{N} \'-]+/u', ' ', $term), " \t-'"), preg_split('/[;\n]+/u', $text) ?: []);
        $terms = array_values(array_unique(array_filter(array_map(fn (string $term) => mb_strtolower((string) preg_replace('/\s+/u', ' ', $term)), $terms))));

        return array_slice($terms, 0, self::MAX_TERMS);
    }

    /**
     * Each search run, PER_TERM results kept from each, the same photo
     * found twice kept once (with the first search that found it).
     *
     * @param  array<int, string>  $terms
     * @return array<int, Photo>
     */
    public function candidates(array $terms, PhotoContext $context): array
    {
        $candidates = [];

        foreach (array_slice($terms, 0, self::MAX_TERMS) as $term) {
            foreach (array_slice($this->stock->search($term, $context->shape), 0, self::PER_TERM) as $photo) {
                $candidates[$photo->key()] ??= $photo;
            }
        }

        return array_values($candidates);
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
