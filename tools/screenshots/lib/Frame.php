<?php

/**
 * Sets a raw shot in the store frame: the Ghostwriter logo, "For <CMS>",
 * a headline and a line under it, and the shot in a rounded panel that
 * runs off the bottom edge. The same layout as Statamic's scripts/frame.php
 * (1600×900, the shot 1360 wide at 120, 320), drawn by Chrome so the brand
 * fonts render, at 1600×900 and, as -2x, 3200×1800.
 */
class Frame
{
    public function __construct(private Browser $browser, private string $scratch) {}

    public function make(string $raw, string $out, string $cms, string $headline, string $line): void
    {
        $logo = (string) file_get_contents(__DIR__.'/../assets/ghostwriter-horizontal-colour.svg');
        $html = strtr((string) file_get_contents(__DIR__.'/../assets/frame.html'), [
            '{{ logo }}' => 'data:image/svg+xml;base64,'.base64_encode($logo),
            '{{ shot }}' => 'data:image/png;base64,'.base64_encode((string) file_get_contents($raw)),
            '{{ cms }}' => htmlspecialchars($cms),
            '{{ headline }}' => htmlspecialchars($headline),
            '{{ line }}' => htmlspecialchars($line),
        ]);

        $page = $this->scratch.'/frame.html';
        file_put_contents($page, $html);

        $this->browser->scheme('light');

        foreach ([1 => $out, 2 => preg_replace('/\.png$/', '-2x.png', $out)] as $scale => $target) {
            $this->browser->viewport(1600, 900, $scale);
            $this->browser->go('file://'.$page);
            $this->browser->settle(0.3);
            $this->browser->capture($target);
        }

        $this->browser->viewport();
    }
}
