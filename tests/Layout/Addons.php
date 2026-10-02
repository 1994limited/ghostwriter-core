<?php

namespace NineteenNinetyFour\Ghostwriter\Core\Tests\Layout;

use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Layout\LayoutOptions;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Layouts;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\NoLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\StatamicLinks;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Layout\Support\BardDialect;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;

/**
 * Each addon's layouts, wired as its adapter will wire them in stage 2.
 */
final class Addons
{
    public const HYPER = 'verbb\\hyper\\fields\\HyperField';

    public const LINK = 'craft\\fields\\Link';

    public static function layouts(string $addon): Layouts
    {
        return match ($addon) {
            'statamic' => new Layouts(LayoutOptions::statamic(), new BardDialect, new StatamicLinks),
            'craft' => new Layouts(LayoutOptions::craft(), new HtmlDialect, new CraftLinks(hyper: [self::HYPER], link: [self::LINK])),
            'filament' => new Layouts(LayoutOptions::filament(), new HtmlDialect(new HtmlToMarkdown(embeds: [])), new NoLinks),
        };
    }
}
