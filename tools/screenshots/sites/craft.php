<?php

/**
 * The Craft test site (gw-test-craft): Craft 5, MySQL, the plugin under
 * /admin/ghostwriter. Craft's control panel has no dark mode, so there are
 * no dark shots.
 */
class CraftSite extends Site
{
    /**
     * Ghostwriter actions the shots may post: none of them calls a model.
     * Every other Ghostwriter post from the page is stopped in the browser,
     * as a second line of defence behind the seeded state.
     */
    private const SAFE_ACTIONS = ['sessions/apply', 'sessions/edit', 'sessions/edit-field', 'sessions/draft', 'setup/hide', 'preview/markdown'];

    public function name(): string
    {
        return 'Craft CMS';
    }

    public function state(): State
    {
        // The runtime cache goes back with the database, so nothing cached
        // during the run outlives the rows it was read from.
        return (new State($this->root, 'craft'))
            ->files(['.env', 'web/uploads', 'storage/runtime/cache'])
            ->mysql('gw_test_craft');
    }

    public function seed(): array
    {
        return $this->seeder('craft.php', [$this->url]);
    }

    public function signIn(Browser $browser): void
    {
        $browser->ws->send('Page.addScriptToEvaluateOnNewDocument', ['source' => $this->guard()]);
        // Headless Chrome has no focused window; the draft piece being
        // edited only looks it with focus emulated.
        $browser->ws->send('Emulation.setFocusEmulationEnabled', ['enabled' => true]);

        $browser->go($this->url.'/admin/login');
        $browser->waitForSelector('.login-form .login-username');
        $browser->fill('.login-form .login-username', getenv('GW_SHOT_EMAIL') ?: $this->testing('Username'));
        $browser->fill('.login-form .login-password', getenv('GW_SHOT_PASSWORD') ?: $this->testing('Password'));
        $browser->clickSelector('.login-form button[type=submit]');
        $browser->until('location.pathname.startsWith("/admin") && !location.pathname.startsWith("/admin/login")', 30, 'the dashboard after signing in');
    }

    public function tidy(Browser $browser): void
    {
        // Dev mode's striped bar, the queue's progress in the menu, and any
        // licence or edition notices.
        $blocked = $browser->js('window.__gwBlocked || []');

        if ($blocked) {
            fwrite(STDERR, '    Stopped in the browser: '.implode(', ', $blocked)."\n");
        }

        $browser->js('(() => { document.body.classList.remove("devmode"); for (const el of document.querySelectorAll("#devmode, #alerts, .cp-alerts, li:has(> #job-icon), #job-icon")) el.remove(); })()');
    }

