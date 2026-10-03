<?php

/*
 * The English source strings for the brief in the conversation (1.6), by
 * key without the `brief.` prefix. Each addon's build copies them into its
 * own format. The messages core writes into a session (`ask`, `card`,
 * `card.open`, `try-again`) are Domain\Sessions\BriefThread's constants;
 * pass the translation to SessionGuard where a site isn't in English.
 *
 * No user-facing text says Ghostwriter "guesses": it fills in, proposes,
 * and leaves in square brackets what only the person knows.
 */

return [
    // What Ghostwriter says in the conversation.
    'ask' => 'What’s it called, and what should it say? A line or two is plenty.',
    'card' => 'Here’s the brief. Change anything that isn’t right, then start writing.',
    'card.open' => 'Anything in [square brackets] is for you to fill in.',
    'filling' => 'Filling in the brief…',
    'filled' => 'The brief is filled in. Check it, then start writing.',
    'failed' => 'Ghostwriter could not fill in the brief from that. Try again, or say a little more about it.',

    // The brief card.
    'region' => 'Brief',
    'title' => 'Working title',
    'model-on' => 'Model it on',
    'agree' => 'Looks right, start writing',
    'try-again' => 'Try again',
    'show' => 'Show the brief',
    'hide' => 'Hide the brief',
    'save' => 'Save the brief',
    'saved' => 'The brief is saved. Ghostwriter works from it from the next message.',
];
