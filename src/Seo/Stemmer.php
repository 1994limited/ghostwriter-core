<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Seo;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\Phrases;

/**
 * A word's stem, by the Snowball rules for English (Porter 2), German,
 * French, Dutch and Spanish, so "pruning" and "prune", "Gärten" and
 * "Garten", "jardins" and "jardin" meet (SEO layer §7.1, "Ranking"). Core
 * has no dependencies, so this is a small framework-free rendering of the
 * published algorithms rather than ext-stemmer; it follows them closely
 * enough for matching pages, not to the letter in every corner.
 *
 * A language it doesn't know keeps the old rule: the first five letters.
 * Words are expected in lower case (NormalisedText::words()).
 */
final class Stemmer
{
    /** The languages with a stemmer of their own. */
    public const LANGUAGES = ['en', 'de', 'fr', 'nl', 'es'];

    /** Letters kept of a word in a language without a stemmer. */
    public const PREFIX = 5;

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /** The language a locale is stemmed in ("en_GB" → "en"); null when it has no stemmer. */
    public static function language(?string $locale): ?string
    {
        if ($locale === null || trim($locale) === '') {
            return null;
        }

        $language = Phrases::language($locale);

        return in_array($language, self::LANGUAGES, true) ? $language : null;
    }

    public static function stem(string $word, ?string $locale = null): string
    {
        $language = self::language($locale);

        if ($language === null) {
            return mb_substr($word, 0, self::PREFIX);
        }

        if (isset(self::$cache[$language][$word])) {
            return self::$cache[$language][$word];
        }

        $stem = match ($language) {
            'en' => self::english($word),
            'de' => self::german($word),
            'fr' => self::french($word),
            'nl' => self::dutch($word),
            default => self::spanish($word),
        };

        if (count(self::$cache[$language] ?? []) > 20000) {
            self::$cache[$language] = [];
        }

        return self::$cache[$language][$word] = $stem === '' ? $word : $stem;
    }

    // English (Porter 2) ------------------------------------------------

    private const EN_VOWELS = 'aeiouy';

    private const EN_EXCEPTIONS = [
        'skis' => 'ski', 'skies' => 'sky', 'dying' => 'die', 'lying' => 'lie', 'tying' => 'tie',
        'idly' => 'idl', 'gently' => 'gentl', 'ugly' => 'ugli', 'early' => 'earli', 'only' => 'onli', 'singly' => 'singl',
        'sky' => 'sky', 'news' => 'news', 'howe' => 'howe', 'atlas' => 'atlas', 'cosmos' => 'cosmos', 'bias' => 'bias', 'andes' => 'andes',
    ];

    private const EN_INVARIANT = ['inning', 'outing', 'canning', 'herring', 'earring', 'proceed', 'exceed', 'succeed'];

