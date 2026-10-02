<?php

/**
 * The chores every shot needs, over a DevTools socket: a fixed window,
 * light or dark, waiting for the page to settle, clicking and typing as a
 * person would, and saving all or part of the window as a PNG.
 */
class Browser
{
    public const WIDTH = 1440;

    public const HEIGHT = 900;

    public const SCALE = 2;

    private string $scheme = 'light';

    public function __construct(public DevToolsSocket $ws)
    {
        $ws->send('Page.enable');
        $ws->send('Runtime.enable');
        $this->viewport();
        $this->scheme('light');
    }

    public function viewport(int $width = self::WIDTH, int $height = self::HEIGHT, int $scale = self::SCALE): void
    {
        $this->ws->send('Emulation.setDeviceMetricsOverride', ['width' => $width, 'height' => $height, 'deviceScaleFactor' => $scale, 'mobile' => false]);
    }

    /**
     * Light or dark, whatever the machine prefers. A CP that keeps its own
     * theme preference is set by the site before the shot.
     */
    public function scheme(string $scheme): void
    {
        $this->scheme = $scheme;
        $this->ws->send('Emulation.setEmulatedMedia', ['features' => [['name' => 'prefers-color-scheme', 'value' => $scheme]]]);
    }

    public function currentScheme(): string
    {
        return $this->scheme;
    }

    public function go(string $url): void
    {
        $this->ws->navigate($url);
    }

    /**
     * @param  array<string, mixed>  $constants  Names replaced in the script with JSON-encoded values.
     */
    public function js(string $script, array $constants = []): mixed
    {
        return $this->ws->evaluate($script, $constants);
    }

    /**
     * Wait until a JavaScript expression is truthy.
     */
    public function until(string $expression, float $seconds = 20, string $what = ''): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            try {
                if ($this->js("(() => { try { return !!({$expression}); } catch (e) { return false; } })()")) {
                    return;
                }
            } catch (RuntimeException) {
                // A navigation can drop the context mid-call; try again.
            }

