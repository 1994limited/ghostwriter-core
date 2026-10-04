<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Suggest;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeProvider;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Checks\StatedCounts;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Phrases;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The phrase checks' tables, in each of the five languages: what each
 * finds, and what it must leave alone (history, a year on its own, a
 * recent entry, a date still to come). "Now" is 4 October 2026; the entry
 * was last saved on 14 March 2024 unless a row says otherwise.
 */
final class FreeChecksTest extends TestCase
{
    public const NOW = '2026-10-04 10:00:00';

    public const UPDATED = '2024-03-14';

    /**
     * language, kind, text, the quote found (null: nothing of that kind), updated.
     *
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: ?string, 4?: string}>
     */
    public static function rows(): iterable
    {
        // English.
        yield 'en past year: new for' => ['en', 'past-year', 'New for 2024: winter care visits.', 'New for 2024'];
        yield 'en past year: our prices' => ['en', 'past-year', 'See our 2025 prices below.', 'our 2025 prices'];
        yield 'en past year: as of' => ['en', 'past-year', 'As of 2023, we cover the whole county.', 'As of 2023'];
        yield 'en past year: since is history' => ['en', 'past-year', 'Designing gardens since 2015.', null];
        yield 'en past year: founded is history' => ['en', 'past-year', 'Northfold was founded in 2009 and we are still here.', null];
        yield 'en past year: a year alone' => ['en', 'past-year', 'See our 2023 show garden.', null];
        yield 'en past year: this year is fine' => ['en', 'past-year', 'New for 2026: winter care visits.', null];
        yield 'en past year: in 2019 we won' => ['en', 'past-year', 'In 2019 we won a gold medal at Chelsea.', null];
        yield 'en relative: this year' => ['en', 'relative-time', 'This year we are planting more natives.', 'This year'];
        yield 'en relative: coming soon' => ['en', 'relative-time', 'Our planting guide is coming soon.', 'coming soon'];
        yield 'en relative: recent entry' => ['en', 'relative-time', 'This year we are planting more natives.', null, '2026-06-01'];
        yield 'en closing: applications close' => ['en', 'closing-date', 'Applications close 31 January 2025.', 'Applications close 31 January 2025'];
        yield 'en closing: US order' => ['en', 'closing-date', 'The offer is valid until March 3, 2025.', 'valid until March 3, 2025'];
        yield 'en closing: still to come' => ['en', 'closing-date', 'Applications close 31 January 2027.', null];
        yield 'en closing: a date with no cue' => ['en', 'closing-date', 'We planted the garden on 31 January 2025.', null];
        yield 'en count: team of' => ['en', 'stated-count', 'A full design from our team of 6 designers.', 'team of 6'];
        yield 'en count: over years' => ['en', 'stated-count', 'We have been doing this for over 20 years.', 'over 20 years'];
        yield 'en count: in words' => ['en', 'stated-count', 'There are six designers on the team.', 'six designers'];
        yield 'en count: price' => ['en', 'stated-count', 'Planting plans from £450.', 'from £450'];
        yield 'en count: recent entry' => ['en', 'stated-count', 'A full design from our team of 6 designers.', null, '2026-02-01'];
        yield 'en long sentence' => ['en', 'long-sentence', 'In terms of the actual process involved, what typically happens is that we will first of all come out and visit the garden in person, after which we will then go away and produce a concept for you.', 'In terms of the actual process involved'];
        yield 'en short sentence' => ['en', 'long-sentence', 'We visit, then we draw.', null];
        yield 'en link text: click here' => ['en', 'empty-link-text', 'To book, [click here](entry::contact).', 'click here'];
        yield 'en link text: fine' => ['en', 'empty-link-text', 'To book, [get in touch](entry::contact).', null];

        // German.
        yield 'de past year: neu für' => ['de', 'past-year', 'Neu für 2024: Winterpflege im Garten.', 'Neu für 2024'];
        yield 'de past year: preise' => ['de', 'past-year', 'Hier finden Sie unsere Preise 2025.', 'unsere Preise 2025'];
        yield 'de past year: seit' => ['de', 'past-year', 'Wir gestalten Gärten seit 2015.', null];
        yield 'de past year: gegründet' => ['de', 'past-year', 'Das Büro wurde 2009 gegründet.', null];
        yield 'de relative: dieses jahr' => ['de', 'relative-time', 'Dieses Jahr pflanzen wir mehr heimische Arten.', 'Dieses Jahr'];
        yield 'de relative: derzeit' => ['de', 'relative-time', 'Derzeit nehmen wir keine Aufträge an.', 'Derzeit'];
        yield 'de closing: bis zum' => ['de', 'closing-date', 'Bewerbungen bis zum 31.01.2025 an uns.', 'bis zum 31.01.2025'];
        yield 'de closing: anmeldeschluss' => ['de', 'closing-date', 'Anmeldeschluss ist der 31. Januar 2025.', 'Anmeldeschluss ist der 31. Januar 2025'];
        yield 'de closing: future' => ['de', 'closing-date', 'Bewerbungen bis zum 31.01.2027 an uns.', null];
        yield 'de count: team aus' => ['de', 'stated-count', 'Ein Team aus 6 Designern plant Ihren Garten.', 'Team aus 6'];
        yield 'de count: mitarbeiter' => ['de', 'stated-count', 'Wir haben 12 Mitarbeiter in Hamburg.', '12 Mitarbeiter'];
        yield 'de count: über jahre' => ['de', 'stated-count', 'Seit über 20 Jahren planen wir Gärten.', 'über 20 Jahren'];
        yield 'de long sentence' => ['de', 'long-sentence', 'Wenn wir den Garten zum ersten Mal besuchen, schauen wir uns die Beete, die Bäume, den Boden und das Licht sehr genau an, damit wir danach in aller Ruhe ein Konzept entwickeln können, das wirklich zu Ihnen passt.', 'Wenn wir den Garten zum ersten Mal besuchen'];
        yield 'de link text: hier' => ['de', 'empty-link-text', 'Termine finden Sie [hier](entry::termine).', 'hier'];

        // French.
        yield 'fr past year: nouveauté' => ['fr', 'past-year', 'Nouveauté 2024 : des visites d\'entretien en hiver.', 'Nouveauté 2024'];
        yield 'fr past year: tarifs' => ['fr', 'past-year', 'Consultez nos tarifs 2025 ci-dessous.', 'nos tarifs 2025'];
        yield 'fr past year: depuis' => ['fr', 'past-year', 'Nous créons des jardins depuis 2015.', null];
        yield 'fr past year: fondée en' => ['fr', 'past-year', 'L\'agence a été fondée en 2009.', null];
        yield 'fr relative: cette année' => ['fr', 'relative-time', 'Cette année, nous plantons davantage d\'espèces locales.', 'Cette année'];
        yield 'fr relative: actuellement' => ['fr', 'relative-time', 'Nous recrutons actuellement deux jardiniers.', 'actuellement'];
        yield 'fr closing: jusqu\'au' => ['fr', 'closing-date', 'Candidatures jusqu\'au 31 janvier 2025.', 'jusqu\'au 31 janvier 2025'];
        yield 'fr closing: date limite' => ['fr', 'closing-date', 'Date limite : 1er février 2025.', 'Date limite : 1er février 2025'];
        yield 'fr count: équipe de' => ['fr', 'stated-count', 'Une équipe de 6 paysagistes conçoit votre jardin.', 'équipe de 6'];
        yield 'fr count: plus de ans' => ['fr', 'stated-count', 'Depuis plus de 20 ans, nous créons des jardins.', 'plus de 20 ans'];
        yield 'fr long sentence' => ['fr', 'long-sentence', 'Lorsque nous venons voir votre jardin pour la première fois, nous prenons le temps d\'observer les massifs, les arbres, le sol et la lumière avec beaucoup d\'attention, afin de pouvoir ensuite proposer tranquillement un projet qui vous ressemble vraiment et qui durera longtemps.', 'Lorsque nous venons voir votre jardin'];
        yield 'fr link text: cliquez ici' => ['fr', 'empty-link-text', 'Pour réserver, [cliquez ici](entry::contact).', 'cliquez ici'];

        // Dutch.
        yield 'nl past year: nieuw in' => ['nl', 'past-year', 'Nieuw in 2024: winteronderhoud aan huis.', 'Nieuw in 2024'];
        yield 'nl past year: prijzen' => ['nl', 'past-year', 'Bekijk hieronder onze prijzen voor 2025.', 'onze prijzen voor 2025'];
        yield 'nl past year: sinds' => ['nl', 'past-year', 'Wij ontwerpen tuinen sinds 2015.', null];
        yield 'nl past year: opgericht' => ['nl', 'past-year', 'Het bureau is opgericht in 2009.', null];
        yield 'nl relative: dit jaar' => ['nl', 'relative-time', 'Dit jaar planten we meer inheemse soorten.', 'Dit jaar'];
        yield 'nl relative: binnenkort' => ['nl', 'relative-time', 'Onze plantgids verschijnt binnenkort.', 'binnenkort'];
        yield 'nl closing: tot en met' => ['nl', 'closing-date', 'Aanmelden kan tot en met 31 januari 2025.', 'tot en met 31 januari 2025'];
        yield 'nl closing: uiterlijk' => ['nl', 'closing-date', 'Reageer uiterlijk 31-01-2025 is geen datum.', null];
        yield 'nl count: team van' => ['nl', 'stated-count', 'Een team van 6 ontwerpers tekent uw tuin.', 'team van 6'];
        yield 'nl count: meer dan jaar' => ['nl', 'stated-count', 'Al meer dan 20 jaar ontwerpen wij tuinen.', 'meer dan 20 jaar'];
        yield 'nl long sentence' => ['nl', 'long-sentence', 'Wanneer we uw tuin voor de eerste keer bezoeken, kijken we heel zorgvuldig naar de borders, de bomen, de bodem en het licht, zodat we daarna in alle rust een ontwerp kunnen maken dat echt bij u past.', 'Wanneer we uw tuin voor de eerste keer bezoeken'];
        yield 'nl link text: klik hier' => ['nl', 'empty-link-text', 'Om te boeken, [klik hier](entry::contact).', 'klik hier'];

        // Spanish.
        yield 'es past year: novedad' => ['es', 'past-year', 'Novedad 2024: visitas de mantenimiento en invierno.', 'Novedad 2024'];
        yield 'es past year: precios' => ['es', 'past-year', 'Consulte nuestros precios para 2025.', 'nuestros precios para 2025'];
        yield 'es past year: desde' => ['es', 'past-year', 'Diseñamos jardines desde 2015.', null];
        yield 'es past year: fundada en' => ['es', 'past-year', 'La empresa fue fundada en 2009.', null];
        yield 'es relative: este año' => ['es', 'relative-time', 'Este año plantamos más especies autóctonas.', 'Este año'];
        yield 'es relative: próximamente' => ['es', 'relative-time', 'Nuestra guía de plantación llegará próximamente.', 'próximamente'];
        yield 'es closing: hasta el' => ['es', 'closing-date', 'Inscripciones hasta el 31 de enero de 2025.', 'Inscripciones hasta el 31 de enero de 2025'];
        yield 'es count: equipo de' => ['es', 'stated-count', 'Un equipo de 6 diseñadores proyecta su jardín.', 'equipo de 6'];
        yield 'es count: más de años' => ['es', 'stated-count', 'Llevamos más de 20 años diseñando jardines.', 'más de 20 años'];
        yield 'es long sentence' => ['es', 'long-sentence', 'Cuando visitamos su jardín por primera vez, observamos con mucha atención los parterres, los árboles, el suelo y la luz, para después poder preparar con calma un proyecto que de verdad se parezca a usted y que dure muchos años en el tiempo sin problemas.', 'Cuando visitamos su jardín por primera vez'];
        yield 'es link text: haz clic aquí' => ['es', 'empty-link-text', 'Para reservar, [haz clic aquí](entry::contact).', 'haz clic aquí'];
    }

