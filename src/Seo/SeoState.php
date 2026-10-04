<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
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
 */
final class SeoState
{
    /**
     * @param  list<array{unit: string, words: string, href: string, title: string, type: string, url: ?string, why: string}>  $links
     * @param  list<string>  $removed
     * @param  array{key: string, params: array<string, scalar|null>}|null  $notice
     */
    public function __construct(
        public readonly array $links = [],
        public readonly array $removed = [],
        public readonly ?array $notice = null,
        public readonly ?string $checked = null,
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

        return new self(
            $links,
            array_values(array_filter(is_array($array['removed'] ?? null) ? $array['removed'] : [], fn ($href) => is_string($href) && $href !== '')),
            $notice,
            is_string($array['checked'] ?? null) ? $array['checked'] : null,
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
     */
    public function withLinks(array $links, ?array $notice, string $checked): self
    {
        return new self(array_values($links), $this->removed, $notice, $checked);
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

        return new self($links, array_values(array_unique([...$this->removed, $href])), $this->notice, $this->checked);
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
