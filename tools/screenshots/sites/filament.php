<?php

/**
 * The Filament test app (Laravel + Filament, SQLite), with the plugin's
 * cluster at /admin/ghostwriter.
 */
class FilamentSite extends Site
{
    private const DATABASE = 'database/database.sqlite';

    public function name(): string
    {
        return 'Filament';
    }

    public function state(): State
    {
        return (new State($this->root, 'filament'))
            ->files(['.env', 'config/ghostwriter.php', 'storage/app/public', 'storage/app/private'])
            ->sqlite(self::DATABASE);
    }

    public function seed(): array
    {
        return $this->seeder('filament.php', [$this->url]);
    }

    public function signIn(Browser $browser): void
    {
        $email = getenv('GW_SHOT_EMAIL') ?: $this->testing('Email');
        $password = getenv('GW_SHOT_PASSWORD') ?: $this->testing('Password');

        $browser->go($this->absolute('/admin/login'));
        $browser->waitForSelector('input[type=email]');
        $browser->fill('input[type=email]', $email);
        $browser->fill('input[type=password]', $password);
        $browser->clickSelector('form button[type=submit]');
        $browser->until('!location.pathname.endsWith("/login")', 20, 'signing in');
        $browser->waitForSelector('.fi-sidebar, .fi-topbar');
    }

    /**
     * Filament follows the system's light or dark unless a theme was
     * chosen; make sure the page matches the shot.
     */
    public function theme(Browser $browser, string $scheme): void
    {
        $dark = $scheme === 'dark';

        if ((bool) $browser->js('document.documentElement.classList.contains("dark")') === $dark) {
            return;
        }

        $browser->js('localStorage.setItem("theme", __T__)', ['__T__' => $scheme]);
        $browser->go((string) $browser->js('location.href'));
        $browser->until(($dark ? '' : '!').'document.documentElement.classList.contains("dark")', 10, "the {$scheme} theme");
    }

