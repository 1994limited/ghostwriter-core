<?php

/*
 * The English source strings for the SEO layer (Gaps\Message keys `seo.*`):
 * the after-draft notice and the Text tab's marks on the links Ghostwriter
 * added. Each addon's build copies them into its own format. Parameters are
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
];
