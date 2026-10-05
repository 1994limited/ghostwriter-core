<?php

/*
 * Spanish for the SEO layer's strings (`seo.*`): the after-draft notice, the links Ghostwriter added and the Search section.
 * Only the SEO strings are translated (decision 18); a key that isn't
 * here falls back to English. Parameters are kept as they are (`:label`).
 */

return [
    'notice.links' => 'He enlazado :count de sus páginas: :titles.',
    'notice.links-one' => 'He enlazado una de sus páginas: :titles.',
    'notice.no-links' => 'No he encontrado páginas lo bastante relacionadas para enlazarlas.',
    'status.checking' => 'Borrador listo. Estoy revisando los encabezados y los enlaces…',
    'link.added' => 'Añadido por Ghostwriter',
    'link.added-long' => 'Añadido por Ghostwriter. Si lo quita, las palabras se quedan.',
    'link.remove' => 'Quitar el enlace',
    'link.open' => 'Abrir la página',
    'link.removed' => 'Enlace quitado. Las palabras se quedan.',
    'search.heading' => 'Búsqueda',
    'search.intro' => 'Así puede aparecer esta página en los resultados de búsqueda. Aquí no se guarda nada hasta que use el borrador.',
    'search.title' => 'Título SEO',
    'search.description' => 'Meta descripción',
    'search.address' => 'Dirección',
    'search.uses-title' => 'Usa el título de la página: «:title»',
    'search.title-fits' => 'Cabe, así que su configuración SEO lo sigue usando.',
    'search.title-long' => 'El título de la página es demasiado largo para los resultados de búsqueda, así que la página necesita uno propio.',
    'search.title-own' => 'Un título propio, más corto que el de la página.',
    'search.title-stays' => 'Su título SEO se queda. Sugerencia en su lugar:',
    'search.give-own' => 'Darle uno propio',
    'search.use-page-title' => 'Usar el título de la página',
    'search.description-new' => 'Solo lo que dice la página.',
    'search.description-source-empty' => 'Salía de :field, que está vacío, así que esta página tiene la suya. Solo lo que dice la página.',
    'search.description-stays' => 'Su descripción SEO se queda. Sugerencia en su lugar:',
    'search.description-inherits' => 'Usa :field. Cabe, así que su configuración SEO la sigue usando.',
    'search.description-dropped' => 'No he podido escribir una con lo que dice la página. Vuelva a intentarlo, o escriba la suya.',
    'search.description-empty' => 'Vacía: «Terminar esta página» le ofrecerá una.',
    'search.edited' => 'Ahora es suyo: un turno posterior no lo reescribirá.',
    'search.template' => 'Su configuración SEO lo genera.',
    'search.off' => 'Desactivado en su configuración SEO.',
    'search.use-this' => 'Usar este texto',
    'search.keep-mine' => 'Conservar el mío',
    'search.range' => 'Lo ideal son de :min a :max caracteres',
    'search.address-new' => 'Solo se fija en esta página nueva. Las páginas publicadas conservan su dirección.',
    'search.address-kept' => 'Las páginas publicadas conservan su dirección.',
    'search.try-again' => 'Volver a intentarlo',
    'search.try-again-note' => 'Pide otro título y otra descripción. Usa Ghostwriter.',
    'search.writing' => 'Escribiendo otro…',
    'search.failed' => 'No ha funcionado. Vuelva a intentarlo en un momento.',
];
