<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Gaps;

/**
 * - `Blocks`: the publish guard refuses (or warns, in warn mode).
 * - `Required`: counted, but left to the CMS's own validation.
 * - `Suggestion`: advice only, never counted and never blocking.
 */
enum Severity: string
{
    case Blocks = 'blocks';
    case Required = 'required';
    case Suggestion = 'suggestion';
}
