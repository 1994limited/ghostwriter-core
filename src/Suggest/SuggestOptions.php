<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Suggest;

/**
 * The site's one Suggest edits setting: whether claims are checked. On (the
 * default), counts about the organisation in an old entry ("team of 6",
 * "over 20 years") are found free, and the review may flag claims only the
 * editor can confirm ("award-winning", "the largest"), each as a Fact to
 * check. Off, neither happens. Closing dates are dates, not claims, and are
 * always checked.
 */
final class SuggestOptions
{
    public function __construct(
        public readonly bool $claims = true,
    ) {}
}
