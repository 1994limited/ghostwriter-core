<?php

/*
 * The English source strings for the SEO layer (Gaps\Message keys `seo.*`):
 * the after-draft notice, the Text tab's marks on the links Ghostwriter
 * added, and the Text tab's Search section. Each addon's build copies them into its own format. Parameters are
 * Laravel-style: `:count`, `:titles`.
 */

return [
    // One line after a first draft: what the SEO pass did (§12.1).
    'notice.links' => 'I linked to :count of your pages: :titles.',
    'notice.links-one' => 'I linked to one of your pages: :titles.',
    'notice.no-links' => 'I didn\'t find pages close enough to link to.',

    // The draft pane while the pass runs (§5.1).
    'status.checking' => 'Draft ready. Checking headings and links…',

    // The Text tab: a link Ghostwriter added, and its popover (§7.5).
    'link.added' => 'Added by Ghostwriter',
    'link.added-long' => 'Added by Ghostwriter. Removing it keeps the words.',
    'link.remove' => 'Remove link',
    'link.open' => 'Open page',
    'link.removed' => 'Link removed. The words stay.',

    // The Text tab's Search section (§9.5): the search title, description and address.
    'search.heading' => 'Search',
    'search.intro' => 'How this page may appear in search results. Nothing here is saved until you use the draft.',
    'search.title' => 'SEO title',
    'search.description' => 'Meta description',
    'search.address' => 'Address',
    'search.uses-title' => 'Uses the page title: “:title”',
    'search.title-fits' => 'It fits, so your SEO settings keep using it.',
    'search.title-long' => 'The page title is too long for search results, so the page needs one of its own.',
    'search.title-own' => 'Its own title, shorter than the page title.',
    'search.title-stays' => 'Your SEO title stays. Suggested instead:',
    'search.give-own' => 'Give it its own',
    'search.use-page-title' => 'Use the page title',
    'search.description-new' => 'Only what the page says.',
    'search.description-stays' => 'Your SEO description stays. Suggested instead:',
    'search.description-inherits' => 'Uses :field. It fits, so your SEO settings keep using it.',
    'search.description-dropped' => 'I couldn\'t write one from what the page says. Try again, or write your own.',
    'search.description-empty' => 'Empty: Finish this page will offer one.',
    'search.edited' => 'Yours now: a later turn won\'t rewrite it.',
    'search.template' => 'Your SEO settings make this one.',
    'search.off' => 'Switched off in your SEO settings.',
    'search.use-this' => 'Use this',
    'search.keep-mine' => 'Keep mine',
    'search.range' => 'Aim for :min to :max characters',
    'search.address-new' => 'Set on this new page only. Published pages keep their address.',
    'search.address-kept' => 'Published pages keep their address.',
    'search.try-again' => 'Try again',
    'search.try-again-note' => 'Asks for another title and description. Uses Ghostwriter.',
    'search.writing' => 'Writing another…',
    'search.failed' => 'That didn\'t work. Try again in a moment.',
];
