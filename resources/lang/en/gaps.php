<?php

/*
 * The English source strings for "Finish this page", by key without the
 * `gaps.` prefix (Gaps\Message). Each addon's build copies them into its
 * own format. Parameters are Laravel-style: `:label`, `:hint`.
 *
 * The marker keywords `ask` and `gw-link` are never translated.
 */

return [
    // What the guide says about each kind of gap.
    'ask' => 'I left a gap in :label: :hint. I didn\'t want to guess. What should it say?',
    'ask-value' => ':label is empty: :hint. I didn\'t want to guess it.',
    'link' => 'The “:words” link in :label doesn\'t go anywhere yet.',
    'link-field' => ':label doesn\'t link anywhere yet.',
    'link-empty' => ':label is empty. Pages like this usually link somewhere here.',
    'link-broken' => 'A link in :label points at a page that\'s been deleted.',
    'image-placeholder' => ':label is still the striped placeholder.',
    'image-empty' => ':label is empty. Pages like this usually have an image here.',
    'stock-preview' => ':label is still a :library preview. License it before publishing.',
    'required' => ':label is required and empty.',
    'expected' => ':label is empty. Pages like this usually fill it in.',
    'leftover-token' => 'Some template text slipped into :label: “:hint”.',
    'placeholder-text' => '“:hint” in :label looks like it\'s waiting for something.',
    'missing-alt' => 'This image in :label has no alt text.',
    'seo-length' => ':label is too long.',
    'off-style-image' => 'The image in :label doesn\'t look like the others here.',

    // The mark's speech label, by kind.
    'speech.ask' => 'Fill this in',
    'speech.ask-value' => 'Fill this in',
    'speech.link' => 'Needs a link',
    'speech.link-empty' => 'Needs a link',
    'speech.link-broken' => 'Broken link',
    'speech.image-placeholder' => 'Swap me',
    'speech.image-empty' => 'Empty!',
    'speech.stock-preview' => 'License me',
    'speech.required' => 'Empty!',
    'speech.expected' => 'Empty!',
    'speech.leftover-token' => 'Oops',
    'speech.placeholder-text' => 'Fill this in',
    'speech.missing-alt' => 'Describe me',
    'speech.seo-length' => 'Too long',
    'speech.off-style-image' => 'Hmm',

    // Fixes.
    'fix.answer' => 'Type it in',
    'fix.link' => 'Link to :title',
    'fix.add-link' => 'Add the address',
    'fix.choose-entry' => 'Choose an entry',
    'fix.remove-link' => 'Remove the link',
    'fix.find-photo' => 'Find a photo',
    'fix.choose-asset' => 'Choose from Assets',
    'fix.leave-empty' => 'Leave it empty',
    'fix.license' => 'License',
    'fix.request-licence' => 'Request licence',
    'fix.refresh-preview' => 'Refresh preview',
    'fix.choose-another' => 'Choose another',
    'fix.write-for-me' => 'Write it for me',
    'fix.write-around' => 'Write around it',
    'fix.shorten' => 'Use a shorter one',
    'fix.focus' => 'I\'ll write it',
    'fix.remove' => 'Remove it',
    'fix.dismiss' => 'It\'s fine',

    // The guide.
    'guide.title' => 'Finish this page',
    'guide.count' => ':count things to finish',
    'guide.count-one' => '1 thing to finish',
    'guide.ready' => 'Ready to publish',
    'guide.suggestions' => 'and :count suggestions',
    'guide.suggestions-one' => 'and 1 suggestion',
    'guide.done' => 'All done. Nothing left to fill in, so this page is ready to publish.',
    'guide.skipped' => 'That\'s everything I could help with. :count still need you; they stay highlighted until they\'re filled in.',
    'guide.skipped-one' => 'That\'s everything I could help with. 1 still needs you; it stays highlighted until it\'s filled in.',
    'guide.reason.draft' => 'I didn\'t want to guess.',
];
