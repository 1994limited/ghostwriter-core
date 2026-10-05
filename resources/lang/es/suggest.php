<?php

/*
 * Spanish for the SEO layer's Suggest edits strings (keys without the `suggest.` prefix).
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'category.seo' => 'SEO',
    'speech.seo' => 'Para buscadores',
    'finding.seo-length' => ':label tiene :length caracteres; el límite es :limit.',
    'finding.seo-empty' => ':label está vacío. Las páginas como esta lo rellenan.',
    'finding.seo-missing' => 'La descripción SEO está vacía, así que los buscadores elegirán su propio texto.',
    'finding.seo-missing-inherited' => 'La descripción SEO sale de :field, que está vacío, así que los buscadores elegirán su propio texto.',
    'finding.seo-missing-short' => 'La descripción SEO solo tiene :length caracteres. Lo ideal son de :min a :max.',
    'finding.heading-long' => 'Este encabezado tiene :length caracteres. Con más de 70 cuesta leerlo de un vistazo y se corta en los resultados de búsqueda.',
    'finding.few-links' => 'Esta página no enlaza a ninguna de sus otras páginas.',
];
