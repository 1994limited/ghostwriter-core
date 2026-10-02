<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Images\Libraries;

use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\Free\Openverse;
use NineteenNinetyFour\Ghostwriter\Core\Images\Libraries\PhotoLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Images\ImagesTestCase;

final class OpenverseTest extends ImagesTestCase
{
    use LibraryContract;

    private const ID = '4bc43a04-ef46-4544-a0c1-63c63f56e276';

    public function test_it_can_be_switched_off_by_a_setting_read_each_time(): void
    {
        $on = false;
        $library = new Openverse($this->http, function () use (&$on) {
            return $on;
        });

        $this->assertFalse($library->available());
        $on = true;
        $this->assertTrue($library->available());
        $this->assertFalse($library->capabilities()->creditRequired, 'CC0 and public-domain work needs no credit.');
    }

    protected function library(): PhotoLibrary
    {
        return new Openverse($this->http);
    }

    protected function routeSearch(): void
    {
        $this->route('https://api.openverse.org/v1/images/?', ['results' => [$this->openverse(self::ID), $this->openverse('bbb')]]);
        $this->route('https://api.openverse.org/v1/images/'.self::ID.'/thumb/', $this->jpegResponse());
        $this->route('https://api.openverse.org/v1/images/bbb/thumb/', $this->jpegResponse());
    }

    protected function routePhoto(string $id): void
    {
        $this->route("https://api.openverse.org/v1/images/{$id}/", $this->openverse($id));
    }

    protected function contractId(): string
    {
        return self::ID;
    }

    protected function contractKey(): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function openverse(string $id): array
    {
        return [
            'id' => $id,
            'title' => 'A bridge',
            'creator' => 'Kim',
            'license' => 'cc0',
            'url' => "https://live.staticflickr.com/{$id}.jpg",
            'thumbnail' => "https://api.openverse.org/v1/images/{$id}/thumb/",
        ];
    }
}