    public function shots(): array
    {
        $panel = fn (string $resource, string $key) => fn (array $c) => "/admin/{$resource}/create?ghostwriter=".$c[$key];
        $openPanel = function (Browser $b): void {
            $b->waitForSelector('.fi-modal-window .gw-panel');
            $b->settle(0.5);
        };

        return [
            'get-started-connect' => [
                'url' => '/admin/ghostwriter/get-started?step=key',
                'ready' => 'Writing with Claude',
            ],
            'get-started-kinds' => [
                'url' => '/admin/ghostwriter/get-started?step=kinds',
                'steps' => function (Browser $b): void {
                    $b->waitForText('Learn this');
                    $b->js('window.scrollTo(0, document.querySelector(".fi-sc-wizard-header").getBoundingClientRect().top + scrollY - 96)');
                },
            ],
            'overview' => [
                'url' => '/admin/ghostwriter/overview',
                'ready' => 'Carry on',
            ],
            'overview-dark' => [
                'url' => '/admin/ghostwriter/overview',
                'ready' => 'Carry on',
                'scheme' => 'dark',
            ],
            'widget' => [
                'url' => '/admin',
                'steps' => function (Browser $b): void {
                    $b->waitForSelector('.gw-widget');
                    $this->mark($b, 'document.querySelector(".gw-widget").closest(".fi-section")');
                },
                'clip' => '#gw-shot',
                'pad' => 20,
            ],
            'cluster-nav' => [
                'url' => '/admin/ghostwriter/overview',
                'steps' => function (Browser $b): void {
                    $b->waitForSelector('.fi-page-sub-navigation-tabs, .fi-page-sub-navigation, nav.fi-tabs');
                    $this->markArea($b, '(document.querySelector(".fi-page-sub-navigation-tabs") || document.querySelector("nav.fi-tabs")).getBoundingClientRect().bottom + 20');
                },
                'clip' => '#gw-shot',
            ],
            'settings' => [
                'url' => '/admin/ghostwriter/settings',
                'ready' => 'Ghostwriter settings',
            ],
            'settings-locked' => [
                'url' => '/admin/ghostwriter/settings',
                'steps' => function (Browser $b): void {
                    $b->waitForText('Set in config/ghostwriter.php.');
                    $this->mark($b, '[...document.querySelectorAll(".fi-fo-field, .fi-fo-field-wrp")].filter(e => e.innerText.includes("Suggest kinds of content automatically")).pop()');
                },
                'clip' => '#gw-shot',
                'pad' => 20,
            ],
            'voice-guide' => [
                'url' => '/admin/ghostwriter/voice',
                'ready' => 'Rescan and rewrite',
            ],
            'image-style' => [
                'url' => '/admin/ghostwriter/image-style',
                'ready' => 'Look again and rewrite',
            ],
            'kinds-suggested' => [
                'url' => '/admin/ghostwriter/overview',
                'steps' => function (Browser $b): void {
                    $b->waitForText('Learn all 2');
                    $this->mark($b, '[...document.querySelectorAll(".fi-section")].find(e => e.innerText.includes("Learn all 2"))');
                },
                'clip' => '#gw-shot',
                'pad' => 20,
            ],
            'kind-editor' => [
                'url' => '/admin/ghostwriter/kind?kind=project-story',
                'ready' => 'Which garden is it',
            ],
            'teach-kind' => [
                'url' => '/admin/ghostwriter/teach?resource=posts',
                'ready' => 'css:form',
            ],
            'writing-choose' => [
                'url' => '/admin/posts/create?ghostwriter=new',
                'steps' => $openPanel,
                'ready' => 'Or carry on with',
            ],
            'writing-brief' => [
                'url' => '/admin/posts/create?ghostwriter=new',
                'height' => 1700,
                'steps' => function (Browser $b) use ($openPanel): void {
                    $openPanel($b);
                    $b->click('Something else', '.fi-modal-window button');
                    $b->waitForText('Quick brief');
                    $b->clickSelector('.fi-modal-window input[type=checkbox][value="9"]');
                    $b->clickSelector('.fi-modal-window input[type=checkbox][value="12"]');
                    $b->waitForText('2 of 6 ticked');
                    $b->fill('.fi-modal-window input[wire\\:model="quickTitle"]', 'Making a wildlife hedge from scratch');
                    $b->fill('.fi-modal-window textarea[wire\\:model="quickNotes"]', 'For clients planting a new boundary this winter. Native species only.');
                    $b->fill('.fi-modal-window textarea[wire\\:model="answers.subject"]', 'How to plant a native hedge for birds and insects, from choosing the plants to the first three years of cutting.');
                    $b->fill('.fi-modal-window textarea[wire\\:model="answers.reader"]', 'Clients with a new garden boundary to plant. They should be ready to order bare-root hedging in November.');
                    $b->fill('.fi-modal-window textarea[wire\\:model="answers.points"]', "Hawthorn, blackthorn, field maple, hazel and dog rose.\nPlant a double staggered row, five plants to the metre.\nCut hard in the first winter so it thickens from the base.");
                    $b->fill('.fi-modal-window input[wire\\:model="answers.shape"]', 'Like our other seasonal advice posts.');
                },
                'clip' => '.fi-modal-window',
                'pad' => 0,
            ],
            'writing-questions' => [
                'url' => $panel('posts', 'questions'),
                'steps' => $openPanel,
                'ready' => 'Your turn: answer above to carry on.',
            ],
            'writing-draft' => [
                'url' => $panel('pages', 'draft'),
                'steps' => function (Browser $b) use ($openPanel): void {
                    $openPanel($b);
                    $b->waitForText('Use this draft');
                    // One piece being changed in place: focused, and kept so through the settle.
                    // Headless Chrome has no window focus, so :focus needs emulating.
                    $b->ws->send('Emulation.setFocusEmulationEnabled', ['enabled' => true]);
                    $b->js('(() => { const el = document.querySelector(".fi-modal-window .gw-editable.gw-prose"); const block = el.closest(".gw-block"); let box = block.parentElement; while (box && !(box.scrollHeight > box.clientHeight && ["auto", "scroll"].includes(getComputedStyle(box).overflowY))) box = box.parentElement; if (box) box.scrollTop += block.getBoundingClientRect().top - box.getBoundingClientRect().top - 72; el.dataset.gwKeepFocus = "1"; el.focus(); })()');
                },
            ],
            'writing-draft-dark' => [
                'url' => $panel('pages', 'draft'),
                'scheme' => 'dark',
                'steps' => $openPanel,
                'ready' => 'Use this draft',
            ],
            'writing-shared' => [
                'url' => $panel('posts', 'shared'),
                'steps' => $openPanel,
                'ready' => 'last changed by you',
            ],
            'writing-failed' => [
                'url' => $panel('posts', 'failed'),
                'steps' => $openPanel,
                'ready' => 'Try again',
                'clip' => '.fi-modal-window .gw-convo',
                'pad' => 20,
            ],
            'editing' => [
                'url' => fn (array $c) => "/admin/pages/{$c['about']}/edit?ghostwriter=1",
                'steps' => $openPanel,
                'ready' => 'Use these changes',
            ],
            'image-button' => [
                'url' => fn (array $c) => "/admin/posts/{$c['post']}/edit",
                'steps' => function (Browser $b): void {
                    $b->waitForText('Hero image');
                    $this->mark($b, '[...document.querySelectorAll(".fi-fo-field, .fi-fo-field-wrp")].filter(e => e.innerText.includes("Hero image")).pop()');
                },
                'clip' => '#gw-shot',
                'pad' => 20,
            ],
            'image-find' => [
                'url' => fn (array $c) => "/admin/posts/{$c['post']}/edit",
                'steps' => fn (Browser $b) => $this->openImage($b, 'find', $this->context['find'], 'Searched for'),
                'clip' => '.fi-modal-window',
                'pad' => 0,
            ],
            'image-make' => [
                'url' => fn (array $c) => "/admin/posts/{$c['post']}/edit",
                'steps' => fn (Browser $b) => $this->openImage($b, 'make', $this->context['make'], 'Make another'),
                'clip' => '.fi-modal-window',
                'pad' => 0,
            ],
            'plan' => [
                'url' => '/admin/ghostwriter/plan',
                'steps' => fn (Browser $b) => $this->closeReview($b),
                'ready' => 'Back to ideas',
            ],
            'plan-waiting' => [
                'url' => '/admin/ghostwriter/plan',
                'steps' => fn (Browser $b) => $this->closeReview($b),
                'clip' => '.gw-waiting-card',
                'pad' => 20,
            ],
            'plan-suggestions' => [
                'url' => '/admin/ghostwriter/plan',
                'steps' => function (Browser $b): void {
                    $b->waitForText('Add 3 to the plan');
                    $b->settle(0.5);
                },
                'clip' => '.fi-modal-window',
                'pad' => 0,
            ],
            // Last: it puts the draft into the form for real (no model call).
            'writing-used' => [
                'url' => $panel('pages', 'draft'),
                'steps' => function (Browser $b) use ($openPanel): void {
                    $openPanel($b);
                    $b->click('Use this draft', '.fi-modal-window button');
                    $b->waitForText('Draft added to the form');
                    $this->unuse($this->context['draft']);
                },
                'ready' => 'Draft added to the form',
            ],
        ];
    }