    #[DataProvider('rows')]
    public function test_the_table(string $language, string $kind, string $text, ?string $quote, string $updated = self::UPDATED): void
    {
        $findings = array_values(array_filter(Findings::standard()->find(self::context($text, $language, $updated)), fn (Finding $finding) => $finding->kind === $kind));

        if ($quote === null) {
            $this->assertSame([], array_map(fn (Finding $finding) => $finding->anchor->quote?->exact, $findings));

            return;
        }

        $this->assertCount(1, $findings, "One {$kind} in “{$text}”.");
        $finding = $findings[0];
        $this->assertNotNull($finding->anchor->quote);
        $this->assertStringStartsWith($quote, $finding->anchor->quote->exact);

        // The quote is found again in the field as stored, as the front end finds it.
        $match = (new QuoteFinder)->find($finding->anchor->quote, $text, $finding->anchor->occurrence, markdown: true);
        $this->assertNotNull($match, 'The anchor is found in the field.');
        $this->assertFalse($match->fuzzy);
    }

    public function test_every_language_has_every_list(): void
    {
        foreach (Phrases::LANGUAGES as $language) {
            $phrases = Phrases::for($language);
            $this->assertNotNull($phrases, $language);

            foreach (['current', 'history', 'relative', 'closing', 'months', 'counts', 'numbers', 'linkText'] as $list) {
                $this->assertNotEmpty($phrases->{$list}, "{$language} {$list}");
            }

            foreach ([...$phrases->current, ...$phrases->history, ...$phrases->counts, ...$phrases->closing] as $fragment) {
                $this->assertNotFalse(@preg_match('/'.strtr($fragment, ['{year}' => '2024', '{n}' => '6']).'/iu', ''), "{$language}: {$fragment}");
            }
        }
    }

