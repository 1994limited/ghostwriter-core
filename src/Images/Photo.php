<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Images;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Offer;
use NineteenNinetyFour\Ghostwriter\Core\Seo\FilenameRules;
use NineteenNinetyFour\Ghostwriter\Core\Text\Slug;

/**
 * One photograph found in a photo library: where it is from, how to credit
 * it, what the library says it shows, which search found it and whether a
 * model picked it.
 *
 * The library's own words are kept as they came: `title` (Openverse's
 * title, an Unsplash caption, the words in a Pexels address),
 * `description` (Unsplash's and Pexels' alt text) and `tags` (Pixabay,
 * Openverse). alt(), assetTitle() and filenameBase() turn them into what a
 * CMS needs, falling back to the search term.
 *
 * `picked` is true only when a model compared the photo with the page (and
 * the references, if any) and put it in the shortlist; `reason` is what it
 * said. Unranked photos are never picked, so a "Best match" badge can
 * follow `picked` alone.
 *
 * `url` is the full-size address the library gave with the search result,
 * for information. StockSearch::fetch() looks the photo up again by
 * `source` and `id`, so nothing a browser sends back is downloaded.
 *
 * A paid library's photos also say how they can be had (`offer`: a price
 * hint and licence type), whether they are for editorial use only
 * (`editorial`, with the library's own `restrictions`), and which of its
 * collections they are from. A free library's photos leave these unset:
 * no offer means free.
 */
final class Photo
{
    /** Alt text longer than this is cut at a word: screen readers read all of it. */
    public const ALT_LENGTH = 125;

    /**
     * @param  array<int, string>  $tags
     */
    public function __construct(
        public readonly string $source,
        public readonly string $id,
        public readonly string $thumb,
        public readonly string $credit,
        public readonly ?string $creditUrl,
        public readonly string $licence,
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly array $tags = [],
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?string $url = null,
        public readonly string $term = '',
        public readonly bool $picked = false,
        public readonly ?string $reason = null,
        public readonly ?Offer $offer = null,
        public readonly bool $editorial = false,
        public readonly ?string $restrictions = null,
        public readonly ?string $collection = null,
    ) {}

    /** Unique across libraries: "unsplash:Ab3dE". */
    public function key(): string
    {
        return $this->source.':'.$this->id;
    }

    /** How it can be had: its offer, or free when it has none. */
    public function offer(): Offer
    {
        return $this->offer ?? Offer::free();
    }

    public function isFree(): bool
    {
        return $this->offer === null || $this->offer->free;
    }

    public function withTerm(string $term): self
    {
        return $this->copy($term, $this->picked, $this->reason);
    }

    /** A copy marked as judged by a model: picked for the shortlist or not, and why it fits. */
    public function judged(bool $picked, ?string $reason = null): self
    {
        return $this->copy($this->term, $picked, $reason !== null && trim($reason) !== '' ? Slug::clip($reason, 200) : null);
    }

    /** A copy with no judgement on it. */
    public function unjudged(): self
    {
        return $this->copy($this->term, false, null);
    }

    /**
     * Alt text: the library's description, else its title, else its first
     * tags, else $fallback or the search term. One line, at most
     * ALT_LENGTH characters, starting with a capital.
     */
    public function alt(?string $fallback = null): string
    {
        $text = $this->firstOf($this->description, $this->title, $this->tagList(), $fallback, $this->term);

        return Slug::upperFirst(Slug::clip($text, self::ALT_LENGTH));
    }

    /**
     * A title for the saved asset: the library's title, else its
     * description, else its first tags, else $fallback or the search term.
     * One line, at most $max characters, starting with a capital, without a
     * full stop.
     */
    public function assetTitle(?string $fallback = null, int $max = 80): string
    {
        $text = $this->firstOf($this->title, $this->description, $this->tagList(), $fallback, $this->term);

        return rtrim(Slug::upperFirst(Slug::clip($text, $max)), '.') ?: 'Photo';
    }

    /**
     * A file name without its extension, descriptive for search (SEO layer
     * §11, Seo\FilenameRules): from the alt text the image is given ($alt,
     * else alt()'s own: the library's description), then the library's
     * title, its first tags, $fallback and the search term, the first that
     * leaves two words once library noise ("stock photo", "royalty free")
     * and stop words are out: "walled-garden-winter-frost". At most $max
     * characters (and 50). Where none does, the first of them as a plain
     * slug, else "photo". Add your own suffix if names must be unique.
     */
    public function filenameBase(?string $fallback = null, int $max = 60, ?string $alt = null, string $language = 'en'): string
    {
        $sources = [$alt, $this->description, $this->title, $this->tagList(3), $fallback, $this->term];
        $name = FilenameRules::first($sources, $language, $max);

        if ($name !== '') {
            return $name;
        }

        foreach ($sources as $text) {
            $slug = $text === null ? '' : Slug::make($text, $max);

            if ($slug !== '') {
                return $slug;
            }
        }

        return 'photo';
    }

