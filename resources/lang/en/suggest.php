<?php

/*
 * The English source strings for Suggest edits, by key without the
 * `suggest.` prefix (Gaps\Message). Each addon's build copies them into its
 * own format. Parameters are Laravel-style: `:quote`, `:year`.
 */

return [
    // Category names.
    'category.out-of-date' => 'Out of date',
    'category.voice' => 'Voice',
    'category.clarity' => 'Clarity',
    'category.fact-to-check' => 'Fact to check',
    'category.link' => 'Link',
    'category.accessibility' => 'Accessibility',
    'category.seo' => 'SEO',
    'category.duplicate' => 'Duplicate',

    // The mark's speech label, by category.
    'speech.out-of-date' => 'A bit old',
    'speech.voice' => 'Off-voice',
    'speech.clarity' => 'Wordy',
    'speech.fact-to-check' => 'Still true?',
    'speech.link' => 'Dead link',
    'speech.accessibility' => 'No alt text',
    'speech.seo' => 'Too long',
    'speech.duplicate' => 'Said before',

    // What a free check found.
    'finding.past-year' => 'Says “:quote” in :now.',
    'finding.relative-time' => '“:quote” was written in :year.',
    'finding.closing-date' => 'The date in “:quote” has passed.',
    'finding.closing-date-field' => ':label (:date) has passed.',
    'finding.stated-count' => 'A count from :date: “:quote”. Is it still right?',
    'finding.long-sentence' => 'This sentence has :words words.',
    'finding.empty-link-text' => 'The link “:quote” doesn\'t say where it goes.',
    'finding.overlap' => 'This paragraph is much like one on “:title” (:share% the same).',
    'finding.link-broken' => 'The “:quote” link points at a page that\'s been deleted.',
    'finding.link-broken-field' => ':label points at a page that\'s been deleted.',
    'finding.external-link' => 'The “:quote” link didn\'t work when it was last checked (:status).',
    'finding.external-link-field' => ':label didn\'t work when it was last checked (:status).',
    'finding.missing-alt' => ':filename has no alt text.',
    'finding.seo-length' => ':label is :length characters; the limit is :limit.',
    'finding.seo-empty' => ':label is empty. Pages like this fill it in.',

    // Where a suggestion's reason comes from.
    'source.voice-guide' => 'Voice guide: “:heading”',
    'source.voice-guide-plain' => 'Voice guide',
    'source.kind' => 'Guidance for :kind',
    'source.house' => 'House style',
    'source.check' => 'Found without AI',
    'source.site-entry' => 'From “:title”',
    'source.image' => 'Checked against the image',
    'source.editor' => 'Facts only come from you',
    'source.general' => 'General writing advice',

    // The free fix when the review call wrote none.
    'free.rewrite' => 'Rewrite it yourself',
    'free.answer' => 'What should it say now?',
    'free.alt' => 'Describe the image yourself',

    // Fact to check.
    'fact.ask' => 'I won\'t guess. What is it now?',
    'fact.still-right' => 'It\'s still right',
    'fact.remove' => 'Remove the number',
    'fact.use' => 'Use it',
    'fact.number-only' => 'Type the number only.',
    'fact.date-only' => 'Type a date.',

    // Review states and notes.
    'review.split' => 'This page is long, so it\'s read in :calls parts. Uses Ghostwriter :calls times.',
    'review.one-call' => 'Uses Ghostwriter once.',
    'review.cut-off' => 'I ran out of room; these are the first :count.',
    'review.nothing' => 'Nothing to suggest. The page reads well against your guide.',
    'review.failed' => 'I couldn\'t finish reading the page: :reason',
    'review.error.unreadable' => 'the answer came back in a shape I couldn\'t read. Try again.',
    'review.stale' => 'This text has changed since the review.',
    'review.another-none' => 'I couldn\'t find another way to say it that keeps to the facts.',
    'review.write-another' => 'Write another (uses Ghostwriter)',
];