    public function store(): array
    {
        $shots = $this->shots();

        return [
            '01-writing-panel' => [$shots['writing-draft-dark'], 'Drafts in your voice, beside the form', 'Start from a short brief. The draft lands in the normal record form for you to check and save.'],
            '02-content-plan' => ['plan', 'Keeps a plan of what the app is missing', 'A running list of the records worth writing next, drawn from what is already there.'],
            '03-image-choices' => ['image-find', 'Pictures that look like yours', 'Finds or makes images to match the app’s own image style guide.'],
            '04-voice-guide' => ['voice-guide', 'Learns how the app already writes', 'A tone of voice guide built from your own records, and editable like any other.'],
        ];
    }

    /**
     * Give the element an expression finds the id the shot crops to.
     */
    private function mark(Browser $browser, string $expression): void
    {
        $browser->js('(() => { document.getElementById("gw-shot")?.removeAttribute("id"); const el = '.$expression.'; if (el) el.id = "gw-shot"; })()');
        $browser->waitForSelector('#gw-shot', 5);
    }

    /**
     * A box over the top of the page, down to a height an expression gives.
     */
    private function markArea(Browser $browser, string $height): void
    {
        $browser->js('(() => { const box = document.createElement("div"); box.id = "gw-shot"; box.style.cssText = "position:absolute;left:0;top:0;width:100%;pointer-events:none;"; box.style.height = Math.ceil('.$height.') + "px"; document.body.appendChild(box); })()');
    }

    /**
     * The image button's dialog on the hero image, showing a seeded request:
     * set on the panel's component, so nothing is searched for or made.
     */
    private function openImage(Browser $browser, string $tab, string $request, string $ready): void
    {
        $browser->waitForText('Hero image');
        $browser->js('(() => { const field = [...document.querySelectorAll(".fi-fo-field, .fi-fo-field-wrp")].filter(e => e.innerText.includes("Hero image")).pop(); [...field.querySelectorAll("button, a")].find(e => e.innerText.trim() === "Ghostwriter").click(); })()');
        $browser->waitForSelector('.fi-modal-window .gw-panel');
        $browser->js('(async () => { const id = document.querySelector(".fi-modal-window .gw-panel").closest("[wire\\\\:id]").getAttribute("wire:id"); const c = Livewire.find(id); await c.set("tab", __TAB__); await c.set("request", __R__); })()', ['__TAB__' => $tab, '__R__' => $request]);
        $browser->waitForText($ready);
        $browser->until('[...document.querySelectorAll(".fi-modal-window .gw-photo img")].every(i => i.complete && i.naturalWidth > 0)', 15, 'the pictures');
    }

    /**
     * The plan opens its box of suggestions by itself; close it, leaving
     * them waiting.
     */
    private function closeReview(Browser $browser): void
    {
        $browser->waitForText('Add 3 to the plan');
        $browser->js('window.dispatchEvent(new CustomEvent("close-modal", {detail: {id: "ghostwriter-plan-review"}}))');
        $browser->until('![...document.querySelectorAll(".fi-modal-window")].some(e => e.offsetParent !== null && e.innerText.includes("Ghostwriter suggests"))', 10, 'the suggestions to close');
        $browser->waitForText('Review');
    }

    /**
     * Use this draft marks the piece as used; the shot is taken by then, so
     * put it back for the store image of the same draft.
     */
    private function unuse(string $ulid): void
    {
        $sql = "UPDATE ghostwriter_sessions SET applied_at = NULL WHERE ulid = '".preg_replace('/[^a-z0-9]/', '', $ulid)."'";
        exec('sqlite3 '.escapeshellarg($this->root.'/'.self::DATABASE).' '.escapeshellarg($sql).' 2>&1', $output, $status);

        if ($status !== 0) {
            throw new RuntimeException('Could not reset the used draft: '.implode("\n", $output));
        }
    }
}