    private static function english(string $w): string
    {
        $w = ltrim(str_replace('’', "'", $w), "'");

        if (strlen($w) <= 2 || preg_match('/[^a-z\']/', $w) === 1) {
            return $w;
        }

        if (isset(self::EN_EXCEPTIONS[$w])) {
            return self::EN_EXCEPTIONS[$w];
        }

        if ($w[0] === 'y') {
            $w = 'Y'.substr($w, 1);
        }

        for ($i = 1; $i < strlen($w); $i++) {
            if ($w[$i] === 'y' && self::enVowel($w[$i - 1])) {
                $w[$i] = 'Y';
            }
        }

        if (preg_match('/^(gener|commun|arsen)/', $w, $m) === 1) {
            $r1 = strlen($m[1]);
        } else {
            $r1 = self::region($w, 0, fn (string $c) => self::enVowel($c));
        }

        $r2 = self::region($w, $r1, fn (string $c) => self::enVowel($c));

        // Step 0.
        foreach (["'s'", "'s", "'"] as $suffix) {
            if (str_ends_with($w, $suffix)) {
                $w = substr($w, 0, -strlen($suffix));

                break;
            }
        }

        // Step 1a.
        if (str_ends_with($w, 'sses')) {
            $w = substr($w, 0, -2);
        } elseif (str_ends_with($w, 'ied') || str_ends_with($w, 'ies')) {
            $w = strlen($w) > 4 ? substr($w, 0, -2) : substr($w, 0, -1);
        } elseif (str_ends_with($w, 'us') || str_ends_with($w, 'ss')) {
            // kept
        } elseif (str_ends_with($w, 's') && preg_match('/[aeiouy]/', substr($w, 0, -2)) === 1) {
            $w = substr($w, 0, -1);
        }

        if (in_array($w, self::EN_INVARIANT, true)) {
            return $w;
        }

        // Step 1b.
        $suffix = self::longest($w, ['eedly', 'ingly', 'edly', 'eed', 'ing', 'ed']);

        if ($suffix === 'eed' || $suffix === 'eedly') {
            if (strlen($w) - strlen($suffix) >= $r1) {
                $w = substr($w, 0, -strlen($suffix)).'ee';
            }
        } elseif ($suffix !== null) {
            $stem = substr($w, 0, -strlen($suffix));

            if (preg_match('/[aeiouy]/', $stem) === 1) {
                $w = $stem;

                if (str_ends_with($w, 'at') || str_ends_with($w, 'bl') || str_ends_with($w, 'iz')) {
                    $w .= 'e';
                } elseif (preg_match('/(bb|dd|ff|gg|mm|nn|pp|rr|tt)$/', $w) === 1) {
                    $w = substr($w, 0, -1);
                } elseif ($r1 >= strlen($w) && self::enShortSyllable($w)) {
                    $w .= 'e';
                }
            }
        }

        // Step 1c.
        if (strlen($w) > 2 && ($w[-1] === 'y' || $w[-1] === 'Y') && ! self::enVowel($w[-2])) {
            $w = substr($w, 0, -1).'i';
        }

        // Step 2.
        $step2 = [
            'ization' => 'ize', 'ational' => 'ate', 'fulness' => 'ful', 'ousness' => 'ous', 'iveness' => 'ive',
            'tional' => 'tion', 'biliti' => 'ble', 'lessli' => 'less',
            'entli' => 'ent', 'ation' => 'ate', 'alism' => 'al', 'aliti' => 'al', 'ousli' => 'ous', 'iviti' => 'ive', 'fulli' => 'ful',
            'enci' => 'ence', 'anci' => 'ance', 'abli' => 'able', 'izer' => 'ize', 'ator' => 'ate', 'alli' => 'al',
            'bli' => 'ble', 'ogi' => 'og', 'li' => '',
        ];
        $suffix = self::longest($w, array_keys($step2));

        if ($suffix !== null && strlen($w) - strlen($suffix) >= $r1) {
            $stem = substr($w, 0, -strlen($suffix));

            if ($suffix === 'ogi') {
                if (str_ends_with($stem, 'l')) {
                    $w = $stem.'og';
                }
            } elseif ($suffix === 'li') {
                if ($stem !== '' && str_contains('cdeghkmnrt', $stem[-1])) {
                    $w = $stem;
                }
            } else {
                $w = $stem.$step2[$suffix];
            }
        }

        // Step 3.
        $step3 = ['ational' => 'ate', 'tional' => 'tion', 'alize' => 'al', 'icate' => 'ic', 'iciti' => 'ic', 'ative' => '', 'ical' => 'ic', 'ness' => '', 'ful' => ''];
        $suffix = self::longest($w, array_keys($step3));

        if ($suffix !== null && strlen($w) - strlen($suffix) >= $r1 && ($suffix !== 'ative' || strlen($w) - strlen($suffix) >= $r2)) {
            $w = substr($w, 0, -strlen($suffix)).$step3[$suffix];
        }

        // Step 4.
        $suffix = self::longest($w, ['ement', 'ance', 'ence', 'able', 'ible', 'ment', 'ant', 'ent', 'ism', 'ate', 'iti', 'ous', 'ive', 'ize', 'ion', 'al', 'er', 'ic']);

        if ($suffix !== null && strlen($w) - strlen($suffix) >= $r2) {
            $stem = substr($w, 0, -strlen($suffix));

            if ($suffix !== 'ion' || ($stem !== '' && ($stem[-1] === 's' || $stem[-1] === 't'))) {
                $w = $stem;
            }
        }

        // Step 5.
        if (str_ends_with($w, 'e')) {
            $at = strlen($w) - 1;

            if ($at >= $r2 || ($at >= $r1 && ! self::enShortSyllable(substr($w, 0, -1)))) {
                $w = substr($w, 0, -1);
            }
        } elseif (str_ends_with($w, 'll') && strlen($w) - 1 >= $r2) {
            $w = substr($w, 0, -1);
        }

        return strtolower($w);
    }

    private static function enVowel(string $c): bool
    {
        return $c !== '' && str_contains(self::EN_VOWELS, $c);
    }

    /** Ends in a short syllable: non-vowel, vowel, non-vowel other than w, x or Y; or a vowel and a non-vowel as the whole word. */
    private static function enShortSyllable(string $w): bool
    {
        $n = strlen($w);

        if ($n === 2) {
            return self::enVowel($w[0]) && ! self::enVowel($w[1]);
        }

        return $n >= 3 && ! self::enVowel($w[$n - 3]) && self::enVowel($w[$n - 2]) && ! self::enVowel($w[$n - 1]) && ! str_contains('wxY', $w[$n - 1]);
    }

    // German --------------------------------------------------------------

