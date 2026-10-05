<?php

/*
 * French for the SEO layer's Suggest edits strings (keys without the `suggest.` prefix).
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'category.seo' => 'SEO',
    'speech.seo' => 'Pour la recherche',
    'finding.seo-length' => ':label fait :length caractères ; la limite est de :limit.',
    'finding.seo-empty' => ':label est vide. Les pages de ce type le remplissent.',
    'finding.seo-missing' => 'La description SEO est vide : les moteurs de recherche en choisiront une eux-mêmes.',
    'finding.seo-missing-inherited' => 'La description SEO vient de :field, qui est vide : les moteurs de recherche en choisiront une eux-mêmes.',
    'finding.seo-missing-short' => 'La description SEO ne fait que :length caractères. Visez :min à :max.',
    'finding.heading-long' => 'Ce titre fait :length caractères. Au-delà de 70, il se lit mal en diagonale et il est coupé dans les résultats de recherche.',
    'finding.few-links' => 'Cette page ne renvoie vers aucune de vos autres pages.',
];