    public function test_a_locale_reads_its_language_and_others_get_no_phrase_checks(): void
    {
        $this->assertSame('de', Phrases::for('de_DE')?->language);
        $this->assertSame('en', Phrases::for('en-GB')?->language);
        $this->assertNull(Phrases::for('it'));

        $kinds = array_map(fn (Finding $finding) => $finding->kind, Findings::standard()->find(self::context('Nuovo per 2024: in questo momento il nostro team di 6 persone.', 'it')));
        $this->assertSame([], $kinds);
    }

    public function test_the_claim_switch_turns_counts_off(): void
    {
        $text = 'A full design from our team of 6 designers.';

        $this->assertCount(1, (new Findings([new StatedCounts]))->find(self::context($text)));
        $this->assertSame([], (new Findings([new StatedCounts]))->find(self::context($text, options: new SuggestOptions(claims: false))));
    }

    public function test_a_count_and_a_closing_date_carry_their_template(): void
    {
        $findings = Findings::standard()->find(self::context('A full design from our team of 6 designers. Applications close 31 January 2025.'));
        $byKind = [];

        foreach ($findings as $finding) {
            $byKind[$finding->kind] = $finding;
        }

        $this->assertSame('team of {answer}', $byKind['stated-count']->meta['template']);
        $this->assertSame('number', $byKind['stated-count']->meta['answer']);
        $this->assertSame(6, $byKind['stated-count']->meta['number']);
        $this->assertSame('Applications close {answer}', $byKind['closing-date']->meta['template']);
        $this->assertSame('2025-01-31', $byKind['closing-date']->meta['date']);
    }

