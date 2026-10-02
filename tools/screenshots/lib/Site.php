<?php

/**
 * One test site: where it is, how to sign in, how to seed the states the
 * shots need (without a model call) and the shots themselves.
 *
 * A shot is an array:
 * - `url`: a path on the site, or a closure given the seeded context
 * - `steps`: optional closure (Browser, Site) run after the page loads, to open or click things
 * - `ready`: text (or `css:selector`) that must be on screen before the shot
 * - `clip`: optional selector (or `[x, y, width, height]`) to crop to; `pad` around it
 * - `scheme`: `light` (default) or `dark`
 * - `height`: a taller window for this shot, for a long crop
 * - `hide`: selectors to hide (banners, the signed-in email)
 */
abstract class Site
{
    /** @var array<string, mixed> What the seeder made: IDs, URLs. */
    public array $context = [];

    public function __construct(public string $root, public string $addon, public string $url) {}

    /** The CMS's name, as in "For Statamic". */
    abstract public function name(): string;

    abstract public function state(): State;

    /**
     * Make every state the shots need. Returns what was made (IDs).
     *
     * @return array<string, mixed>
     */
    abstract public function seed(): array;

    abstract public function signIn(Browser $browser): void;

    /**
     * @return array<string, array<string, mixed>>
     */
    abstract public function shots(): array;

    /**
     * The four store images: name => [shot, headline, line]. The shot is a
     * shot array taken at the full window, or the ID of one in shots().
     *
     * @return array<string, array{0: string|array<string, mixed>, 1: string, 2: string}>
     */
    abstract public function store(): array;

    /**
     * Chores after every page load, such as closing a licence notice.
     */
    public function tidy(Browser $browser): void {}

    /**
     * Switch the CP's own theme where it keeps one, beyond the media query.
     */
    public function theme(Browser $browser, string $scheme): void {}

    /**
     * Run a seeder script inside the site and read the JSON it prints last.
     *
     * @param  array<int, string>  $args
     * @return array<string, mixed>
     */
    protected function seeder(string $script, array $args): array
    {
        $command = 'cd '.escapeshellarg($this->root).' && php '.escapeshellarg(__DIR__.'/../seeders/'.$script).' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
        exec($command, $output, $status);

        $last = (string) end($output);
        $json = json_decode($last, true);

        if ($status !== 0 || ! is_array($json)) {
            throw new RuntimeException("The seeder failed:\n".implode("\n", array_slice($output, -40)));
        }

        return $json;
    }

    /**
     * Read a value from the site's TESTING.md: the first `code` after the label.
     */
    protected function testing(string $label): string
    {
        $file = $this->root.'/TESTING.md';
        $text = is_file($file) ? (string) file_get_contents($file) : '';

        if (! preg_match('/'.$label.'.*?`([^`]+)`/s', $text, $m)) {
            throw new RuntimeException("Couldn't read {$label} from {$file}; set it in the environment instead.");
        }

        return $m[1];
    }

    public function absolute(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : $this->url.$path;
    }
}
