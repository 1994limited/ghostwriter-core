<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Walk;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;

/**
 * Whether an existing page's headings sit at the levels its template and
 * editors call for, without changing anything: HeadingFixer run over each
 * top-level rich-text value with the group's render profile
 * (GapContext::$profile, else the default "the title is the H1"). Only a
 * heading that would move to another level counts (a skipped level, a
 * body H1 under the template's); bold lines and lead-ins don't. For
 * Content to revisit's `heading-levels` reason (SEO layer §13.2). No
 * model.
 *
 * Fields inside blocks are left out: their top depends on the block's
 * own heading, which a saved page doesn't say.
 */
final class HeadingLevelCheck
{
    public function __construct(private readonly HeadingFixer $fixer = new HeadingFixer) {}

    /**
     * The paths of the fields whose heading levels would change.
     *
     * @return list<string>
     */
    public function fields(GapContext $context): array
    {
        $paths = [];

        foreach (Walk::entry($context->schema, $context->entry) as $visit) {
            if (count($visit->path->segments) !== 1 || ! HeadingLevels::holdsHeadings($visit->field)) {
                continue;
            }

            $text = Walk::text($visit, $context->richText);

            if ($text === null || ! str_contains($text, '#')) {
                continue;
            }

            $fixed = $this->fixer->fix($text, HeadingPolicy::for($visit->field, $context->profile));

            foreach ($fixed->changes as $change) {
                if ($change->kind === HeadingChange::LEVEL) {
                    $paths[] = $visit->path->toString();

                    break;
                }
            }
        }

        return $paths;
    }
}
