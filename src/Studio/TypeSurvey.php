<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Studio;

/**
 * What the type analyst is shown to work out a kind of content: the group
 * it lives in, its fields and the entries to model it on.
 */
final class TypeSurvey
{
    /**
     * @param  string  $groupTitle  The collection, section or resource as people see it.
     * @param  string  $groupHandle  Its handle, for logs.
     * @param  string|null  $title  What the editors call this kind, when they named it.
     * @param  bool  $chosenExamples  The entries were picked by an editor, rather than the newest.
     */
    public function __construct(
        public readonly string $groupTitle,
        public readonly string $groupHandle,
        public readonly Layout $layout,
        public readonly ?string $title = null,
        public readonly bool $chosenExamples = false,
    ) {}
}
