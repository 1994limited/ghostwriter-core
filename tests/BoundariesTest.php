<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Core depends on no framework or CMS. bin/check-boundaries enforces it in
 * CI; this runs the same check with the test suite.
 */
class BoundariesTest extends TestCase
{
    private ?string $temporary = null;

    protected function tearDown(): void
    {
        if ($this->temporary !== null) {
            array_map('unlink', glob($this->temporary.'/*') ?: []);
            rmdir($this->temporary);
        }
    }

    public function test_src_names_no_framework_or_cms(): void
    {
        [$code, $output] = $this->check(dirname(__DIR__).'/src');

        $this->assertSame(0, $code, $output);
    }

    public function test_the_check_catches_each_forbidden_namespace(): void
    {
        $this->temporary = sys_get_temp_dir().'/ghostwriter-boundaries-'.bin2hex(random_bytes(4));
        mkdir($this->temporary);

        file_put_contents($this->temporary.'/Bad.php', <<<'PHP'
            <?php
            namespace Example;
            use Craft;
            use craft\helpers\App;
            use Illuminate\Support\Str;
            // use Statamic\Facades\Entry; (a comment, so allowed)
            class Bad {
                public function f(): array {
                    return [\Yii::$app, 'Filament\Panel', \Livewire\Component::class, Craft::$app, Vocabulary::craft()];
                }
            }
            PHP);

        [$code, $output] = $this->check($this->temporary);

        $this->assertSame(1, $code);

        foreach (['Craft', 'craft\helpers\App', 'Illuminate\Support\Str', '\Yii', "'Filament\Panel'", '\Livewire\Component'] as $name) {
            $this->assertStringContainsString($name, $output);
        }

        $this->assertStringNotContainsString('Statamic', $output);
        $this->assertStringNotContainsString('Vocabulary', $output);
    }

    /**
     * @return array{int, string}
     */
    private function check(string $directory): array
    {
        $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/check-boundaries').' '.escapeshellarg($directory).' 2>&1';
        exec($command, $lines, $code);

        return [$code, implode("\n", $lines)];
    }
}
