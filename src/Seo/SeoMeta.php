<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoField;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SeoSource;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\TitleFormat;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;

/**
 * The search title, description and address of a draft (SEO layer §9,
 * §10), between MetaContext (the site's SEO fields), the draft and the
 * session's SearchMeta:
 *
 * - fields(): the entry's SEO title and description as they would stand
 *   with the draft in it (an inherited one reads the draft's excerpt), with
 *   how the `<title>` adds the site name.
 * - request(): what the `seo-editor` call is asked for: the description
 *   where MetaPolicy would write or suggest one, the title only where the
 *   page title won't do (decision 12) or the page was given its own; never
 *   what an editor wrote in the Search section, unless they asked again.
 * - settle(): the reply checked (SeoMetaCheck) into the session's meta.
 * - slug(): the address, made from the title (SlugRules) while nobody has
 *   typed one.
 */
final class SeoMeta
{
    public function __construct(
        private readonly MetaPolicy $policy = new MetaPolicy,
        private readonly SeoMetaCheck $check = new SeoMetaCheck,
    ) {}

    /**
     * The SEO title and description fields (the first of each the addon
     * finds: its SEO addon's before plain fields), as they would read with
     * the draft's values in the entry, and the title format.
     *
     * @param  array<string, mixed>  $data  The draft's values.
     * @return array{title: ?SeoField, description: ?SeoField, format: ?TitleFormat}
     */
    public function fields(MetaContext $context, array $data = []): array
    {
        $entry = $context->entry->withValues(array_replace($context->entry->values, $data));
        $found = [SeoField::TITLE => null, SeoField::DESCRIPTION => null];

        foreach ($context->fields->in($context->schema, $entry) as $field) {
            $found[$field->role] ??= $field;
        }

        return [
            'title' => $found[SeoField::TITLE],
            'description' => $found[SeoField::DESCRIPTION],
            'format' => $context->fields->titleFormat($context->schema, $entry),
        ];
    }

    /** The range a role's text should be in. */
    public function range(?SeoField $field, string $role, ?TitleFormat $format): MetaRange
    {
        return $field !== null ? MetaRange::of($field, $format) : MetaRange::for($role, null, $role === SeoField::TITLE ? $format : null);
    }

    /**
     * What Ghostwriter does with the field: MetaPolicy's answer, with the
     * title's own rule on a new entry: a page title too long for search is
     * replaced by a title of the page's own even where the field inherits
     * it (decision 12), as nobody wrote that title for this page.
     */
    public function action(?SeoField $field, bool $newEntry, SeoProvenance $provenance, MetaRange $range): ?MetaAction
    {
        if ($field === null) {
            return null;
        }

        $action = $this->policy->decide($field, $provenance, $newEntry, $range);

        if ($action === MetaAction::Suggest && $field->role === SeoField::TITLE && $newEntry && $field->source === SeoSource::Field) {
            return MetaAction::Write;
        }

        return $action;
    }

    /**
     * What to ask the `seo-editor` call for on this draft; null when
     * nothing is wanted.
     *
     * - $force: Try again: an editor's own text is asked for again too, and
     *   the current texts are given to write differently from.
     *
     * @param  array<int|string, string>  $sources  What the page may say besides the draft: the brief and the answers.
     */
    public function request(Session $session, MetaContext $context, array $sources = [], bool $force = false): ?MetaRequest
    {
        $draft = self::draft($session);

        if ($draft === null) {
            return null;
        }

        $fields = $this->fields($context, $draft->data);
        $meta = SeoState::of($session)->meta;
        $provenance = $context->provenance->merge(SeoState::of($session)->written);
        $pageTitle = $draft->title();
        $wants = [];
        $ranges = [];

        foreach ([SeoField::TITLE, SeoField::DESCRIPTION] as $role) {
            $field = $fields[$role];
            $ranges[$role] = $this->range($field, $role, $fields['format']);
            $action = $this->action($field, $context->newEntry, $provenance, $ranges[$role]);
            $wanted = $action !== null && $action !== MetaAction::Leave;

            if ($role === SeoField::TITLE) {
                $wanted = $wanted && ($this->policy->wantsTitle($pageTitle, $ranges[$role]) || $meta->title !== '');
            }

            $wants[$role] = $wanted && ($force || ! $meta->edited($role));
        }

        if (! $wants[SeoField::TITLE] && ! $wants[SeoField::DESCRIPTION]) {
            return null;
        }

        return new MetaRequest(
            $pageTitle,
            $wants[SeoField::TITLE],
            $wants[SeoField::DESCRIPTION],
            $ranges[SeoField::TITLE],
            $ranges[SeoField::DESCRIPTION],
            [self::text($draft->data), ...array_values($sources)],
            $force ? array_filter([SeoField::TITLE => $meta->title, SeoField::DESCRIPTION => $meta->description]) : [],
        );
    }

    /**
     * The reply's title and description, checked, into the session's
     * meta. A text that fails the checks is dropped (and why is kept); one
     * only too long is cut at a word. Roles not asked for are left as
     * they were.
     */
    public function settle(SearchMeta $meta, SeoReply $reply, MetaRequest $request, ?string $now = null): SearchMeta
    {
        $now ??= gmdate('Y-m-d\TH:i:s\Z');

        foreach ([SeoField::TITLE, SeoField::DESCRIPTION] as $role) {
            if (! $request->wanted($role)) {
                continue;
            }

            [$text, $problems] = $this->check->settle($reply->text($role), $request->range($role), $request->sources, $request->pageTitle);
            $meta = $meta->with($role, $text, false, $text === '' ? array_keys($problems) : [], $now);
        }

        return $meta;
    }

    /**
     * The address for the draft: its title through SlugRules, unless an
     * editor typed one; null where no slug is set.
     */
    public function slug(Session $session, MetaContext $context): ?string
    {
        $meta = SeoState::of($session)->meta;
        $slug = $context->slug;

        if ($slug === null || ! $slug->settable) {
            return null;
        }

        if ($meta->edited('slug') && $meta->slug !== null) {
            return $meta->slug;
        }

        $draft = self::draft($session);

        return $draft === null ? $meta->slug : (SlugRules::suggest($draft->title(), $context->locale, $slug->dated, $slug->taken) ?: null);
    }

    /**
     * The draft's words as one text: what the title and description may
     * draw on.
     *
     * @param  array<mixed>  $data
     */
    public static function text(array $data): string
    {
        $texts = [];

        array_walk_recursive($data, function (mixed $value) use (&$texts) {
            if (is_string($value) && trim($value) !== '') {
                $texts[] = $value;
            }
        });

        return implode("\n\n", $texts);
    }

    private static function draft(Session $session): ?Draft
    {
        if ($session->draft === null || trim($session->draft) === '') {
            return null;
        }

        try {
            return Draft::parse($session->draft);
        } catch (\Throwable) {
            return null;
        }
    }
}