            usleep(250000);
        }

        throw new RuntimeException('Timed out waiting for '.($what ?: $expression).' on '.$this->js('location.href'));
    }

    public function waitForSelector(string $selector, float $seconds = 20): void
    {
        $this->until('[...document.querySelectorAll('.json_encode($selector).')].some(e => e.offsetParent !== null || e.getClientRects().length)', $seconds, "“{$selector}”");
    }

    /**
     * Wait for visible text anywhere on the page (case-insensitive).
     */
    public function waitForText(string $text, float $seconds = 20): void
    {
        $this->until('document.body && document.body.innerText.toLowerCase().includes('.json_encode(mb_strtolower($text)).')', $seconds, "the text “{$text}”");
    }

    public function hasText(string $text): bool
    {
        return (bool) $this->js('document.body.innerText.toLowerCase().includes(__T__)', ['__T__' => mb_strtolower($text)]);
    }

    /**
     * Click the first visible element whose own text matches, as a person
     * would. Exact match first, then a match at the start.
     */
    public function click(string $text, string $within = 'button, a, [role=button], [role=tab], label, summary', bool $required = true): bool
    {
        $clicked = (bool) $this->js(<<<'JS'
            (() => {
                const all = [...document.querySelectorAll(__WITHIN__)].filter(e => e.offsetParent !== null || e.getClientRects().length);
                const norm = s => s.replace(/\s+/g, ' ').trim().toLowerCase();
                const want = norm(__TEXT__);
                const el = all.find(e => norm(e.innerText || e.textContent) === want)
                    || all.find(e => norm(e.getAttribute('aria-label') || '') === want)
                    || all.find(e => norm(e.innerText || e.textContent).startsWith(want));
                if (!el) return false;
                el.scrollIntoView({block: 'center'});
                el.click();
                return true;
            })()
        JS, ['__TEXT__' => $text, '__WITHIN__' => $within]);

        if (! $clicked && $required) {
            throw new RuntimeException("Nothing to click called “{$text}” on ".$this->js('location.href'));
        }

        usleep(300000);

        return $clicked;
    }

    public function clickSelector(string $selector, bool $required = true): bool
    {
        $clicked = (bool) $this->js('(() => { const el = [...document.querySelectorAll(__S__)].find(e => e.offsetParent !== null || e.getClientRects().length); if (!el) return false; el.scrollIntoView({block: "center"}); el.click(); return true; })()', ['__S__' => $selector]);

        if (! $clicked && $required) {
            throw new RuntimeException("Nothing matches “{$selector}” on ".$this->js('location.href'));
        }

        usleep(300000);

        return $clicked;
    }

    /**
     * Set an input's value the way typing would, for Vue, Livewire and
     * plain forms alike.
     */
    public function fill(string $selector, string $value): void
    {
        $done = $this->js(<<<'JS'
            (() => {
                const el = document.querySelector(__S__);
                if (!el) return false;
                const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
                Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, __V__);
                el.dispatchEvent(new Event('input', {bubbles: true}));
                el.dispatchEvent(new Event('change', {bubbles: true}));
                return true;
            })()
        JS, ['__S__' => $selector, '__V__' => $value]);

        if (! $done) {
            throw new RuntimeException("No field “{$selector}” on ".$this->js('location.href'));
        }
    }

    /**
     * Type into the focused element.
     */
    public function type(string $text): void
    {
        $this->ws->send('Input.insertText', ['text' => $text]);
    }

    public function key(string $key): void
    {
        $codes = ['Enter' => 13, 'Escape' => 27, 'Tab' => 9];
        $params = ['key' => $key, 'code' => $key, 'windowsVirtualKeyCode' => $codes[$key] ?? 0];
        $this->ws->send('Input.dispatchKeyEvent', ['type' => 'keyDown'] + $params);
        $this->ws->send('Input.dispatchKeyEvent', ['type' => 'keyUp'] + $params);
    }

    public function scrollTo(string $selector, string $block = 'start', int $offset = 0): void
    {
        $this->js('(() => { const el = document.querySelector(__S__); if (!el) return; el.scrollIntoView({block: __B__}); if (__O__) { (el.closest("[data-scroll], .overflow-y-auto, .overflow-auto") || window).scrollBy(0, __O__); } })()', ['__S__' => $selector, '__B__' => $block, '__O__' => $offset]);
        usleep(300000);
    }

    /**
     * Hide elements that shouldn't be in a shot (a licence banner, a
     * debug bar, the signed-in person's email).
     *
     * @param  array<int, string>  $selectors
     */
    public function hide(array $selectors): void
    {
        $this->js('(() => { for (const s of __S__) for (const el of document.querySelectorAll(s)) el.style.setProperty("visibility", "hidden", "important"); })()', ['__S__' => $selectors]);
    }

    /**
     * Stop animations and the caret, then wait for fonts and images, so a
     * shot never catches something halfway.
     */
    public function settle(float $extra = 0.4): void
    {
        $this->js(<<<'JS'
            (() => {
                if (!document.getElementById('gw-shots-still')) {
                    const style = document.createElement('style');
                    style.id = 'gw-shots-still';
                    style.textContent = '*, *::before, *::after { transition-duration: 0s !important; transition-delay: 0s !important; animation-duration: 0s !important; animation-delay: 0s !important; animation-iteration-count: 1 !important; caret-color: transparent !important; scroll-behavior: auto !important; }';
                    document.head.appendChild(style);
                }
                if (document.activeElement && document.activeElement !== document.body && !document.activeElement.dataset.gwKeepFocus) document.activeElement.blur();
            })()
        JS);

        $this->js(<<<'JS'
            (async () => {
                await document.fonts.ready;
                const pending = [...document.images].filter(i => !i.complete && i.loading !== 'lazy');
                await Promise.race([
                    Promise.all(pending.map(i => new Promise(r => { i.addEventListener('load', r, {once: true}); i.addEventListener('error', r, {once: true}); }))),
                    new Promise(r => setTimeout(r, 8000)),
                ]);
                return true;
            })()
        JS);

        usleep((int) ($extra * 1000000));
    }

    /**
     * The rectangle of an element in page coordinates, with padding, kept
     * inside the page.
     *
     * @return array{x: float, y: float, width: float, height: float}
     */
    public function rect(string $selector, int $pad = 0): array
    {
        $rect = $this->js(<<<'JS'
            (() => {
                const el = [...document.querySelectorAll(__S__)].find(e => e.offsetParent !== null || e.getClientRects().length);
                if (!el) return null;
                const r = el.getBoundingClientRect();
                return {x: r.left + scrollX, y: r.top + scrollY, width: r.width, height: r.height, pageWidth: document.documentElement.scrollWidth};
            })()
        JS, ['__S__' => $selector]);

        if (! is_array($rect)) {
            throw new RuntimeException("Nothing to crop to: “{$selector}” on ".$this->js('location.href'));
        }

        $x = max(0, $rect['x'] - $pad);
        $y = max(0, $rect['y'] - $pad);

        return [
            'x' => $x,
            'y' => $y,
            'width' => min($rect['width'] + 2 * $pad, max(1, $rect['pageWidth'] - $x)),
            'height' => $rect['height'] + 2 * $pad,
        ];
    }

    /**
     * Save the window, or part of it, as a PNG at the device scale.
     *
     * @param  string|array{x: float, y: float, width: float, height: float}|null  $clip  A selector, a rectangle in page coordinates, or the whole window.
     */
    public function capture(string $path, string|array|null $clip = null, int $pad = 0): void
    {
        $params = ['format' => 'png', 'captureBeyondViewport' => false];

        if ($clip !== null) {
            $rect = is_string($clip) ? $this->rect($clip, $pad) : $clip;
            $params['clip'] = $rect + ['scale' => 1];
            // Only when it has to: capturing beyond the viewport resizes the
            // page for a moment, which some CPs answer by re-rendering forms.
            $params['captureBeyondViewport'] = (float) $this->js('innerHeight + scrollY') < $rect['y'] + $rect['height'];
        }

        $png = base64_decode($this->ws->send('Page.captureScreenshot', $params)['data']);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $png);
    }
}
