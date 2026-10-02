<?php

/**
 * The Statamic test site (gw-test-statamic): flat-file content and users,
 * Ghostwriter's state under storage/ghostwriter and resources/ghostwriter.
 */
class StatamicSite extends Site
{
    public function name(): string
    {
        return 'Statamic';
    }

    public function state(): State
    {
        // The database only holds the queue and an unused cache table, and
        // nothing here writes to it, so it is checked rather than copied:
        // replacing the jobs table under running workers would trip them up.
        return (new State($this->root, 'statamic'))->files([
            '.env',
            'users',
            'content',
            'resources/ghostwriter',
            'resources/addons',
            'storage/ghostwriter',
            'public/assets',
        ]);
    }

    public function seed(): array
    {
        return $this->seeder('statamic.php', [$this->email()]);
    }

    private function email(): string
    {
        return getenv('GW_SHOT_EMAIL') ?: $this->testing('Login');
    }

    public function signIn(Browser $browser): void
    {
        // The password is the second `code` on the Login line.
        $password = getenv('GW_SHOT_PASSWORD') ?: $this->testing('Login:\*\*[^`]*`[^`]+`[^`]*');

        $browser->go($this->url.'/cp/auth/login');
        $browser->waitForSelector('input[type=password]');
        $browser->fill('input[type=email], input[name=email]', $this->email());
        $browser->fill('input[type=password]', $password);
        $browser->clickSelector('button[type=submit]');
        $browser->until('location.pathname.startsWith("/cp") && !location.pathname.includes("/auth/login")', 20, 'the Control Panel after signing in');

        // On every page from here on: nothing that would start a model call
        // (or any other background work) can be sent, and the image
        // dialog's searches are answered by the requests seeded for it.
        // A headless page has no focus, so the draft's piece being edited
        // would not show as focused without this.
        $browser->ws->send('Emulation.setFocusEmulationEnabled', ['enabled' => true]);

        $browser->ws->send('Page.addScriptToEvaluateOnNewDocument', ['source' => strtr($this->guard(), [
            '__FIND__' => json_encode($this->context['images']['find']),
            '__MAKE__' => json_encode($this->context['images']['make']),
        ])]);
    }

