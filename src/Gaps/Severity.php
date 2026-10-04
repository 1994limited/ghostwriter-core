<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * - `Blocks`: the publish guard refuses (or warns, in warn mode).
 * - `Prompt`: an image the page looks like it needs, left empty. Counted,
 *   and it brings the guide out like what blocks, but never blocks: where
 *   the field is required, the CMS's own validation says so on save.
 * - `Required`: counted, but left to the CMS's own validation; it doesn't
 *   bring the guide out on its own.
 * - `Suggestion`: advice only, never counted and never blocking.
 */
enum Severity: string
{
    case Blocks = 'blocks';
    case Prompt = 'prompt';
    case Required = 'required';
    case Suggestion = 'suggestion';
}
