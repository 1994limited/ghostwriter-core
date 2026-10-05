<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;

/**
 * The search title and description part of a `seo-editor` call (SEO
 * layer §8.1, §9): which of the two are wanted, the length each should be
 * (MetaRange, the site name already off the title's budget), the page's
 * own title, what the page says (the sources the text is checked against:
 * the draft, the brief and the editor's answers) and, for Try again, the
 * texts to write differently from.
 */
final class MetaRequest
{
    /**
     * @param  array<int|string, string>  $sources
     * @param  array<string, string>  $previous  By role: the texts the editor asked to replace.
     */
    public function __construct(
        public readonly string $pageTitle,
        public readonly bool $wantsTitle,
        public readonly bool $wantsDescription,
        public readonly MetaRange $title,
        public readonly MetaRange $description,
        public readonly array $sources = [],
        public readonly array $previous = [],
    ) {}

    public function wants(): bool
    {
        return $this->wantsTitle || $this->wantsDescription;
    }

    public function wanted(string $role): bool
    {
        return $role === SeoField::TITLE ? $this->wantsTitle : $this->wantsDescription;
    }

    public function range(string $role): MetaRange
    {
        return $role === SeoField::TITLE ? $this->title : $this->description;
    }

    /** The prompt's part about them, after the page and the candidates. */
    public function prompt(): string
    {
        $lines = ['## Search title and description', ''];

        if ($this->wantsTitle) {
            $name = $this->title->format !== null && $this->title->format->addsName()
                ? ' once the site adds its name ("'.$this->title->format->compose('…').'")'
                : '';
            $lines[] = "- `title`: {$this->title->min} to {$this->title->max} characters. The page's own title, \"{$this->pageTitle}\", is too long for search results{$name}, so write a shorter search title that says what the page is. Don't add the site's name.";
        } else {
            $lines[] = '- `title`: give `""`. The page keeps its own title in search results.';
        }

        if ($this->wantsDescription) {
            $lines[] = "- `description`: {$this->description->min} to {$this->description->max} characters: what someone searching finds on this page, as one or two plain sentences in the site's voice.";
        } else {
            $lines[] = '- `description`: give `""`. The page has one already.';
        }

        if ($this->wants()) {
            $lines[] = '';
            $lines[] = 'Count the characters. Use only what the page says: no figure, name or claim it doesn\'t make. Anything in a `[[ask: …]]` or `[[check: …]]` marker isn\'t confirmed yet: leave it out, with any figure that appears only inside one.';
        }

        $previous = array_filter([
            SeoField::TITLE => $this->wantsTitle ? trim($this->previous[SeoField::TITLE] ?? '') : '',
            SeoField::DESCRIPTION => $this->wantsDescription ? trim($this->previous[SeoField::DESCRIPTION] ?? '') : '',
        ]);

        if ($previous !== []) {
            $lines[] = '';
            $lines[] = 'The editor asked for another. Write '.(count($previous) === 1 ? 'it' : 'them').' differently from:';

            foreach ($previous as $role => $text) {
                $lines[] = "- {$role}: \"{$text}\"";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * What is wrong with a reply's title and description, for the one
     * retry: each problem the check finds in a wanted one, in words.
     */
    public function problems(SeoReply $reply, SeoMetaCheck $check = new SeoMetaCheck): ?string
    {
        $problems = [];

        foreach ([SeoField::TITLE, SeoField::DESCRIPTION] as $role) {
            if ($this->wanted($role)) {
                array_push($problems, ...array_values($check->problems($reply->text($role), $this->range($role), $this->sources, $this->pageTitle)));
            }
        }

        return $problems === [] ? null : implode(' ', $problems);
    }
}
