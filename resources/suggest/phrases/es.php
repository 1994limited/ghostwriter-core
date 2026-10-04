<?php

/*
 * Spanish phrase lists for the free checks. See en.php for what each list is.
 */

return [
    'current' => [
        'nuev[oa]s? (?:para |en |de )?{year}',
        'novedad(?:es)? (?:para |de |en )?{year}',
        '(?:nuestros |nuestras |los |las )?(?:precios|tarifas|programa|programación|temporada|catálogo|horarios|menú|ofertas?|cursos) (?:para |de |del |en )?{year}',
        'temporada {year}',
        'edición {year}',
        '(?:en|para) {year},? (?:ofrecemos|tenemos|somos|abrimos)',
        'reservas? (?:para|en) {year}',
        '(?:válido|actualizado) (?:para |en |desde )?{year}',
    ],
    'history' => [
        'desde {year}',
        '(?:fundad[oa]s?|cread[oa]s?|establecid[oa]s?|abiert[oa]s?|construid[oa]s?|inaugurad[oa]s?|nacid[oa]s?) en {year}',
        'en {year},? (?:fue|fuimos|abrimos|ganamos|se|nos|empezamos|comenzamos|era|éramos)\b',
        'de {year} a',
        'hasta {year}',
        '{year} ?- ?(?:19|20)\d\d',
        '(?:a principios|a finales|a mediados|en la primavera|en el verano|en el otoño|en el invierno) de {year}',
    ],
    'relative' => [
        'este año', 'el próximo año', 'el año que viene', 'esta temporada', 'actualmente', 'en este momento', 'en la actualidad',
        'próximamente', 'pronto', 'recientemente', 'recién inaugurad[oa]', 'totalmente nuev[oa]', 'este verano', 'este invierno',
        'este otoño', 'esta primavera', 'el próximo verano', 'a finales de este año',
    ],
    'closing' => [
        'hasta el', 'fecha límite', 'plazo(?: hasta el)?', 'cierra el', 'termina el', 'válido hasta el', 'antes del',
        'inscripciones hasta el', 'a más tardar el', 'vence el',
    ],
    'months' => [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        'ene' => 1, 'feb' => 2, 'mar' => 3, 'abr' => 4, 'jun' => 6, 'jul' => 7, 'ago' => 8, 'sep' => 9, 'sept' => 9,
        'oct' => 10, 'nov' => 11, 'dic' => 12,
    ],
    'counts' => [
        'equipo de {n}',
        '{n} (?:empleados|trabajadores|diseñadores|diseñadoras|expertos|especialistas|jardineros|arquitectos|abogados|desarrolladores|voluntarios|profesionales)',
        '(?:más de|casi|cerca de|unos|unas|alrededor de) {n} (?:años|clientes|proyectos|empleados|oficinas|tiendas|países|premios)',
        '{n}\+? (?:años de experiencia|clientes satisfechos|clientes|proyectos|oficinas|tiendas)',
        '(?:desde|solo|sólo|por solo) {n} ?€',
    ],
    'numbers' => [
        'dos' => 2, 'tres' => 3, 'cuatro' => 4, 'cinco' => 5, 'seis' => 6, 'siete' => 7, 'ocho' => 8, 'nueve' => 9,
        'diez' => 10, 'once' => 11, 'doce' => 12, 'quince' => 15, 'veinte' => 20, 'treinta' => 30, 'cincuenta' => 50,
    ],
    'link_text' => ['aquí', 'haz clic aquí', 'pulsa aquí', 'clic aquí', 'leer más', 'más', 'más información', 'este enlace', 'enlace', 'ver más'],
    'long_sentence' => 35,
];
