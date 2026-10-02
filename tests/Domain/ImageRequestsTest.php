<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Domain;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequests;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\StoredFile;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Testing\InMemoryLock;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Viewer;
use PHPUnit\Framework\TestCase;

final class ImageRequestsTest extends TestCase
{
    private DateTimeImmutable $now;

    private InMemoryImageRequestStore $store;

    private function requests(DomainOptions $options): ImageRequests
    {
        $this->now ??= new DateTimeImmutable('2026-10-02T12:00:00+00:00');
        $this->store = new InMemoryImageRequestStore($options->format, fn () => $this->now);

        return new ImageRequests($this->store, new InMemoryLock, $options, fn () => $this->now);
    }

    public function test_a_request_works_then_is_done_or_failed(): void
    {
        $requests = $this->requests(DomainOptions::statamic());
        $request = $requests->start(ImageRequest::FIND, new Viewer('u1'), ['slot' => ['journal', null, 'hero_image'], 'terms' => ['tulips']]);

        $this->assertTrue($request->isWorking());
        $this->assertSame('2026-10-02T12:00:00+00:00', $request->createdAt);
        $this->assertSame('u1', $request->toArray()['user']);

        $done = $requests->succeed($request->id, ['terms' => ['tulips', 'alliums'], 'options' => [['id' => '1']], 'judged' => true]);
        $this->assertSame('done', $done?->toArray()['status']);
        $this->assertSame(['tulips', 'alliums'], $done?->terms);
        $this->assertTrue($done?->details['judged']);

        $failed = $requests->fail($request->id, 'No photo library is switched on.');
        $this->assertSame('failed', $failed?->status);
        $this->assertNull($requests->fail('nothing', 'x'));
    }

    public function test_craft_and_filament_call_it_ready(): void
    {
        foreach ([DomainOptions::craft(), DomainOptions::filament()] as $options) {
            $request = ImageRequest::start($options->format, ImageRequest::MAKE, 1);
            $request->succeed(file: 'ghostwriter/images/x.png');

            $this->assertSame('ready', $request->toArray()['status']);
            $this->assertSame('ghostwriter/images/x.png', $request->toArray()['file']);
            $this->assertIsInt($request->createdAt);
            $this->assertTrue(ImageRequest::fromArray($request->toArray(), $options->format)->isDone());
        }

        $this->assertArrayHasKey('userId', ImageRequest::start(Format::Craft, 'find', 1)->toArray());
        $this->assertArrayHasKey('user_id', ImageRequest::start(Format::Filament, 'find', 1)->toArray());
    }

    public function test_a_request_is_its_owners_alone(): void
    {
        $requests = $this->requests(DomainOptions::filament());
        $request = $requests->start(ImageRequest::FIND, new Viewer(1));

        $this->assertSame($request->id, $requests->mine($request->id, new Viewer('1'))->id);

        try {
            $requests->mine($request->id, new Viewer(2));
            $this->fail('Not allowed');
        } catch (NotAllowed $refused) {
            $this->assertSame(403, $refused->status());
        }

        $this->expectException(NotFound::class);
        $requests->mine('nothing', new Viewer(1));
    }

    public function test_one_whose_job_stopped_shows_as_failed(): void
    {
        $requests = $this->requests(DomainOptions::craft());
        $request = $requests->start(ImageRequest::MAKE, new Viewer(1));

        $this->now = $this->now->modify('+20 minutes');

        $found = $requests->find($request->id);
        $this->assertSame(ImageRequest::FAILED, $found?->status);
        $this->assertSame(DomainOptions::STOPPED, $found?->error);
    }

    public function test_a_new_request_clears_those_older_than_a_day_with_their_files(): void
    {
        $requests = $this->requests(DomainOptions::statamic());
        $old = $requests->start(ImageRequest::MAKE, new Viewer('u'));
        $requests->putFile($old->id, StoredFile::MADE, new StoredFile('png', 'image/png', 'png'));
        $this->assertNotNull($requests->file($old->id, StoredFile::MADE));

        $this->now = $this->now->modify('+25 hours');
        $this->assertTrue($old->isExpired($this->now));
        $requests->start(ImageRequest::FIND, new Viewer('u'));

        $this->assertNull($requests->find($old->id));
        $this->assertNull($requests->file($old->id, StoredFile::MADE));
    }
}
