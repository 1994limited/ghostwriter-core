<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;

/**
 * What the SEO pass did to a session's draft, kept on the session
 * (Session::$seo) and shared by everyone on the piece:
 *
 * - `links`: the links it added to the site's other pages, each with the
 *   unit it is in, its words, its href and the page it goes to (title,
 *   type, address) and why it was chosen, for the Text tab's marks and
 *   popover and for Finish this page's "Check 3 links Ghostwriter added".
 * - `removed`: the hrefs of links an editor removed (Remove link), so
 *   LinkGuard never lets the writer put one back.
 * - `notice`: one line for the reply area about what the pass did ("I
 *   linked to 3 of your pages: …"), as a message key and its parameters.
 * - `checked`: when the links were looked for (the first draft), so they
 *   are looked for once.
 * - `suggested`: for the writer's own links to choose (`#gw-link:`
 *   markers), the page the `seo-editor` call suggested and the verifier
 *   kept, by the marker's hint and words, so Finish this page offers it
 *   first, "Link to Contact us" (decision 24). The marker itself is never
 *   resolved.
 */
final class SeoState
{
    /**
     * @param  list<array{unit: string, words: string, href: string, title: string, type: string, url: ?string, why: string}>  $links
     * @param  list<string>  $removed
     * @param  array{key: string, params: array<string, scalar|null>}|null  $notice
     * @param  list<array{hint: string, words: string, id: string, title: string, type: string, url: ?string, href: string, why: string}>  $suggested
     */
    public function __construct(
        public readonly array $links = [],
        public readonly array $removed = [],
        public readonly ?array $notice = null,
        public readonly ?string $checked = null,
        public readonly array $suggested = [],
    ) {}

    public static function of(Session $session): self
    {
        return self::fromArray($session->seo);
    }

    /**
     * @param  array<mixed>  $array
     */
    public static function fromArray(array $array): self
    {
        $links = [];

        foreach (is_array($array['links'] ?? null) ? $array['links'] : [] as $link) {
            if (! is_array($link) || ! is_string($link['href'] ?? null) || ! is_string($link['words'] ?? null) || $link['href'] === '' || $link['words'] === '') {
                continue;
            }

            $text = fn (string $key) => is_scalar($link[$key] ?? null) ? (string) $link[$key] : '';
            $links[] = [
                'unit' => $text('unit'),
                'words' => $link['words'],
                'href' => $link['href'],
                'title' => $text('title'),
                'type' => $text('type'),
                'url' => is_string($link['url'] ?? null) && $link['url'] !== '' ? $link['url'] : null,
                'why' => $text('why'),
            ];
        }

        $notice = $array['notice'] ?? null;
        $notice = is_array($notice) && is_string($notice['key'] ?? null)
            ? ['key' => $notice['key'], 'params' => array_filter(is_array($notice['params'] ?? null) ? $notice['params'] : [], fn ($value) => is_scalar($value) || $value === null)]
            : null;

        $suggested = [];

        foreach (is_array($array['suggested'] ?? null) ? $array['suggested'] : [] as $suggestion) {
            if (! is_array($suggestion) || ! is_string($suggestion['href'] ?? null) || $suggestion['href'] === '' || ! is_string($suggestion['title'] ?? null)) {
                continue;
            }

            $text = fn (string $key) => is_scalar($suggestion[$key] ?? null) ? (string) $suggestion[$key] : '';
            $suggested[] = [
                'hint' => $text('hint'),
                'words' => $text('words'),
                'id' => $text('id'),
                'title' => $suggestion['title'],
                'type' => $text('type'),
                'url' => is_string($suggestion['url'] ?? null) && $suggestion['url'] !== '' ? $suggestion['url'] : null,
                'href' => $suggestion['href'],
                'why' => $text('why'),
            ];
        }

        return new self(
            $links,
            array_values(array_filter(is_array($array['removed'] ?? null) ? $array['removed'] : [], fn ($href) => is_string($href) && $href !== '')),
            $notice,
            is_string($array['checked'] ?? null) ? $array['checked'] : null,
            $suggested,
        );
    }

    /**
     * For Session::$seo: only what is set.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'links' => $this->links,
            'removed' => $this->removed,
            'notice' => $this->notice,
            'checked' => $this->checked,
            'suggested' => $this->suggested,
        ], fn ($value) => $value !== null && $value !== []);
    }

    public function saveTo(Session $session): void
    {
        $session->seo = $this->toArray();
    }

    /** The notice, as a message the addon translates; null when there is nothing to say. */
    public function message(): ?Message
    {
        return $this->notice === null ? null : new Message($this->notice['key'], $this->notice['params']);
    }

    /**
     * @param  list<array{unit: string, words: string, href: string, title: string, type: string, url: ?string, why: string}>  $links
     * @param  array{key: string, params: array<string, scalar|null>}|null  $notice
     * @param  list<array{hint: string, words: string, id: string, title: string, type: string, url: ?string, href: string, why: string}>|null  $suggested  Null keeps those it has.
     */
    public function withLinks(array $links, ?array $notice, string $checked, ?array $suggested = null): self
    {
        return new self(array_values($links), $this->removed, $notice, $checked, array_values($suggested ?? $this->suggested));
    }

    /**
     * The page suggested for one of the writer's links to choose, by its
     * hint as a gap or chip has it (hyphens and spaces alike, any case),
     * and, where two markers share a hint, its words. Null when none was.
     *
     * @return array{hint: string, words: string, id: string, title: string, type: string, url: ?string, href: string, why: string}|null
     */
    public function suggestion(?string $hint, string $words = ''): ?array
    {
        if ($hint === null || trim($hint) === '') {
            return null;
        }

        $key = self::hintKey($hint);
        $same = array_values(array_filter($this->suggested, fn (array $suggestion) => self::hintKey($suggestion['hint']) === $key));

        if (count($same) > 1 && trim($words) !== '') {
            foreach ($same as $suggestion) {
                if (self::hintKey($suggestion['words']) === self::hintKey($words)) {
                    return $suggestion;
                }
            }
        }

        return $same[0] ?? null;
    }

    private static function hintKey(string $hint): string
    {
        return Markers::normaliseHint((string) preg_replace('/[-_]+/', ' ', Markers::linkHintFrom($hint)));
    }

    /**
     * Without the link to this href (an editor removed it): it leaves
     * `links` and joins `removed`. Every link Ghostwriter added to the same
     * page goes, as one page is linked once.
     */
    public function without(string $href): self
    {
        $key = LinkCandidates::linkKey($href) ?? $href;
        $links = array_values(array_filter($this->links, fn (array $link) => (LinkCandidates::linkKey($link['href']) ?? $link['href']) !== $key));

        return new self($links, array_values(array_unique([...$this->removed, $href])), $this->notice, $this->checked, $this->suggested);
    }

    /**
     * The link Ghostwriter added with this href, if any.
     *
     * @return array{unit: string, words: string, href: string, title: string, type: string, url: ?string, why: string}|null
     */
    public function link(string $href): ?array
    {
        $key = LinkCandidates::linkKey($href) ?? $href;

        foreach ($this->links as $link) {
            if ((LinkCandidates::linkKey($link['href']) ?? $link['href']) === $key) {
                return $link;
            }
        }

        return null;
    }

    /** Whether an editor removed a link to this href. */
    public function wasRemoved(string $href): bool
    {
        $key = LinkCandidates::linkKey($href) ?? $href;

        foreach ($this->removed as $removed) {
            if ((LinkCandidates::linkKey($removed) ?? $removed) === $key) {
                return true;
            }
        }

        return false;
    }
}
