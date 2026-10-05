<?php

/*
 * The English source strings for Content to revisit, by key without the
 * `revisit.` prefix (Gaps\Message). Each addon's build copies them into
 * its own format.
 */

return [
    // Reason chips.
    'reason.age-months' => ':months months old',
    'reason.age-years' => ':years years old',
    'reason.past-year' => '“:quote”',
    'reason.relative-time' => '“:quote” = :year',
    'reason.closing-date' => 'closing date passed',
    'reason.broken-link-one' => '1 broken link',
    'reason.broken-link-many' => ':count broken links',
    'reason.external-link-one' => '1 link to another site failed',
    'reason.external-link-many' => ':count links to other sites failed',
    'reason.missing-alt' => 'no alt text',
    'reason.missing-alt-many' => 'no alt text ×:count',
    'reason.empty-field' => 'empty fields',
    'reason.empty-field-one' => '1 empty field',
    'reason.leftover' => 'unfinished',
    'reason.seo-length' => 'SEO text too long',
    'reason.stated-count' => '“:quote”',
    'reason.seo-missing' => 'No SEO description',
    'reason.few-links' => 'No internal links',
    'reason.heading-levels' => 'Heading levels',
    'reason.competing' => 'Twin of “:quote”',
    'reason.readability' => 'Long paragraphs',

    // Priority words.
    'priority.high' => 'High',
    'priority.medium' => 'Medium',
    'priority.low' => 'Low',

    // The list.
    'note' => 'Found without AI: dates, links, empty fields and age. Reviewing a page uses one AI call, only when you ask.',
    'empty' => 'Nothing needs a look. Ghostwriter checks every page each day for dates, links, alt text and empty fields.',
    'tile.worth-a-look' => 'pages worth a look',
    'tile.past-year' => 'mention a past year as current',
    'tile.broken-link' => 'broken links',
    'tile.missing-alt' => 'images without alt text',
    'external.setting' => 'Check links to other sites once a week',
    'external.help' => 'Off by default. Once a week, Ghostwriter asks each site your pages link to whether the page is still there (a HEAD request, at most one a second per site). Broken ones show as Link suggestions and in this list.',
];
