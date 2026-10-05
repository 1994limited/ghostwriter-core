<?php

/*
 * English phrase lists for the free checks (Suggest\Phrases). Patterns are
 * PCRE fragments, matched case-insensitively against normalised text
 * (straight quotes, "-" for every dash, one space between words).
 *
 * - current: a year written as if it were still current. {year} is the year.
 * - history: a year written as history; it is never flagged. {year} is the year.
 * - relative: time words that age with the page.
 * - closing: words before a date that make it a closing or end date.
 * - months: month names (and short forms) to their numbers.
 * - counts: counts and prices about the organisation. {n} is the number.
 * - numbers: number words the counts may use.
 * - link_text: link words that say nothing out of context.
 * - long_sentence: words in a sentence before it counts as long.
 * - stop_words: words too common to match link targets on (Seo\LinkCandidates).
 * - utility_slugs: address segments of pages never offered as link targets
 *   (Seo\Linkable): search, login, cart, thank-you…; every language's list applies.
 */

return [
    'current' => [
        'new (?:for|in) {year}',
        '(?:our|the) {year} (?:prices?|rates?|fees?|price list|programme|program|schedule|season|menu|brochure|range|collection|timetable|offers?|events?|courses?|dates)',
        '{year} (?:prices?|rates?|fees?|price list|programme|program|season|menu|brochure|timetable|edition|dates)',
        '(?:prices?|rates?|fees?) (?:for|in) {year}',
        'as (?:of|at) {year}',
        '(?:for|in) {year},? (?:we|our team) (?:are|\'re|offer|have|will|run|open)',
        '(?:coming|launching|opening|available|starting) (?:in |from )?{year}',
        '(?:bookings?|booking) (?:for|in) {year}',
        'now (?:taking|booking|open)(?: bookings?)? for {year}',
        '(?:updated|valid) (?:for|in) {year}',
    ],
    'history' => [
        'since {year}',
        '(?:founded|established|est\.?|started|opened|built|launched|began|set up|formed) (?:in |back in )?{year}',
        'in {year},? (?:we|they|it|he|she|the \w+) (?:\w+ed|were|was|had|did|won|became|went|made|took|began)\b',
        'back in {year}',
        '(?:from|between) {year}',
        '(?:until|till|up to) {year}',
        '{year} ?- ?(?:19|20)\d\d',
        '(?:in|of|from) (?:the )?(?:early|late|mid|spring|summer|autumn|fall|winter) (?:of )?{year}',
    ],
    'relative' => [
        'this year', 'next year', 'later this year', 'this season', 'next season',
        'this spring', 'this summer', 'this autumn', 'this fall', 'this winter',
        'next spring', 'next summer', 'next autumn', 'next fall', 'next winter',
        'currently', 'at the moment', 'at present', 'coming soon', 'recently',
        'just launched', 'brand new', 'newly opened', 'upcoming',
    ],
    'closing' => [
        'closes?', 'closing(?: date)?', 'deadline', 'ends?', 'ending', 'until', 'apply by', 'applications close',
        'open until', 'runs? until', 'last day', 'expires?', 'valid until', 'no later than', 'book by', 'before',
    ],
    'months' => [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
        'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9,
        'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ],
    'counts' => [
        'team of {n}',
        '{n} (?:designers|staff|people|employees|members of staff|engineers|consultants|experts|specialists|gardeners|architects|lawyers|solicitors|developers|volunteers|teachers|nurses|doctors)',
        '(?:over|more than|almost|nearly|around|about) {n} (?:years|clients|customers|projects|gardens|homes|members|countries|locations|branches|offices|shops|stores|awards|staff|people)',
        '{n}\+? (?:years\'? experience|years in business|happy clients|happy customers|clients|customers|projects|locations|branches|offices|shops|stores)',
        '(?:from|only|just) [£$€] ?{n}',
    ],
    'numbers' => [
        'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'fifteen' => 15, 'twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50,
    ],
    'link_text' => ['here', 'click here', 'click', 'read more', 'more', 'this link', 'link', 'learn more', 'find out more', 'more info', 'this page', 'go'],
    'long_sentence' => 30,
    'stop_words' => [
        'a', 'an', 'the', 'and', 'or', 'but', 'nor', 'so', 'yet', 'of', 'to', 'in', 'on', 'at', 'by', 'for', 'with',
        'from', 'into', 'onto', 'over', 'under', 'about', 'above', 'below', 'after', 'before', 'between', 'through',
        'during', 'without', 'within', 'along', 'across', 'around', 'against', 'among', 'than', 'then', 'that',
        'this', 'these', 'those', 'there', 'here', 'it', 'its', 'it\'s', 'is', 'are', 'was', 'were', 'be', 'been',
        'being', 'am', 'do', 'does', 'did', 'doing', 'have', 'has', 'had', 'having', 'can', 'could', 'will', 'would',
        'shall', 'should', 'may', 'might', 'must', 'not', 'no', 'yes', 'your', 'you', 'yours', 'we', 'our', 'ours',
        'us', 'they', 'their', 'them', 'he', 'she', 'his', 'her', 'i', 'me', 'my', 'mine', 'what', 'which', 'who',
        'whom', 'whose', 'when', 'where', 'why', 'how', 'all', 'any', 'both', 'each', 'every', 'few', 'more', 'most',
        'other', 'some', 'such', 'only', 'own', 'same', 'very', 'just', 'also', 'too', 'as', 'if', 'out', 'up',
        'down', 'off', 'again', 'further', 'once',
    ],
    'utility_slugs' => [
        'search', '404', 'not-found', 'error', 'login', 'log-in', 'signin', 'sign-in', 'logout', 'log-out',
        'signout', 'sign-out', 'account', 'my-account', 'register', 'signup', 'sign-up', 'cart', 'basket', 'checkout',
        'thank-you', 'thanks', 'thankyou', 'confirmation', 'order-confirmation', 'unsubscribe', 'sitemap', 'password',
        'reset-password', 'forgot-password',
    ],
    // Words photo libraries put in titles that say nothing about the photo: left out of file names (Seo\FilenameRules).
    'filename_noise' => ['stock photo', 'stock photograph', 'stock image', 'stock picture', 'royalty free', 'royalty-free', 'free photo', 'free image', 'image of', 'photo of', 'picture of', 'photograph of', 'close up', 'close-up', 'high resolution', 'hd', '4k', 'copy space', 'unsplash', 'pexels', 'pixabay', 'shutterstock', 'getty images', 'istock', 'adobe stock', 'openverse'],
];