    public function test_in_a_dated_group_a_year_the_entry_was_written_in_is_history(): void
    {
        $text = 'New for 2023: the show garden.';
        $journal = new EntryRef('journal', 'j1');

        $this->assertCount(1, self::ofKind('past-year', self::context($text, updated: '2023-05-01', entry: $journal)), 'Not dated: flagged.');
        $this->assertSame([], self::ofKind('past-year', self::context($text, updated: '2023-05-01', entry: $journal, age: AgePolicy::fromGroups(['journal']))), 'Dated: history.');
        $this->assertCount(1, self::ofKind('past-year', self::context($text, updated: '2023-05-01', entry: $journal, age: AgePolicy::fromGroups(['journal'], ['journal']))), 'The switch off: flagged.');
        $this->assertCount(1, self::ofKind('past-year', self::context('New for 2022: the show garden.', updated: '2023-05-01', entry: $journal, age: AgePolicy::fromGroups(['journal']))), 'A year before it was written still is.');
    }

    public function test_free_checks_never_call_anything(): void
    {
        $fake = new FakeProvider;
        Findings::standard()->report(self::context('New for 2024. Our team of 6 designers. [click here](entry::x)'));

        $fake->assertNothingSent();
    }

    /**
     * @return list<Finding>
     */
    private static function ofKind(string $kind, CheckContext $context): array
    {
        return array_values(array_filter(Findings::standard()->find($context), fn (Finding $finding) => $finding->kind === $kind));
    }

    public static function context(string $text, string $language = 'en', string $updated = self::UPDATED, ?SuggestOptions $options = null, ?EntryRef $entry = null, ?AgePolicy $age = null): CheckContext
    {
        $schema = new Schema([new Field('body', Kind::LongText, 'Body', type: 'markdown')]);

        return new CheckContext(
            gaps: new GapContext(schema: $schema, entry: new EntryData(['body' => $text])),
            now: new DateTimeImmutable(self::NOW),
            updatedAt: new DateTimeImmutable($updated),
            language: $language,
            entry: $entry,
            age: $age ?? new AgePolicy,
            options: $options ?? new SuggestOptions,
        );
    }

    public function test_gap_finder_standard_has_the_two_new_detectors(): void
    {
        $classes = array_map(fn ($detector) => (new \ReflectionClass($detector))->getShortName(), GapFinder::standard()->detectors());

        $this->assertContains('MissingAlt', $classes);
        $this->assertContains('SeoLength', $classes);
    }
}
