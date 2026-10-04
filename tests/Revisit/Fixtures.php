<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Revisit;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Testing\MemoryLinkTargets;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\AgePolicy;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\EntrySnapshot;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\LinkResult;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Kind;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Quieted;

/** Small entries for the revisit tests: a title and a markdown body. */
final class Fixtures
{
    public const NOW = '2026-10-04 10:00:00';

    /**
     * @param  array<string, array{title: string, slug?: string, url?: string}>  $targets  Entries that exist, for broken links.
     * @param  array<string, LinkResult>  $external
     */
    public static function snapshot(EntryRef $ref, string $title, string $updated, string $body = 'A plain page.', bool $published = true, array $targets = ['e12' => ['title' => 'Walled garden']], ?AgePolicy $age = null, array $external = [], ?Quieted $quieted = null): EntrySnapshot
    {
        $schema = new Schema([new Field('title', Kind::Text, 'Title'), new Field('body', Kind::LongText, 'Body', type: 'markdown')]);

        return new EntrySnapshot($ref, $title, '/cp/'.$ref->id, new CheckContext(
            gaps: new GapContext(schema: $schema, entry: new EntryData(['title' => $title, 'body' => $body], $ref->id), links: new StatamicLinks, targets: new MemoryLinkTargets($targets)),
            now: new DateTimeImmutable(self::NOW),
            updatedAt: new DateTimeImmutable($updated),
            entry: $ref,
            age: $age ?? new AgePolicy,
            quieted: $quieted ?? new Quieted,
            external: $external,
        ), $published);
    }
}
