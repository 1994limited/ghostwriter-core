<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts;

use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequest;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\ImageRequestStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Images\StoredFile;

/**
 * What every ImageRequestStore must do. See SessionStoreContract for how
 * an addon runs it.
 */
trait ImageRequestStoreContract
{
    abstract protected function imageRequestStore(): ImageRequestStore;

    abstract protected function storeFormat(): Format;

    protected function contractUser(int $n): int|string
    {
        return $this->storeFormat() === Format::Statamic ? "user-{$n}" : $n;
    }

    private function request(string $mode = ImageRequest::FIND): ImageRequest
    {
        return ImageRequest::start($this->storeFormat(), $mode, $this->contractUser(1), ['label' => 'Hero image', 'terms' => ['walled kitchen garden']]);
    }

    public function test_a_saved_request_is_found_with_when_it_changed(): void
    {
        $store = $this->imageRequestStore();
        $request = $this->request();
        $store->save($request);

        $found = $store->find($request->id);

        $this->assertNotNull($found);
        $this->assertSame(ImageRequest::WORKING, $found->status);
        $this->assertSame((string) $this->contractUser(1), (string) $found->owner);
        $this->assertSame('Hero image', $found->details['label'] ?? null);
        $this->assertNotNull(Format::parse($found->changedAt ?? $found->createdAt));
    }

    public function test_a_finished_request_keeps_its_results(): void
    {
        $store = $this->imageRequestStore();
        $request = $store->save($this->request());

        $request->succeed(['terms' => ['tulips'], 'options' => [['source' => 'openverse', 'id' => 'abc', 'thumb' => 'https://example.org/t.jpg']], 'judged' => true]);
        $store->save($request);

        $found = $store->find($request->id);
        $this->assertNotNull($found);
        $this->assertTrue($found->isDone());
        $this->assertSame(['tulips'], $found->terms);
        $this->assertSame('https://example.org/t.jpg', $found->options[0]['thumb']);
        $this->assertTrue($found->details['judged']);
    }

    public function test_nothing_is_found_for_a_missing_or_malformed_id(): void
    {
        $store = $this->imageRequestStore();

        $this->assertNull($store->find($this->storeFormat()->newId()));

        foreach (['', '../../etc/passwd', str_repeat('a', 300)] as $id) {
            $this->assertNull($store->find($id), "Nothing for \"{$id}\"");
        }
    }

    public function test_files_are_kept_beside_a_request_and_go_with_it(): void
    {
        $store = $this->imageRequestStore();
        $request = $store->save($this->request(ImageRequest::MAKE));
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        $store->putFile($request->id, StoredFile::MADE, new StoredFile($png, 'image/png', 'png'));
        $file = $store->file($request->id, StoredFile::MADE);

        $this->assertNotNull($file);
        $this->assertSame($png, $file->content);
        $this->assertSame('image/png', $file->mime);
        $this->assertSame('png', $file->extension);
        $this->assertNull($store->file($request->id, StoredFile::SOURCE));

        $store->delete($request->id);

        $this->assertNull($store->find($request->id));
        $this->assertNull($store->file($request->id, StoredFile::MADE));
    }

    public function test_old_requests_are_cleared_with_their_files(): void
    {
        $store = $this->imageRequestStore();
        $request = $store->save($this->request(ImageRequest::MAKE));
        $store->putFile($request->id, StoredFile::MADE, new StoredFile('png', 'image/png', 'png'));

        $this->assertSame(0, $store->clearOlderThan(new DateTimeImmutable('-1 hour')));
        $this->assertNotNull($store->find($request->id));

        $this->assertSame(1, $store->clearOlderThan(new DateTimeImmutable('+1 hour')));
        $this->assertNull($store->find($request->id));
        $this->assertNull($store->file($request->id, StoredFile::MADE));
    }
}