    /**
     * Ghostwriter's endpoints that change something are refused in the
     * page, except "Use this draft", which only fills the form. The image
     * dialog's "Find photos" and "Make the picture" become reads of the seeded
     * requests, so the dialog shows them as it would a finished search.
     */
    private function guard(): string
    {
        return <<<'JS'
            (() => {
                const seeded = { find: __FIND__, make: __MAKE__ };
                const refused = (method, path) => {
                    if (!path.startsWith('/cp/')) return false;
                    if (method === 'GET') return /\/cp\/ghostwriter\/sessions\/[^/]+\/photos$/.test(path);
                    if (/\/cp\/ghostwriter\/sessions\/[^/]+\/apply$/.test(path)) return false;
                    return path.startsWith('/cp/ghostwriter') || /\/cp\/collections\/[^/]+\/entries/.test(path);
                };
                const note = (what) => { (window.__gwRefused = window.__gwRefused || []).push(what); console.warn('Screenshots refused', what); };
                const open = XMLHttpRequest.prototype.open;
                const send = XMLHttpRequest.prototype.send;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    this.__gw = { method: String(method).toUpperCase(), url: String(url), rest };
                    return open.call(this, method, url, ...rest);
                };
                XMLHttpRequest.prototype.send = function (body) {
                    const request = this.__gw || { method: 'GET', url: location.href, rest: [] };
                    const path = new URL(request.url, location.href).pathname;
                    if (request.method === 'POST' && /\/cp\/ghostwriter\/images$/.test(path) && body instanceof FormData && seeded[body.get('mode')]) {
                        open.call(this, 'GET', path + '/' + seeded[body.get('mode')], ...request.rest);
                        this.setRequestHeader('Accept', 'application/json');
                        this.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                        return send.call(this, null);
                    }
                    if (refused(request.method, path)) {
                        note(request.method + ' ' + path);
                        throw new Error('Refused by the screenshot tool: ' + request.method + ' ' + path);
                    }
                    return send.call(this, body);
                };
                const fetched = window.fetch;
                window.fetch = function (input, init = {}) {
                    const url = typeof input === 'string' ? input : input.url;
                    const method = String(init.method || (typeof input === 'string' ? 'GET' : input.method) || 'GET').toUpperCase();
                    const path = new URL(url, location.href).pathname;
                    if (refused(method, path)) {
                        note(method + ' ' + path);
                        return Promise.reject(new Error('Refused by the screenshot tool'));
                    }
                    return fetched.apply(this, arguments);
                };
            })();
        JS;
    }

    public function theme(Browser $browser, string $scheme): void
    {
        // The CP picks its mode as the page loads, from the user's
        // preference or the media query; make sure it matches.
        $browser->js('document.documentElement.classList.toggle("dark", __DARK__)', ['__DARK__' => $scheme === 'dark']);
    }

    public function tidy(Browser $browser): void
    {
        // A trial-mode site greets each fresh browser with a licence notice.
        $refused = $browser->js(<<<'JS'
            (() => {
                [...document.querySelectorAll('button')].find(b => /snooze/i.test(b.textContent))?.click();
                // The edition badge beside the site name: "Pro – Trial Mode".
                for (const el of document.querySelectorAll('header *')) if (el.children.length === 0 && /trial mode/i.test(el.textContent)) el.style.setProperty('visibility', 'hidden', 'important');
                return window.__gwRefused || [];
            })()
        JS);

        if ($refused) {
            throw new RuntimeException('The page tried to start work the shots must not: '.implode(', ', $refused));
        }
    }

    public function shots(): array
    {
        $session = fn (string $name) => fn (array $c) => $c['create'][$name === 'draft' || $name === 'editing' ? 'pages' : 'journal'].'?ghostwriter='.$c['sessions'][$name];
        // Wait for the page, then run a script (usually one that marks what to crop to).
        $mark = fn (string $wait, string $script) => function (Browser $b) use ($wait, $script) {
            $b->waitForText($wait);
            $b->js($script);
        };

        return [
            'get-started-connect' => [
                'url' => '/cp/ghostwriter/setup?shot=connect#step-1',
                'ready' => 'Writing with Claude (Anthropic).',
            ],
            'get-started-kinds' => [
                'url' => '/cp/ghostwriter/setup?shot=kinds#step-4',
                'ready' => ['Teach it your kinds of content', 'Seasonal advice', 'Learn this'],
                'steps' => $mark('Seasonal advice', <<<'JS'
                    (() => {
                        const heading = [...document.querySelectorAll('#main-content *')].find(e => e.children.length === 0 && e.textContent.trim() === 'Journal');
                        heading.closest('.rounded-md').scrollIntoView({block: 'start'});
                        document.querySelector('#main-content').scrollBy(0, -24);
                    })()
                JS),
            ],
            'overview' => [
                'url' => '/cp/ghostwriter',
                'ready' => ['In progress', 'A wildlife pond for a small garden'],
            ],
            'overview-dark' => [
                'url' => '/cp/ghostwriter?shot=dark',
                'scheme' => 'dark',
                'ready' => ['In progress', 'A wildlife pond for a small garden'],
            ],
            'widget' => [
                'url' => '/cp/dashboard',
                'ready' => 'Open Ghostwriter',
                'steps' => function (Browser $b) {
                    $b->waitForText('Write something');
                    $b->js(<<<'JS'
                        (() => {
                            let card = [...document.querySelectorAll('#main-content button, #main-content a')].find(e => e.textContent.trim() === 'Write something');
                            while (card && !/^\s*Ghostwriter/.test(card.innerText)) card = card.parentElement;
                            card.setAttribute('data-gw-shot', 'widget');
                        })()
                    JS);
                },
                'clip' => '[data-gw-shot=widget]',
                'pad' => 16,
            ],
            'settings' => [
                'url' => '/cp/addons/ghostwriter-statamic/settings',
                'ready' => 'ANTHROPIC_API_KEY',
                'steps' => $mark('ANTHROPIC_API_KEY', <<<'JS'
                    (() => {
                        const heading = [...document.querySelectorAll('#main-content *')].find(e => e.children.length === 0 && e.textContent.trim() === 'API keys');
                        heading.scrollIntoView({block: 'start'});
                        document.querySelector('#main-content').scrollBy(0, -40);
                    })()
                JS),
            ],
            'settings-locked' => [
                'url' => '/cp/addons/ghostwriter-statamic/settings?shot=locked',
                'ready' => 'which wins over this screen',
                'steps' => $mark('which wins over this screen', <<<'JS'
                    (() => {
                        const note = [...document.querySelectorAll('*')].find(e => e.children.length === 0 && /which wins over this screen/.test(e.textContent));
                        // The AI provider section: the locked provider, with the model under it.
                        let section = note;
                        while (section && !/^\s*AI provider/.test(section.innerText)) section = section.parentElement;
                        section.setAttribute('data-gw-shot', 'locked');
                    })()
                JS),
                'height' => 1600,
                'clip' => '[data-gw-shot=locked]',
                'pad' => 20,
            ],
            'voice-guide' => [
                'url' => '/cp/ghostwriter/voice',
                'ready' => ['How Northfold writes', 'Ask for a change'],
            ],
            'image-style' => [
                'url' => '/cp/ghostwriter/imagery',
                'ready' => 'Look again and rewrite',
            ],
            'kinds-suggested' => [
                'url' => '/cp/ghostwriter?shot=kinds',
                'ready' => 'suggested kinds to review',
                'steps' => function (Browser $b) {
                    $b->waitForText('2 suggested kinds to review');
                    $b->click('2 suggested kinds to review');
                    $b->waitForText('Not this');
                    $b->js(<<<'JS'
                        (() => {
                            const heading = [...document.querySelectorAll('#main-content *')].find(e => e.children.length === 0 && e.textContent.trim() === 'Journal');
                            const row = heading.closest('.p-4');
                            row.setAttribute('data-gw-shot', 'journal');
                        })()
                    JS);
                },
                'height' => 1300,
                'clip' => '[data-gw-shot=journal]',
                'pad' => 8,
            ],
            'kind-editor' => [
                'url' => '/cp/ghostwriter/types/project-story',
                'ready' => 'Project story',
            ],
            'teach-kind' => [
                'url' => fn (array $c) => $c['create']['journal'].'?ghostwriter=new',
                'ready' => 'What are you writing?',
                'steps' => function (Browser $b) {
                    $b->waitForText('What are you writing?');
                    $b->click('Teach a kind');
                },
                'ready' => 'Teach Ghostwriter a kind of content',
            ],
            'writing-choose' => [
                'url' => fn (array $c) => $c['create']['journal'].'?ghostwriter=new&shot=choose',
                'ready' => ['What are you writing?', 'Or carry on with'],
            ],
            'writing-brief' => [
                'url' => fn (array $c) => $c['create']['journal'].'?ghostwriter=new&shot=brief',
                'ready' => 'Model it on',
                'steps' => function (Browser $b) {
                    $b->waitForText('Something else');
                    $b->click('Something else', 'button');
                    $b->waitForText('Quick brief');
                    $b->fill('input[placeholder="Working title"]', 'Why we plant in threes and fives');
                    $b->fill('textarea[placeholder^="Notes"]', 'Our own border at the old dairy as the example.');
                    $answers = [
                        'Why odd-numbered groups of plants look more natural in a border than pairs and rows, and how to use them in your own garden.',
                        'Home gardeners planning a border this autumn. They should come away ready to plant in groups.',
                        'Groups of three, five and seven. Repeat the same group along a border. Space them unevenly. Our border at the old dairy as the example.',
                        'About 600 words.',
                    ];
                    $b->js(<<<'JS'
                        (() => {
                            const brief = [...document.querySelectorAll('.max-w-3xl')].find(e => /Quick brief/.test(e.textContent));
                            const boxes = [...brief.querySelectorAll('textarea')].filter(t => !t.placeholder.startsWith('Notes'));
                            const set = Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set;
                            __ANSWERS__.forEach((answer, i) => { if (!boxes[i]) return; set.call(boxes[i], answer); boxes[i].dispatchEvent(new Event('input', {bubbles: true})); });
                        })()
                    JS, ['__ANSWERS__' => $answers]);
                    $b->click('Why we leave the seedheads standing', 'label');
                    $b->click('Five trees for a small garden', 'label');
                    $b->js('[...document.querySelectorAll(".max-w-3xl")].find(e => /Quick brief/.test(e.textContent)).setAttribute("data-gw-shot", "brief")');
                },
                // The whole brief, down to "Start writing", in one crop.
                'height' => 2300,
                'clip' => '[data-gw-shot=brief]',
                'pad' => 24,
            ],
            'writing-questions' => [
                'url' => $session('questions'),
                'ready' => ['Ghostwriter needs your answer', 'Just draft it with what you have'],
            ],
            'writing-draft' => [
                'url' => $session('draft'),
                'ready' => ['Use this draft', 'What winter care is for'],
                'steps' => $mark('What winter care is for', <<<'JS'
                    (() => {
                        const piece = [...document.querySelectorAll('[contenteditable]')].find(e => /What winter care is for/.test(e.textContent));
                        piece.dataset.gwKeepFocus = '1';
                        piece.focus();
                        piece.scrollIntoView({block: 'center'});
                    })()
                JS),
            ],
            'writing-draft-dark' => [
                'url' => fn (array $c) => $c['create']['pages'].'?ghostwriter='.$c['sessions']['draft'].'&shot=dark',
                'scheme' => 'dark',
                'ready' => ['Use this draft', 'What winter care is for'],
            ],
            'writing-shared' => [
                'url' => $session('shared'),
                'ready' => ['Maya Lindqvist', 'Bring wellies'],
            ],
            'writing-failed' => [
                'url' => $session('failed'),
                'ready' => ['That didn’t work', 'Try again'],
                'steps' => $mark('Try again', <<<'JS'
                    (() => {
                        const chat = [...document.querySelectorAll('.lg\\:col-span-2')].find(e => /That didn’t work/.test(e.textContent));
                        chat.setAttribute('data-gw-shot', 'chat');
                    })()
                JS),
                'clip' => '[data-gw-shot=chat]',
                'pad' => 16,
            ],
            'editing' => [
                'url' => fn (array $c) => $c['about'].'?ghostwriter='.$c['sessions']['editing'],
                'ready' => ['I have the entry as it stands', 'Use these changes', 'Start again from the entry'],
                // Down to the call to action, the block that was changed.
                'steps' => $mark('spring 2027 is already filling up', <<<'JS'
                    (() => {
                        const changed = [...document.querySelectorAll('[contenteditable]')].find(e => /spring 2027 is already filling up/.test(e.textContent));
                        changed.closest('.rounded-lg').scrollIntoView({block: 'end'});
                    })()
                JS),
            ],
            'image-button' => [
                'url' => fn (array $c) => $c['image_entry'],
                'ready' => 'Hero image',
                'steps' => $mark('Hero image', <<<'JS'
                    (() => {
                        const label = [...document.querySelectorAll('label')].find(e => e.textContent.trim().startsWith('Hero image'));
                        let field = label;
                        while (field && field.getBoundingClientRect().height < 120) field = field.parentElement;
                        field.setAttribute('data-gw-shot', 'hero');
                    })()
                JS),
                'height' => 1400,
                'clip' => '[data-gw-shot=hero]',
                'pad' => 16,
            ],
            'image-find' => [
                'url' => fn (array $c) => $c['image_entry'].'?shot=find',
                'ready' => 'Hero image',
                'steps' => function (Browser $b) {
                    $this->openImageDialog($b);
                    $b->waitForText('Find photos');
                    $b->click('Find photos');
                    $b->waitForText('Searched for:');
                    $b->waitForText('Best match');
                },
                'wait' => 1.5,
            ],
            'image-make' => [
                'url' => fn (array $c) => $c['image_entry'].'?shot=make',
                'ready' => 'Hero image',
                'steps' => function (Browser $b) {
                    $this->openImageDialog($b);
                    $b->waitForText('Make one');
                    $b->click('Make one', 'button');
                    $b->click('Make the picture');
                    $b->waitForText('Make another');
                    $b->until('[...document.querySelectorAll("[role=dialog] img")].every(i => i.complete && i.naturalWidth)', 20, 'the made picture');
                    $b->js('[...document.querySelectorAll("button")].find(e => e.textContent.trim() === "Make another").scrollIntoView({block: "end"})');
                },
                'wait' => 1.5,
            ],
            'image-panel' => [
                'url' => fn (array $c) => $c['create']['pages'].'?ghostwriter='.$c['sessions']['draft'].'&shot=images',
                'ready' => ['Use this draft', 'Searched for:'],
                'steps' => function (Browser $b) {
                    $b->waitForText('Searched for:');
                    $b->scrollTo('[data-gw-images]');
                },
            ],
            'plan' => [
                'url' => '/cp/ghostwriter/plan',
                'ready' => ['Ask what is missing', 'Winter garden care'],
            ],
            'plan-waiting' => [
                'url' => '/cp/ghostwriter/plan?shot=waiting',
                'ready' => '3 suggestions waiting',
                'steps' => $mark('3 suggestions waiting', <<<'JS'
                    (() => {
                        const text = [...document.querySelectorAll('*')].find(e => e.children.length === 0 && /3 suggestions waiting/.test(e.textContent));
                        let card = text;
                        while (card && !card.querySelector('button')) card = card.parentElement;
                        card.setAttribute('data-gw-shot', 'waiting');
                    })()
                JS),
                'clip' => '[data-gw-shot=waiting]',
                'pad' => 16,
            ],
            'plan-suggestions' => [
                'url' => '/cp/ghostwriter/plan?shot=suggestions',
                'ready' => '3 suggestions waiting',
                'steps' => function (Browser $b) {
                    $b->waitForText('3 suggestions waiting');
                    $b->click('Review');
                    $b->waitForText('Add 3 to the plan');
                    $b->js('[...document.querySelectorAll("button")].find(e => /^Add 3 to the plan/.test(e.textContent.trim())).scrollIntoView({block: "end"})');
                },
                'wait' => 1,
            ],
            // Last, and on the shared piece: using a draft marks it as put
            // into a form, which would change how it shows everywhere else.
            'writing-used' => [
                'url' => fn (array $c) => $c['create']['journal'].'?ghostwriter='.$c['sessions']['shared'].'&shot=used',
                'ready' => 'Draft added to the form',
                'steps' => function (Browser $b) {
                    $b->waitForText('Use this draft');
                    $b->click('Use this draft');
                    $b->waitForText('Draft added to the form');
                    // The notes are a notice above the form (F7), not a toast.
                    $b->until('!!document.querySelector("[data-ghostwriter-notes]")', 10, 'the notes notice');
                },
                'wait' => 1.2,
            ],
        ];
    }

    /**
     * The Ghostwriter button beside the hero image field, and the dialog it opens.
     */
    private function openImageDialog(Browser $browser): void
    {
        $browser->until('[...document.querySelectorAll("button")].some(b => /ghostwriter/i.test(b.getAttribute("aria-label") || b.title || b.textContent))', 20, 'the image button');
        $browser->js(<<<'JS'
            (() => {
                const label = [...document.querySelectorAll('label')].find(e => e.textContent.trim().startsWith('Hero image'));
                let field = label;
                while (field && !field.querySelector('button[aria-label="Ghostwriter"], button[title="Ghostwriter"]')) field = field.parentElement;
                (field || document).querySelector('button[aria-label="Ghostwriter"], button[title="Ghostwriter"]').click();
            })()
        JS);
        $browser->waitForText('Image for Hero image');
    }

    public function store(): array
    {
        return [
            '01-writing-panel' => [
                ['url' => fn (array $c) => $c['create']['pages'].'?ghostwriter='.$c['sessions']['draft'].'&shot=store', 'ready' => ['Use this draft', 'What winter care is for']],
                'Drafts in your voice, beside the entry',
                'Start from a short brief. The draft lands in the normal entry form for you to check and save.',
            ],
            '02-content-plan' => ['plan', 'Keeps a plan of what the site is missing', 'A running list of the entries worth writing next, drawn from what is already there.'],
            '03-image-choices' => ['image-find', 'Pictures that look like yours', 'Finds or makes images to match the site\'s own image style guide.'],
            '04-voice-guide' => ['voice-guide', 'Learns how the site already writes', 'A tone of voice guide built from your own entries, and editable like any other.'],
        ];
    }
}