    /** What the library says the photo shows, for a model to read; empty when it says nothing. */
    public function summary(): string
    {
        $parts = array_values(array_unique(array_filter([
            $this->description !== null ? Slug::clip($this->description, 160) : '',
            $this->title !== null ? Slug::clip($this->title, 120) : '',
            $this->tags !== [] ? 'tags: '.$this->tagList(8) : '',
        ])));

        return implode('; ', $parts);
    }

    /**
     * As an array for JSON, a session or a queue job: the keys the addons'
     * photo lists already used (credit_url and so on), plus the library's
     * words and the helpers' results. `offer`, `editorial`, `restrictions`
     * and `collection` are added only when set, so a free photo's array is
     * as it always was.
     *
     * @return array{source: string, id: string, thumb: string, credit: string, credit_url: ?string, licence: string, title: ?string, description: ?string, tags: array<int, string>, width: ?int, height: ?int, url: ?string, term: string, picked: bool, reason: ?string, alt: string, asset_title: string, offer?: array<string, mixed>, editorial?: true, restrictions?: string, collection?: string}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'id' => $this->id,
            'thumb' => $this->thumb,
            'credit' => $this->credit,
            'credit_url' => $this->creditUrl,
            'licence' => $this->licence,
            'title' => $this->title,
            'description' => $this->description,
            'tags' => $this->tags,
            'width' => $this->width,
            'height' => $this->height,
            'url' => $this->url,
            'term' => $this->term,
            'picked' => $this->picked,
            'reason' => $this->reason,
            'alt' => $this->alt(),
            'asset_title' => $this->assetTitle(),
        ] + array_filter([
            'offer' => $this->offer?->toArray(),
            'editorial' => $this->editorial ?: null,
            'restrictions' => $this->restrictions,
            'collection' => $this->collection,
        ], fn ($value) => $value !== null);
    }

    /**
     * Back from toArray(), or from an addon's older photo arrays (source,
     * id, thumb, credit, credit_url, licence, term, picked).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key): ?string => isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '' ? (string) $data[$key] : null;
        $int = fn (string $key): ?int => isset($data[$key]) && is_numeric($data[$key]) && (int) $data[$key] > 0 ? (int) $data[$key] : null;
        $tags = is_array($data['tags'] ?? null) ? array_values(array_filter(array_map(fn ($tag) => is_scalar($tag) ? trim((string) $tag) : '', $data['tags']))) : [];

        return new self(
            source: $string('source') ?? '',
            id: $string('id') ?? '',
            thumb: $string('thumb') ?? '',
            credit: $string('credit') ?? '',
            creditUrl: $string('credit_url'),
            licence: $string('licence') ?? '',
            title: $string('title'),
            description: $string('description'),
            tags: $tags,
            width: $int('width'),
            height: $int('height'),
            url: $string('url'),
            term: $string('term') ?? '',
            picked: (bool) ($data['picked'] ?? false),
            reason: $string('reason'),
            offer: is_array($data['offer'] ?? null) ? Offer::fromArray($data['offer']) : null,
            editorial: (bool) ($data['editorial'] ?? false),
            restrictions: $string('restrictions'),
            collection: $string('collection'),
        );
    }

    private function tagList(int $count = 5): ?string
    {
        $tags = array_slice($this->tags, 0, $count);

        return $tags === [] ? null : implode(', ', $tags);
    }

    private function firstOf(?string ...$texts): string
    {
        foreach ($texts as $text) {
            if ($text !== null && Slug::clip($text, 1) !== '') {
                return $text;
            }
        }

        return 'Photo';
    }

    private function copy(string $term, bool $picked, ?string $reason): self
    {
        return new self(
            $this->source, $this->id, $this->thumb, $this->credit, $this->creditUrl, $this->licence,
            $this->title, $this->description, $this->tags, $this->width, $this->height, $this->url,
            $term, $picked, $reason, $this->offer, $this->editorial, $this->restrictions, $this->collection,
        );
    }
}
