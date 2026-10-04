<?php

/*
 * The English source strings for "Finish this page", by key without the
 * `gaps.` prefix (Gaps\Message). Each addon's build copies them into its
 * own format. Parameters are Laravel-style: `:label`, `:hint`.
 *
 * The marker keywords `ask`, `check`, `from` and `gw-link` are never translated.
 */

return [
    // What the guide says about each kind of gap.
    'ask' => 'I left a gap in :label: :hint. Only you know this. What should it say?',
    'ask-value' => ':label is empty: :hint. This one needs you.',
    'check' => 'I counted :hint from “:list”. Is that right?',
    'check-changed' => 'I counted :hint from “:list”, but that list has changed since. It now has :newCount. Use “:newValue” instead?',
    'check-gone' => 'I counted :hint from “:list”, but that list isn\'t in what you gave me any more. Is :hint still right?',
    'check-count' => 'This says :hint, but “:list” has :newCount. Use “:newValue” instead?',
    'link' => 'The “:words” link in :label doesn\'t go anywhere yet.',
    'link-field' => ':label doesn\'t link anywhere yet.',
    'link-empty' => ':label is empty. Pages like this usually link somewhere here.',
    'link-broken' => 'A link in :label points at a page that\'s been deleted.',
    'image-placeholder' => ':label is still the striped placeholder.',
    'image-empty' => ':label is empty. Pages like this usually have an image here.',
    'image-empty.required' => ':label is required. Add one?',
    'image-empty.prominent' => ':label is the page\'s main image, and it\'s empty. Add one?',
    'image-empty.siblings' => ':label is empty, but most :group entries have one. Add one?',
    'stock-preview' => ':label is still a :library preview. License it before publishing.',
    'required' => ':label is required and empty.',
    'expected' => ':label is empty. Pages like this usually fill it in.',
    'leftover-token' => 'Some template text slipped into :label: “:hint”.',
    'placeholder-text' => '“:hint” in :label looks like it\'s waiting for something.',
    'missing-alt' => 'This image in :label has no alt text.',
    'seo-length' => ':label is too long.',
    'off-style-image' => 'The image in :label doesn\'t look like the others here.',
    'links-added' => 'Check :count links Ghostwriter added. “:words” goes to :title (:url). Keep it, or remove the link and keep the words.',
    'links-added-one' => 'Check the link Ghostwriter added. “:words” goes to :title (:url). Keep it, or remove the link and keep the words.',

    // The mark's speech label, by kind.
    'speech.ask' => 'Fill this in',
    'speech.ask-value' => 'Fill this in',
    'speech.check' => 'Check me',
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
    'speech.links-added' => 'Linked',

    // Fixes.
    'fix.answer' => 'Type it in',
    'fix.confirm' => 'Looks right',
    'fix.use-count' => 'Use “:value”',
    'fix.change' => 'Change it',
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
    'fix.keep-link' => 'Keep it',

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
    'guide.reason.draft' => 'Only you know this.',

    // The publish guard: one message for the page...
    'publish.ready' => 'Ready to publish.',
    'publish.blocked' => ':count things to finish before this page goes live: :items.',
    'publish.blocked-one' => '1 thing to finish before this page goes live: :items.',
    'publish.warned' => 'Published with :count things still to finish: :items.',
    'publish.warned-one' => 'Published with 1 thing still to finish: :items.',

    // ...each thing in its list...
    'publish.item.ask' => ':label (:hint)',
    'publish.item.ask-value' => ':label (:hint)',
    'publish.item.check' => ':label (a count to check: :hint)',
    'publish.item.link' => ':label (a link to choose)',
    'publish.item.link-broken' => ':label (a link to a deleted page)',
    'publish.item.image-placeholder' => ':label (the image placeholder)',
    'publish.item.stock-preview' => ':label (a :library preview, not licensed)',
    'publish.item.leftover-token' => ':label (template text “:hint”)',

    // ...and what each field says.
    'publish.field.ask' => 'Add :hint before publishing.',
    'publish.field.ask-value' => 'Add :hint before publishing.',
    'publish.field.check' => 'Check “:hint” before publishing.',
    'publish.field.link' => 'Choose where this link goes before publishing.',
    'publish.field.link-broken' => 'This links to a page that\'s been deleted. Choose another before publishing.',
    'publish.field.image-placeholder' => 'Replace the image placeholder before publishing.',
    'publish.field.stock-preview' => 'This is a :library preview, not licensed yet. License it, or choose another image, before publishing.',
    'publish.field.leftover-token' => 'Remove the template text “:hint” before publishing.',

    // The extras list: where a counted extra came from, and its state.
    'extras.counted.brief' => 'Counted from your brief: “:list”',
    'extras.counted.answer' => 'Counted from your answer: “:list”',
    'extras.counted.conversation' => 'Counted from your message: “:list”',
    'extras.counted.draft' => 'Counted from the draft: “:list”',
    'extras.counted.entry' => 'Counted from :title: “:list”',
    'extras.needs-review' => 'Needs review',
    'extras.needs-answer' => 'Needs your answer',
];
