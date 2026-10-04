<?php

/*
 * German phrase lists for the free checks. See en.php for what each list is.
 */

return [
    'current' => [
        'neu (?:für|in|ab|im jahr) {year}',
        'neu {year}',
        '(?:unsere|die) (?:preise|tarife|gebühren|preisliste|programm|saison|termine|kurse|angebote) (?:für |in )?{year}',
        '(?:preise|tarife|gebühren|preisliste|programm|saison|termine|katalog|ausgabe|kurse) {year}',
        'stand:? {year}',
        '(?:in|für) {year} (?:bieten|haben|sind|planen) wir',
        'ab {year} (?:bieten|gibt|gilt|gelten|sind|haben)',
        'buchungen (?:für|in) {year}',
        '(?:gültig|aktualisiert) (?:für|in|ab) {year}',
    ],
    'history' => [
        'seit {year}',
        '(?:gegründet|gegr\.?|eröffnet|gebaut|gestartet|entstanden) (?:im jahr |in )?{year}',
        '{year} (?:gegründet|eröffnet|gebaut|gestartet)',
        '(?:im jahr )?{year} (?:wurde|wurden|haben wir|hat|hatte|war|waren|gewann|gewannen)\b',
        'von {year} bis',
        'bis {year}',
        '{year} ?- ?(?:19|20)\d\d',
        '(?:anfang|ende|mitte|frühjahr|sommer|herbst|winter) {year}',
    ],
    'relative' => [
        'dieses jahr', 'diesem jahr', 'dieses jahres', 'nächstes jahr', 'nächsten jahr', 'im kommenden jahr', 'diese saison',
        'diesen sommer', 'diesen winter', 'diesen herbst', 'diesen frühling', 'nächsten sommer', 'nächsten winter',
        'derzeit', 'zurzeit', 'momentan', 'demnächst', 'in kürze', 'kürzlich', 'neu eröffnet', 'brandneu', 'in diesem jahr',
    ],
    'closing' => [
        'bis(?: zum)?', 'endet(?: am)?', 'einsendeschluss', 'anmeldeschluss', 'bewerbungsschluss', 'frist', 'gültig bis',
        'läuft bis', 'letzter tag', 'spätestens(?: am)?', 'vor dem',
    ],
    'months' => [
        'januar' => 1, 'jänner' => 1, 'februar' => 2, 'märz' => 3, 'april' => 4, 'mai' => 5, 'juni' => 6, 'juli' => 7,
        'august' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'dezember' => 12,
        'jan' => 1, 'feb' => 2, 'mär' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9,
        'okt' => 10, 'nov' => 11, 'dez' => 12,
    ],
    'counts' => [
        'team (?:aus|von|mit) {n}',
        '{n} (?:mitarbeiter(?:innen)?|mitarbeitende|beschäftigte|designer(?:innen)?|experten|expertinnen|fachleute|gärtner(?:innen)?|architekt(?:inn)?en|anwälte|entwickler(?:innen)?|ehrenamtliche)',
        '(?:über|mehr als|fast|rund|knapp|etwa) {n} (?:jahre|jahren|kunden|kundinnen|projekte|projekten|mitarbeiter|standorte|filialen|länder|auszeichnungen)',
        '{n}\+? (?:jahre erfahrung|zufriedene kunden|kunden|projekte|standorte|filialen)',
        '(?:ab|nur|schon ab) {n} ?€',
        '(?:ab|nur) € ?{n}',
    ],
    'numbers' => [
        'zwei' => 2, 'drei' => 3, 'vier' => 4, 'fünf' => 5, 'sechs' => 6, 'sieben' => 7, 'acht' => 8, 'neun' => 9,
        'zehn' => 10, 'elf' => 11, 'zwölf' => 12, 'fünfzehn' => 15, 'zwanzig' => 20, 'dreißig' => 30, 'fünfzig' => 50,
    ],
    'link_text' => ['hier', 'hier klicken', 'klicken sie hier', 'mehr', 'weiterlesen', 'mehr erfahren', 'mehr lesen', 'link', 'dieser link', 'diesen link', 'mehr infos'],
    'long_sentence' => 25,
];