    public function shots(): array
    {
        $panel = '.modal.gw-modal';

        return [
            'get-started-connect' => [
                'url' => '/admin/ghostwriter/setup#step-1',
                'ready' => ['Connected. Ghostwriter writes with'],
            ],
            'get-started-kinds' => [
                // Opened from the step list: an address differing from the
                // last shot's only by its hash would not load the page again.
                'url' => '/admin/ghostwriter/setup',
                'steps' => function (Browser $browser): void {
                    $browser->waitForText('Teach it your kinds of content');
                    $browser->clickSelector('[data-wizard="go"][data-step="3"]');
                    $browser->waitForText('Learn this');
                    $browser->js('window.scrollTo(0, 0)');
                },
                'ready' => ['Learn this', 'Not this'],
            ],
            'overview' => [
                'url' => '/admin/ghostwriter',
                'ready' => ['In progress', 'Sections'],
            ],
            'widget' => [
                'url' => '/admin/dashboard',
                'ready' => ['css:.gw-widget'],
                'clip' => '.widget:has(.gw-widget)',
                'pad' => 16,
            ],
            'settings' => [
                'url' => '/admin/settings/plugins/ghostwriter',
                'ready' => ['Write for these sections'],
            ],
            'settings-locked' => [
                'url' => '/admin/settings/plugins/ghostwriter',
                'ready' => ['which wins over this screen'],
                // The field as wide as what it says, not the whole form.
                'steps' => function (Browser $browser): void {
                    $browser->js('document.querySelector(\'.field:has(input[name$="[sections][]"])\').style.width = "fit-content"');
                },
                'clip' => '.field:has(input[name$="[sections][]"])',
                'pad' => 20,
            ],
            'voice-guide' => [
                'url' => '/admin/ghostwriter/voice',
                'ready' => ['Rescan and rewrite', 'Ask for a change', 'css:#gw-voice-editor:not(.hidden)'],
            ],
            'image-style' => [
                'url' => '/admin/ghostwriter/imagery',
                'ready' => ['Look again and rewrite', 'css:#gw-voice-editor:not(.hidden)'],
            ],
            'kinds-suggested' => [
                'url' => '/admin/ghostwriter',
                'steps' => function (Browser $browser): void {
                    $browser->js('document.querySelector(\'.gw-sec[data-section="journal"] details.gw-suggestions\').open = true');
                    $browser->hide(['.gw-sec[data-section="pages"]']);
                    $browser->js('document.querySelector(\'.gw-sec[data-section="pages"]\').style.display = "none"');
                },
                'ready' => ['Learn all 2', 'For example:'],
                'clip' => '.gw-secs',
                'pad' => 16,
            ],
            'kind-editor' => [
                'url' => '/admin/ghostwriter/types/project-story',
                'ready' => ['The brief', 'Guidance for the writer', 'Modelled on'],
                // Tall enough for the checklist and the examples at the foot.
                'height' => 1500,
                'clip' => '#main',
            ],
            'teach-kind' => [
                'url' => '/admin/ghostwriter/teach/journal',
                'ready' => ['What is this kind of content called?'],
            ],
            'writing-choose' => [
                'url' => fn (array $c) => $c['blank'].'&ghostwriter=new',
                'ready' => ['What are you writing?', 'Something else', 'Or carry on with'],
            ],
            'writing-brief' => [
                'url' => fn (array $c) => $c['blank'].'&ghostwriter=new',
                'steps' => function (Browser $browser): void {
                    $browser->waitForText('What are you writing?');
                    $browser->clickSelector('[data-action="choose-general"]');
                    $browser->waitForText('Quick brief');
                    $browser->fill('[data-model="quick-title"]', 'Moving a garden to a new house');
                    $browser->fill('#gw-q-subject', 'What to dig up and take with you when you move house, what to leave, and how to keep plants alive in pots until the new garden is ready.');
                    $browser->fill('#gw-q-reader', 'Clients and readers who are about to move. They should make a list before the removal van is booked.');
                    $browser->fill('#gw-q-points', "- Check the sale contract: plants can be fixtures.\n- Autumn and winter moves are kindest to plants.\n- Take cuttings and divisions rather than whole shrubs.\n- Pot up in peat-free compost and keep them together in a sheltered spot.");
                    $browser->fill('[data-model="quick-notes"]', 'Lots of clients move just as their garden is getting going. What we tell them.');
                    $browser->js('document.querySelector("#gw-q-points").rows = 5');
                    $browser->clickSelector('#gw-example-19 + label');
                },
                'ready' => ['Start writing', 'Fill in the brief', 'Modelled on'],
                // A taller window, so the whole brief fits in the panel.
                'height' => 2000,
                'clip' => '.modal.gw-modal',
            ],
            'writing-questions' => [
                'url' => fn (array $c) => $c['questions'],
                'ready' => ['needs your answer', 'Just draft it with what you have'],
            ],
            'writing-draft' => [
                'url' => fn (array $c) => $c['draft'],
                'ready' => ['Use this draft', 'What we do'],
                'steps' => function (Browser $browser): void {
                    $browser->waitForText('Use this draft');
                    $browser->js(<<<'JS'
                        (() => {
                            const block = [...document.querySelectorAll('.gw-block')].find((b) => b.querySelector('.gw-block__name')?.textContent.trim() === 'Text');
                            const field = block?.querySelector('[data-edit-path]');
                            if (!field) return false;
                            field.dataset.gwKeepFocus = '1';
                            field.focus();
                            const range = document.createRange();
                            range.selectNodeContents(field);
                            range.collapse(false);
                            getSelection().removeAllRanges();
                            getSelection().addRange(range);
                            return true;
                        })()
                    JS);
                },
            ],
            'writing-used' => [
                // A copy of the page draft, made only now (see the seeder).
                'url' => fn (array $c) => $this->seeder('craft.php', [$this->url, 'used', $c['draftSession']])['used'],
                'steps' => function (Browser $browser): void {
                    $browser->waitForText('Use this draft');
                    $browser->click('Use this draft');
                    $browser->until('!location.search.includes("ghostwriter=")', 30, 'the form to reload');
                    $browser->waitForText('Check it over, then save.', 30);
                },
                'ready' => ['Draft added to the form'],
            ],
            'writing-shared' => [
                'url' => fn (array $c) => $c['shared'],
                'ready' => ['Maya Lindqvist', 'A note on squirrels'],
            ],
            'writing-failed' => [
                'url' => fn (array $c) => $c['failed'],
                'ready' => ['Try again', 'The provider is busy right now.'],
                'clip' => $panel.' .gw-convo',
                'pad' => 16,
            ],
            'editing' => [
                'url' => fn (array $c) => $c['editing'],
                'ready' => ['I have the entry as it stands', 'Use these changes', 'Start again from the entry'],
            ],
            'image-button' => [
                'url' => fn (array $c) => $c['pictured'],
                'ready' => ['css:.gw-image-launch'],
                'clip' => '.field:has(.gw-image-launch)',
                'pad' => 16,
            ],
            'image-find' => [
                'url' => fn (array $c) => $c['pictured'],
                'steps' => function (Browser $browser): void {
                    $this->openImages($browser);
                    $browser->clickSelector('.gw-image-modal .gw-image-search');
                    $browser->waitForText('Best match', 30);
                    $browser->until('[...document.querySelectorAll(".gw-image-modal .gw-photo:not(.hidden) img")].every((i) => i.complete && i.naturalWidth)', 20, 'the thumbnails');
                },
                'ready' => ['Searched for:', 'View 3 more'],
                'clip' => '.modal.gw-image-modal',
                'pad' => 24,
            ],
            'image-make' => [
                'url' => fn (array $c) => $c['pictured'],
                'steps' => function (Browser $browser): void {
                    $this->openImages($browser);
                    $browser->clickSelector('.gw-image-modal [data-mode="make"]');
                    $browser->fill('.gw-image-modal .gw-image-direction', 'Allium seed heads in a border at dusk, low sun behind soft hills');
                    $browser->clickSelector('.gw-image-modal .gw-image-make');
                    $browser->waitForText('Make another', 30);
                    $browser->until('[...document.querySelectorAll(".gw-image-modal .gw-photo--made img")].every((i) => i.complete && i.naturalWidth)', 20, 'the made picture');
                },
                'ready' => ['Make another'],
                'clip' => '.modal.gw-image-modal',
                'pad' => 24,
            ],
            'plan' => [
                'url' => '/admin/ghostwriter/plan',
                'ready' => ['In progress', 'Back to ideas', 'Not this one', 'Ask what is missing'],
            ],
            'plan-waiting' => [
                'url' => '/admin/ghostwriter/plan',
                'ready' => ['3 suggestions waiting'],
                'clip' => '.gw-waiting',
                'pad' => 16,
            ],
            'plan-suggestions' => [
                'url' => '/admin/ghostwriter/plan',
                'steps' => function (Browser $browser): void {
                    $browser->waitForText('3 suggestions waiting');
                    $browser->clickSelector('[data-plan="review"]');
                },
                'ready' => ['Ghostwriter suggests', 'Add 3 to the plan', 'Drop them all'],
                'clip' => '.modal.gw-review-modal',
                'pad' => 24,
            ],
        ];
    }