    private const DE_VOWELS = 'aeiouyäöü';

    private static function german(string $w): string
    {
        $w = str_replace('ß', 'ss', $w);
        $c = self::chars($w);
        $vowel = fn (string $ch) => str_contains(self::DE_VOWELS, $ch);

        for ($i = 1; $i < count($c) - 1; $i++) {
            if (($c[$i] === 'u' || $c[$i] === 'y') && $vowel($c[$i - 1]) && $vowel($c[$i + 1])) {
                $c[$i] = strtoupper($c[$i]);
            }
        }

        $w = implode('', $c);
        $r1 = max(3, self::region($w, 0, $vowel));
        $r2 = self::region($w, self::region($w, 0, $vowel), $vowel);
        $in = fn (string $word, string $suffix, int $region) => mb_strlen($word) - mb_strlen($suffix) >= $region;

        // Step 1.
        $suffix = self::longest($w, ['ern', 'em', 'er', 'en', 'es', 'e', 's']);

        if ($suffix !== null && $in($w, $suffix, $r1)) {
            $stem = mb_substr($w, 0, mb_strlen($w) - mb_strlen($suffix));

            if (in_array($suffix, ['em', 'ern', 'er'], true)) {
                $w = $stem;
            } elseif (in_array($suffix, ['e', 'en', 'es'], true)) {
                $w = str_ends_with($stem, 'niss') ? mb_substr($stem, 0, -1) : $stem;
            } elseif ($stem !== '' && str_contains('bdfghklmnrt', mb_substr($stem, -1))) {
                $w = $stem;
            }
        }

        // Step 2.
        $suffix = self::longest($w, ['est', 'en', 'er', 'st']);

        if ($suffix !== null && $in($w, $suffix, $r1)) {
            $stem = mb_substr($w, 0, mb_strlen($w) - mb_strlen($suffix));

            if ($suffix !== 'st') {
                $w = $stem;
            } elseif (mb_strlen($stem) > 3 && str_contains('bdfghklmnt', mb_substr($stem, -1))) {
                $w = $stem;
            }
        }

        // Step 3.
        $suffix = self::longest($w, ['isch', 'lich', 'heit', 'keit', 'end', 'ung', 'ig', 'ik']);

        if ($suffix !== null && $in($w, $suffix, $r2)) {
            $stem = mb_substr($w, 0, mb_strlen($w) - mb_strlen($suffix));

            if ($suffix === 'end' || $suffix === 'ung') {
                $w = $stem;

                if (str_ends_with($w, 'ig') && ! str_ends_with($w, 'eig') && $in($w, 'ig', $r2)) {
                    $w = mb_substr($w, 0, -2);
                }
            } elseif (in_array($suffix, ['ig', 'ik', 'isch'], true)) {
                if (! str_ends_with($stem, 'e')) {
                    $w = $stem;
                }
            } elseif ($suffix === 'lich' || $suffix === 'heit') {
                $w = $stem;

                if ((str_ends_with($w, 'er') || str_ends_with($w, 'en')) && $in($w, 'er', $r1)) {
                    $w = mb_substr($w, 0, -2);
                }
            } else {
                $w = $stem;

                foreach (['lich', 'ig'] as $before) {
                    if (str_ends_with($w, $before) && $in($w, $before, $r2)) {
                        $w = mb_substr($w, 0, mb_strlen($w) - mb_strlen($before));

                        break;
                    }
                }
            }
        }

        return strtr($w, ['U' => 'u', 'Y' => 'y', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u']);
    }

    // Dutch ---------------------------------------------------------------

    private const NL_VOWELS = 'aeiouyè';

    private static function dutch(string $w): string
    {
        $w = strtr($w, ['ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $vowel = fn (string $ch) => $ch !== '' && str_contains(self::NL_VOWELS, $ch);
        $c = self::chars($w);

        if (($c[0] ?? '') === 'y') {
            $c[0] = 'Y';
        }

        for ($i = 1; $i < count($c); $i++) {
            if ($c[$i] === 'y' && $vowel($c[$i - 1])) {
                $c[$i] = 'Y';
            } elseif ($c[$i] === 'i' && $vowel($c[$i - 1]) && $i + 1 < count($c) && $vowel($c[$i + 1])) {
                $c[$i] = 'I';
            }
        }

        $w = implode('', $c);
        $r1 = max(3, self::region($w, 0, $vowel));
        $r2 = self::region($w, self::region($w, 0, $vowel), $vowel);
        $in = fn (string $word, string $suffix, int $region) => mb_strlen($word) - mb_strlen($suffix) >= $region;
        $undouble = fn (string $word) => preg_match('/(kk|dd|tt)$/u', $word) === 1 ? mb_substr($word, 0, -1) : $word;
        $enEnding = fn (string $stem) => $stem !== '' && ! $vowel(mb_substr($stem, -1)) && ! str_ends_with($stem, 'gem');
        $sEnding = fn (string $stem) => $stem !== '' && ! $vowel(mb_substr($stem, -1)) && mb_substr($stem, -1) !== 'j';
        $cut = fn (string $word, string $suffix) => mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
        $eFound = false;

        // Step 1.
        $suffix = self::longest($w, ['heden', 'ene', 'en', 'se', 's']);

        if ($suffix !== null && $in($w, $suffix, $r1)) {
            $stem = $cut($w, $suffix);

            if ($suffix === 'heden') {
                $w = $stem.'heid';
            } elseif ($suffix === 'en' || $suffix === 'ene') {
                if ($enEnding($stem)) {
                    $w = $undouble($stem);
                }
            } elseif ($sEnding($stem)) {
                $w = $stem;
            }
        }

        // Step 2.
        $step2 = function () use (&$w, &$eFound, $in, $r1, $vowel, $undouble, $cut): void {
            if (str_ends_with($w, 'e') && $in($w, 'e', $r1) && ! $vowel(mb_substr($cut($w, 'e'), -1))) {
                $w = $undouble($cut($w, 'e'));
                $eFound = true;
            }
        };
        $step2();

        // Step 3a.
        if (str_ends_with($w, 'heid') && $in($w, 'heid', $r2) && ! str_ends_with($cut($w, 'heid'), 'c')) {
            $w = $cut($w, 'heid');

            if (str_ends_with($w, 'en') && $in($w, 'en', $r1) && $enEnding($cut($w, 'en'))) {
                $w = $undouble($cut($w, 'en'));
            }
        }

        // Step 3b.
        $suffix = self::longest($w, ['baar', 'lijk', 'end', 'ing', 'bar', 'ig']);

        if ($suffix !== null && $in($w, $suffix, $r2)) {
            $stem = $cut($w, $suffix);

            if ($suffix === 'end' || $suffix === 'ing') {
                $w = $stem;

                if (str_ends_with($w, 'ig') && $in($w, 'ig', $r2) && ! str_ends_with($cut($w, 'ig'), 'e')) {
                    $w = $cut($w, 'ig');
                } else {
                    $w = $undouble($w);
                }
            } elseif ($suffix === 'ig') {
                if (! str_ends_with($stem, 'e')) {
                    $w = $stem;
                }
            } elseif ($suffix === 'lijk') {
                $w = $stem;
                $step2();
            } elseif ($suffix === 'baar' || $eFound) {
                $w = $stem;
            }
        }

        // Step 4: undouble a vowel.
        if (preg_match('/[^aeiouyè](aa|ee|oo|uu)[^aeiouyèI]$/u', $w) === 1) {
            $w = mb_substr($w, 0, -2).mb_substr($w, -1);
        }

        return strtr($w, ['I' => 'i', 'Y' => 'y']);
    }

    // Spanish -------------------------------------------------------------

    private const ES_VOWELS = 'aeiouáéíóúü';

    private static function spanish(string $w): string
    {
        $vowel = fn (string $ch) => $ch !== '' && str_contains(self::ES_VOWELS, $ch);
        $r1 = self::region($w, 0, $vowel);
        $r2 = self::region($w, $r1, $vowel);
        $rv = self::rvRomance($w, $vowel);
        $in = fn (string $word, string $suffix, int $region) => mb_strlen($word) - mb_strlen($suffix) >= $region;
        $cut = fn (string $word, string $suffix) => mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
        $unaccent = fn (string $word) => strtr($word, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        // Step 0: attached pronouns.
        $suffix = self::longest($w, ['selas', 'selos', 'sela', 'selo', 'las', 'les', 'los', 'nos', 'me', 'se', 'la', 'le', 'lo']);

        if ($suffix !== null && $in($w, $suffix, $rv)) {
            $stem = $cut($w, $suffix);
            $before = self::longest($stem, ['iéndo', 'ándo', 'ár', 'ér', 'ír', 'iendo', 'ando', 'ar', 'er', 'ir', 'yendo']);

            if ($before !== null && $in($stem, $before, $rv)) {
                if (in_array($before, ['iéndo', 'ándo', 'ár', 'ér', 'ír'], true)) {
                    $w = $cut($stem, $before).$unaccent($before);
                } elseif ($before !== 'yendo' || str_ends_with($cut($stem, 'yendo'), 'u')) {
                    $w = $stem;
                }
            }
        }

        // Step 1: standard suffixes.
        $done = false;
        $groups = [
            ['anza', 'anzas', 'ico', 'ica', 'icos', 'icas', 'ismo', 'ismos', 'able', 'ables', 'ible', 'ibles', 'ista', 'istas', 'oso', 'osa', 'osos', 'osas', 'amiento', 'amientos', 'imiento', 'imientos'],
            ['adora', 'ador', 'ación', 'adoras', 'adores', 'aciones', 'ante', 'antes', 'ancia', 'ancias'],
            ['logía', 'logías'], ['ución', 'uciones'], ['encia', 'encias'], ['amente'], ['mente'], ['idad', 'idades'], ['iva', 'ivo', 'ivas', 'ivos'],
        ];
        $all = array_merge(...$groups);
        $suffix = self::longest($w, $all);

        if ($suffix !== null) {
            $stem = $cut($w, $suffix);

            if (in_array($suffix, $groups[0], true) && $in($w, $suffix, $r2)) {
                [$w, $done] = [$stem, true];
            } elseif (in_array($suffix, $groups[1], true) && $in($w, $suffix, $r2)) {
                [$w, $done] = [$stem, true];

                if (str_ends_with($w, 'ic') && $in($w, 'ic', $r2)) {
                    $w = $cut($w, 'ic');
                }
            } elseif (in_array($suffix, $groups[2], true) && $in($w, $suffix, $r2)) {
                [$w, $done] = [$stem.'log', true];
            } elseif (in_array($suffix, $groups[3], true) && $in($w, $suffix, $r2)) {
                [$w, $done] = [$stem.'u', true];
            } elseif (in_array($suffix, $groups[4], true) && $in($w, $suffix, $r2)) {
                [$w, $done] = [$stem.'ente', true];
            } elseif ($suffix === 'amente' && $in($w, $suffix, $r1)) {
                [$w, $done] = [$stem, true];

                if (str_ends_with($w, 'iv') && $in($w, 'iv', $r2)) {
                    $w = $cut($w, 'iv');

                    if (str_ends_with($w, 'at') && $in($w, 'at', $r2)) {
                        $w = $cut($w, 'at');
                    }
                } else {
                    foreach (['os', 'ic', 'ad'] as $before) {
                        if (str_ends_with($w, $before) && $in($w, $before, $r2)) {
                            $w = $cut($w, $before);

                            break;
                        }
                    }
                }
            } elseif ($suffix === 'mente' && $in($w, $suffix, $r2)) {
                [$w, $done] = [$stem, true];

                foreach (['ante', 'able', 'ible'] as $before) {
                    if (str_ends_with($w, $before) && $in($w, $before, $r2)) {
                        $w = $cut($w, $before);

                        break;
                    }
                }
            } elseif (in_array($suffix, $groups[7], true) && $in($w, $suffix, $r2)) {
                [$w, $done] = [$stem, true];

                foreach (['abil', 'ic', 'iv'] as $before) {
                    if (str_ends_with($w, $before) && $in($w, $before, $r2)) {
                        $w = $cut($w, $before);

                        break;
                    }
                }
            } elseif (in_array($suffix, $groups[8], true) && $in($w, $suffix, $r2)) {
                [$w, $done] = [$stem, true];

                if (str_ends_with($w, 'at') && $in($w, 'at', $r2)) {
                    $w = $cut($w, 'at');
                }
            }
        }

        // Step 2: verb suffixes.
        if (! $done) {
            $suffix = self::longest($w, ['yeron', 'yendo', 'yamos', 'yais', 'yan', 'yen', 'yas', 'yes', 'ya', 'ye', 'yo', 'yó']);

            if ($suffix !== null && $in($w, $suffix, $rv) && str_ends_with($cut($w, $suffix), 'u')) {
                $w = $cut($w, $suffix);
                $done = true;
            }
        }

        if (! $done) {
            $verbs = ['arían', 'arías', 'arán', 'arás', 'aríais', 'aría', 'aréis', 'aríamos', 'aremos', 'ará', 'aré', 'erían', 'erías', 'erán', 'erás', 'eríais', 'ería', 'eréis', 'eríamos', 'eremos', 'erá', 'eré', 'irían', 'irías', 'irán', 'irás', 'iríais', 'iría', 'iréis', 'iríamos', 'iremos', 'irá', 'iré', 'aba', 'ada', 'ida', 'ía', 'ara', 'iera', 'ad', 'ed', 'id', 'ase', 'iese', 'aste', 'iste', 'an', 'aban', 'ían', 'aran', 'ieran', 'asen', 'iesen', 'aron', 'ieron', 'ado', 'ido', 'ando', 'iendo', 'ió', 'ar', 'er', 'ir', 'as', 'abas', 'adas', 'idas', 'ías', 'aras', 'ieras', 'ases', 'ieses', 'ís', 'áis', 'abais', 'íais', 'arais', 'ierais', 'aseis', 'ieseis', 'asteis', 'isteis', 'ados', 'idos', 'amos', 'ábamos', 'íamos', 'imos', 'áramos', 'iéramos', 'iésemos', 'ásemos'];
            $suffix = self::longest($w, ['en', 'es', 'éis', 'emos', ...$verbs]);

            if ($suffix !== null && $in($w, $suffix, $rv)) {
                $w = $cut($w, $suffix);

                if (in_array($suffix, ['en', 'es', 'éis', 'emos'], true) && str_ends_with($w, 'gu')) {
                    $w = mb_substr($w, 0, -1);
                }
            }
        }

        // Step 3: residual suffixes.
        $suffix = self::longest($w, ['os', 'a', 'o', 'á', 'í', 'ó', 'e', 'é']);

        if ($suffix !== null && $in($w, $suffix, $rv)) {
            $w = $cut($w, $suffix);

            if (($suffix === 'e' || $suffix === 'é') && str_ends_with($w, 'gu') && $in($w, 'u', $rv)) {
                $w = mb_substr($w, 0, -1);
            }
        }

        return $unaccent($w);
    }

    // French --------------------------------------------------------------

    private const FR_VOWELS = 'aeiouyâàëéêèïîôûù';

    private static function french(string $w): string
    {
        $vowel = fn (string $ch) => $ch !== '' && str_contains(self::FR_VOWELS, $ch);
        $c = self::chars($w);

        for ($i = 0; $i < count($c); $i++) {
            $prev = $c[$i - 1] ?? '';
            $next = $c[$i + 1] ?? '';

            if (($c[$i] === 'u' || $c[$i] === 'i') && $vowel($prev) && $vowel($next)) {
                $c[$i] = strtoupper($c[$i]);
            } elseif ($c[$i] === 'y' && ($vowel($prev) || $vowel($next))) {
                $c[$i] = 'Y';
            } elseif ($c[$i] === 'u' && $prev === 'q') {
                $c[$i] = 'U';
            }
        }

        $w = implode('', $c);
        $r1 = self::region($w, 0, $vowel);
        $r2 = self::region($w, $r1, $vowel);

        if (preg_match('/^(par|col|tap)/u', $w) === 1) {
            $rv = 3;
        } elseif (mb_strlen($w) >= 2 && $vowel(mb_substr($w, 0, 1)) && $vowel(mb_substr($w, 1, 1))) {
            $rv = min(3, mb_strlen($w));
        } else {
            $rv = mb_strlen($w);

            for ($i = 1; $i < mb_strlen($w); $i++) {
                if ($vowel(mb_substr($w, $i, 1))) {
                    $rv = $i + 1;

                    break;
                }
            }
        }

        $in = fn (string $word, string $suffix, int $region) => mb_strlen($word) - mb_strlen($suffix) >= $region;
        $cut = fn (string $word, string $suffix) => mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
        $before = $w;
        $step1 = false;
        $step2a = false;

        // Step 1: standard suffixes.
        $suffix = self::longest($w, ['issements', 'issement', 'atrices', 'atrice', 'ateurs', 'ateur', 'ations', 'ation', 'logies', 'logie', 'usions', 'usion', 'utions', 'ution', 'ences', 'ence', 'ements', 'ement', 'ités', 'ité', 'ives', 'ive', 'ifs', 'ivs', 'if', 'iv', 'eaux', 'aux', 'euses', 'euse', 'amment', 'emment', 'ments', 'ment', 'ances', 'ance', 'iqUes', 'iqUe', 'ismes', 'isme', 'ables', 'able', 'istes', 'iste', 'eux']);

        if ($suffix !== null) {
            $stem = $cut($w, $suffix);
            $base = rtrim($suffix, 's');

            if (in_array($base, ['ance', 'iqUe', 'isme', 'able', 'iste', 'eux'], true) || $suffix === 'eux') {
                if ($in($w, $suffix, $r2)) {
                    [$w, $step1] = [$stem, true];
                }
            } elseif (in_array($base, ['atrice', 'ateur', 'ation'], true)) {
                if ($in($w, $suffix, $r2)) {
                    [$w, $step1] = [$stem, true];

                    if (str_ends_with($w, 'ic')) {
                        $w = $in($w, 'ic', $r2) ? $cut($w, 'ic') : $cut($w, 'ic').'iqU';
                    }
                }
            } elseif ($base === 'logie') {
                if ($in($w, $suffix, $r2)) {
                    [$w, $step1] = [$stem.'log', true];
                }
            } elseif ($base === 'usion' || $base === 'ution') {
                if ($in($w, $suffix, $r2)) {
                    [$w, $step1] = [$stem.'u', true];
                }
            } elseif ($base === 'ence') {
                if ($in($w, $suffix, $r2)) {
                    [$w, $step1] = [$stem.'ent', true];
                }
            } elseif ($base === 'ement') {
                if ($in($w, $suffix, $rv)) {
                    [$w, $step1] = [$stem, true];

                    if (str_ends_with($w, 'iv') && $in($w, 'iv', $r2)) {
                        $w = $cut($w, 'iv');

                        if (str_ends_with($w, 'at') && $in($w, 'at', $r2)) {
                            $w = $cut($w, 'at');
                        }
                    } elseif (str_ends_with($w, 'eus')) {
                        if ($in($w, 'eus', $r2)) {
                            $w = $cut($w, 'eus');
                        } elseif ($in($w, 'eus', $r1)) {
                            $w = $cut($w, 'eus').'eux';
                        }
                    } elseif ((str_ends_with($w, 'abl') && $in($w, 'abl', $r2)) || (str_ends_with($w, 'iqU') && $in($w, 'iqU', $r2))) {
                        $w = mb_substr($w, 0, -3);
                    } elseif ((str_ends_with($w, 'ièr') || str_ends_with($w, 'Ièr')) && $in($w, 'ièr', $rv)) {
                        $w = mb_substr($w, 0, -3).'i';
                    }
                }
            } elseif ($base === 'ité' || $suffix === 'ités') {
                if ($in($w, $suffix, $r2)) {
                    [$w, $step1] = [$stem, true];

                    if (str_ends_with($w, 'abil')) {
                        $w = $in($w, 'abil', $r2) ? $cut($w, 'abil') : $cut($w, 'abil').'abl';
                    } elseif (str_ends_with($w, 'ic')) {
                        $w = $in($w, 'ic', $r2) ? $cut($w, 'ic') : $cut($w, 'ic').'iqU';
                    } elseif (str_ends_with($w, 'iv') && $in($w, 'iv', $r2)) {
                        $w = $cut($w, 'iv');
                    }
                }
            } elseif (in_array($suffix, ['if', 'ive', 'ifs', 'ives', 'iv', 'ivs'], true)) {
                if ($in($w, $suffix, $r2)) {
                    [$w, $step1] = [$stem, true];

                    if (str_ends_with($w, 'at') && $in($w, 'at', $r2)) {
                        $w = $cut($w, 'at');

                        if (str_ends_with($w, 'ic')) {
                            $w = $in($w, 'ic', $r2) ? $cut($w, 'ic') : $cut($w, 'ic').'iqU';
                        }
                    }
                }
            } elseif ($suffix === 'eaux') {
                [$w, $step1] = [$stem.'eau', true];
            } elseif ($suffix === 'aux') {
                if ($in($w, $suffix, $r1)) {
                    [$w, $step1] = [$stem.'al', true];
                }
            } elseif ($base === 'euse') {
                if ($in($w, $suffix, $r2)) {
                    [$w, $step1] = [$stem, true];
                } elseif ($in($w, $suffix, $r1)) {
                    [$w, $step1] = [$stem.'eux', true];
                }
            } elseif ($base === 'issement') {
                if ($in($w, $suffix, $r1) && ! $vowel(mb_substr($stem, -1))) {
                    [$w, $step1] = [$stem, true];
                }
            } elseif ($suffix === 'amment') {
                if ($in($w, $suffix, $rv)) {
                    [$w, $step1] = [$stem.'ant', false];
                }
            } elseif ($suffix === 'emment') {
                if ($in($w, $suffix, $rv)) {
                    [$w, $step1] = [$stem.'ent', false];
                }
            } elseif ($base === 'ment') {
                if ($in($w, $suffix, $rv) && $vowel(mb_substr($stem, -1)) && mb_strlen($stem) - 1 >= $rv) {
                    [$w, $step1] = [$stem, false];
                }
            }
        }

        // Step 2a: verbs in -ir.
        if (! $step1) {
            $suffix = self::longest($w, ['issaIent', 'issantes', 'iraIent', 'issante', 'issants', 'issions', 'irions', 'issais', 'issait', 'issant', 'issent', 'issiez', 'issons', 'irais', 'irait', 'irent', 'iriez', 'irons', 'iront', 'isses', 'issez', 'îmes', 'îtes', 'irai', 'iras', 'irez', 'isse', 'ies', 'ira', 'ît', 'ie', 'ir', 'is', 'it', 'i']);

            if ($suffix !== null && $in($w, $suffix, $rv)) {
                $stem = $cut($w, $suffix);

                if ($stem !== '' && ! $vowel(mb_substr($stem, -1)) && mb_strlen($stem) > $rv - 1) {
                    [$w, $step2a] = [$stem, true];
                }
            }

            // Step 2b: other verbs.
            if (! $step2a) {
                $suffix = self::longest($w, ['eraIent', 'assions', 'erions', 'assent', 'assiez', 'èrent', 'erais', 'erait', 'eriez', 'erons', 'eront', 'aIent', 'antes', 'asses', 'ions', 'erai', 'eras', 'erez', 'âmes', 'âtes', 'ante', 'ants', 'asse', 'ées', 'era', 'iez', 'ais', 'ait', 'ant', 'ée', 'és', 'er', 'ez', 'ât', 'ai', 'as', 'é', 'a']);

                if ($suffix !== null && $in($w, $suffix, $rv)) {
                    $stem = $cut($w, $suffix);

                    if ($suffix === 'ions') {
                        if ($in($w, $suffix, $r2)) {
                            $w = $stem;
                        }
                    } elseif (in_array($suffix, ['é', 'ée', 'ées', 'és', 'èrent', 'er', 'era', 'erai', 'eraIent', 'erais', 'erait', 'eras', 'erez', 'eriez', 'erions', 'erons', 'eront', 'ez', 'iez'], true)) {
                        $w = $stem;
                    } else {
                        $w = $stem;

                        if (str_ends_with($w, 'e') && $in($w, 'e', $rv)) {
                            $w = mb_substr($w, 0, -1);
                        }
                    }
                }
            }
        }

        if ($w !== $before) {
            // Step 3.
            if (str_ends_with($w, 'Y')) {
                $w = mb_substr($w, 0, -1).'i';
            } elseif (str_ends_with($w, 'ç')) {
                $w = mb_substr($w, 0, -1).'c';
            }
        } else {
            // Step 4: residual suffixes.
            if (str_ends_with($w, 's') && ! preg_match('/[aiouès]s$/u', $w)) {
                $w = mb_substr($w, 0, -1);
            }

            $suffix = self::longest($w, ['ière', 'Ière', 'ier', 'Ier', 'ion', 'e', 'ë']);

            if ($suffix !== null && $in($w, $suffix, $rv)) {
                $stem = $cut($w, $suffix);

                if ($suffix === 'ion') {
                    if ($in($w, $suffix, $r2) && (str_ends_with($stem, 's') || str_ends_with($stem, 't'))) {
                        $w = $stem;
                    }
                } elseif ($suffix === 'e') {
                    $w = $stem;
                } elseif ($suffix === 'ë') {
                    if (str_ends_with($stem, 'gu')) {
                        $w = $stem;
                    }
                } else {
                    $w = $stem.'i';
                }
            }
        }

        // Step 5: undouble.
        if (preg_match('/(enn|onn|ett|ell|eill)$/u', $w) === 1) {
            $w = mb_substr($w, 0, -1);
        }

        // Step 6: un-accent.
        $w = (string) preg_replace('/[éè]([^aeiouyâàëéêèïîôûù]+)$/u', 'e$1', $w);

        return strtr($w, ['I' => 'i', 'U' => 'u', 'Y' => 'y']);
    }

    // Shared --------------------------------------------------------------

    /**
     * Where the region after the first non-vowel following a vowel starts,
     * looking from $from (Snowball's R1, and R2 from R1); the word's length
     * when there's none.
     *
     * @param  callable(string): bool  $vowel
     */
    private static function region(string $w, int $from, callable $vowel): int
    {
        $c = self::chars($w);
        $n = count($c);

        for ($i = $from + 1; $i < $n; $i++) {
            if (! $vowel($c[$i]) && $vowel($c[$i - 1])) {
                return $i + 1;
            }
        }

        return $n;
    }

    /**
     * RV for Spanish (and Portuguese): after the next vowel when the second
     * letter is a consonant; after the next consonant when the first two
     * are vowels; else after the third letter.
     *
     * @param  callable(string): bool  $vowel
     */
    private static function rvRomance(string $w, callable $vowel): int
    {
        $c = self::chars($w);
        $n = count($c);

        if ($n < 2) {
            return $n;
        }

        if (! $vowel($c[1])) {
            for ($i = 2; $i < $n; $i++) {
                if ($vowel($c[$i])) {
                    return $i + 1;
                }
            }

            return $n;
        }

        if ($vowel($c[0])) {
            for ($i = 2; $i < $n; $i++) {
                if (! $vowel($c[$i])) {
                    return $i + 1;
                }
            }

            return $n;
        }

        return min(3, $n);
    }

    /**
     * The longest of the suffixes the word ends in.
     *
     * @param  list<string>  $suffixes
     */
    private static function longest(string $w, array $suffixes): ?string
    {
        $found = null;

        foreach ($suffixes as $suffix) {
            if (str_ends_with($w, $suffix) && ($found === null || mb_strlen($suffix) > mb_strlen($found))) {
                $found = $suffix;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function chars(string $w): array
    {
        return mb_str_split($w);
    }
}
