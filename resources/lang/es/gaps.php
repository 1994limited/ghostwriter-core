<?php

/*
 * Spanish for the SEO layer's "Finish this page" strings (keys without the `gaps.` prefix).
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'seo-length' => ':label es demasiado largo.',
    'seo-missing' => 'La descripción SEO está vacía, así que los buscadores elegirán su propio texto.',
    'seo-missing.draft' => 'La descripción SEO está vacía, así que los buscadores elegirán su propio texto. Aquí tiene la del borrador: «:text»',
    'seo-missing.short' => 'La descripción SEO solo tiene :length caracteres. Los resultados de búsqueda muestran hasta unos :limit.',
    'seo-missing.short-draft' => 'La descripción SEO solo tiene :length caracteres. Aquí tiene la del borrador: «:text»',
    'seo-missing.inherited' => 'La descripción SEO sale de :field, que está vacío, así que los buscadores elegirán su propio texto.',
    'seo-missing.inherited-draft' => 'La descripción SEO sale de :field, que está vacío. Aquí tiene una para esta página: «:text»',
    'heading-long' => 'Este encabezado en :label tiene :length caracteres. Con más de 70 cuesta leerlo de un vistazo y se corta en los resultados de búsqueda.',
    'few-links' => 'Esta página no enlaza a ninguna de sus otras páginas. Unos pocos enlaces ayudan a los lectores, y a los buscadores, a encontrar páginas relacionadas.',
    'links-added' => 'Revise los :count enlaces que añadió Ghostwriter. «:words» lleva a :title (:url). Consérvelo, o quite el enlace y conserve las palabras.',
    'links-added-one' => 'Revise el enlace que añadió Ghostwriter. «:words» lleva a :title (:url). Consérvelo, o quite el enlace y conserve las palabras.',
    'speech.seo-length' => 'Muy largo',
    'speech.seo-missing' => '¡Vacío!',
    'speech.links-added' => 'Enlazado',
    'speech.heading-long' => 'Muy largo',
    'speech.few-links' => 'Enlázame',
    'step.seo-missing' => 'Añadir una descripción para buscadores',
    'step.heading-long' => 'Acortar un encabezado',
    'step.few-links' => 'Enlazar a sus otras páginas',
    'fix.keep-link' => 'Conservarlo',
    'fix.use-text' => 'Usar este texto',
    'fix.add-links' => 'Añadir un enlace',
    'fix.skip' => 'Omitir',
];