    public function store(): array
    {
        $draft = $this->shots()['writing-draft'];
        unset($draft['steps']);

        // A short window keeps the centred dialog in the part of the shot the frame shows.
        $find = $this->shots()['image-find'];
        $find['height'] = 640;

        return [
            '01-writing-panel' => [$draft, 'Drafts in your voice, beside the entry', 'Start from a short brief. The draft lands in the normal entry form for you to check and save.'],
            '02-content-plan' => ['plan', 'Keeps a plan of what the site is missing', 'A running list of the entries worth writing next, drawn from what is already there.'],
            '03-image-choices' => [$find, 'Pictures that look like yours', 'Finds or makes images to match the site\'s own image style guide.'],
            '04-voice-guide' => ['voice-guide', 'Learns how the site already writes', 'A tone of voice guide built from your own entries, and editable like any other.'],
        ];
    }

    private function openImages(Browser $browser): void
    {
        $browser->waitForSelector('.gw-image-launch');
        $browser->clickSelector('.gw-image-launch');
        $browser->waitForSelector('.modal.gw-image-modal');
    }

    /**
     * Run before every page's own scripts: Ghostwriter posts that would
     * queue a model call never leave the browser. Starting a photo search
     * or a picture is answered with the seeded request instead, which the
     * real status action then serves.
     */
    private function guard(): string
    {
        $script = <<<'JS'
            (() => {
                const safe = __SAFE__;
                const found = __FOUND__;
                const made = __MADE__;
                const action = (url) => (decodeURIComponent(String(url)).match(/actions\/ghostwriter\/([a-z\/-]+)/) || [])[1] || null;
                window.__gwBlocked = [];

                const reply = (name, body) => {
                    window.__gwBlocked.push(name);

                    if (name !== 'images/start') return { message: 'Stopped by the screenshot tool.' };

                    const making = body instanceof FormData;

                    return { id: making ? made : found, mode: making ? 'make' : 'find', status: 'working', error: null, terms: [], options: [], judged: false, noneFit: false, withReferences: false, preview: null };
                };

                const open = XMLHttpRequest.prototype.open;
                const send = XMLHttpRequest.prototype.send;

                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    this.__gw = { method: String(method).toUpperCase(), url };

                    return open.call(this, method, url, ...rest);
                };

                XMLHttpRequest.prototype.send = function (body) {
                    const name = this.__gw && this.__gw.method !== 'GET' ? action(this.__gw.url) : null;

                    if (name && !safe.includes(name)) {
                        open.call(this, 'GET', 'data:application/json,' + encodeURIComponent(JSON.stringify(reply(name, body))), true);

                        return send.call(this, null);
                    }

                    return send.call(this, body);
                };

                const fetch = window.fetch;

                window.fetch = function (input, init = {}) {
                    const name = String(init.method || 'GET').toUpperCase() !== 'GET' ? action(input.url || input) : null;

                    if (name && !safe.includes(name)) {
                        return Promise.resolve(new Response(JSON.stringify(reply(name, init.body)), { headers: { 'Content-Type': 'application/json' } }));
                    }

                    return fetch.call(this, input, init);
                };

                document.addEventListener('submit', (event) => {
                    const name = event.target.querySelector('input[name="action"]')?.value?.replace(/^ghostwriter\//, '');

                    if (event.target.querySelector('input[name="action"]')?.value?.startsWith('ghostwriter/') && !safe.includes(name)) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                        window.__gwBlocked.push(name);
                    }
                }, true);
            })();
        JS;

        return strtr($script, [
            '__SAFE__' => json_encode(self::SAFE_ACTIONS),
            '__FOUND__' => json_encode($this->context['found'] ?? ''),
            '__MADE__' => json_encode($this->context['made'] ?? ''),
        ]);
    }
}
