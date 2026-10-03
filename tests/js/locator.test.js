// node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { canRead, contentArea, decodePayload, findMarkers, locate, measure, parsePayload, stripText, watch, words } from '../../resources/js/preview/locator.js';
import { marked, marker, parse } from './dom.js';

const name = (element) => element.getAttribute?.('id') ?? element.tagName.toLowerCase();
const names = (result, key) => result.byKey[key]?.elements.map(name);
const block = (key, extra = {}) => ({ key, kind: key.startsWith('s') ? 'section' : key.startsWith('f') ? 'field' : 'block', label: key, parent: null, units: [], fields: {}, assets: [], anchors: [], ...extra });

test('a payload decodes from tag characters, and parses into a key and a field', () => {
    const tags = Array.from(marker('b7.2')).slice(2, -1).join('');

    assert.equal(decodePayload(tags), 'b7.2');
    assert.deepEqual(parsePayload('b7.2'), { key: 'b7', field: 2 });
    assert.deepEqual(parsePayload('s3'), { key: 's3', field: null });
    assert.equal(parsePayload('x 1'), null);
    assert.equal(stripText(`<p>${marker('f1')}Hi</p>`), '<p>Hi</p>');
    assert.deepEqual(words('**Don’t** need <em>it</em>: 4 visits'), ['don', 't', 'need', 'em', 'it', 'em', '4', 'visits']);
});

test('markers are found in text and attributes, then stripped from the whole document', () => {
    const flag = '\u{1F3F4}\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}';
    const doc = parse(marked(`<main><h1 id="h">{b1.0}Winter <em>care</em></h1><img id="i" src="/a.jpg" alt="{b1.1}A garden"><p id="flag">${flag} Scotland</p><script>var x = "{b9.0}";</script></main>`));
    const { marks, removed } = findMarkers(doc);

    assert.deepEqual(marks.map((m) => [m.payload, m.key, m.field, name(m.element), m.attribute]), [
        ['b1.0', 'b1', 0, 'h', null],
        ['b1.1', 'b1', 1, 'i', 'alt'],
    ]);
    assert.equal(removed, 2);
    assert.equal(doc.body.querySelector('h1').textContent, 'Winter care');
    assert.equal(doc.body.querySelector('img').getAttribute('alt'), 'A garden');
    assert.equal(doc.body.querySelector('#flag').textContent, `${flag} Scotland`, 'a subdivision flag is left alone');
    assert.match(doc.body.querySelector('script').textContent, /\u{E0067}/u, 'scripts are not touched');
    assert.equal(findMarkers(doc).marks.length, 0);
});

test('wrapped blocks: each block is its wrapper; sections are runs inside it', () => {
    const doc = parse(marked(`
        <header id="site"><nav>Home</nav><p>{f1}Winter care</p></header>
        <main id="main">
            <section id="hero"><h1>{b1.0}Winter care visits</h1><img id="img" src="/img/asset/abc/garden.jpg?w=800" alt="{b1.1}A winter garden"></section>
            <section id="text"><div id="prose">
                <p id="lead">{b2.0}{s1}Winter is when a garden is set up.</p>
                <h2 id="what">{s2}What the visits are</h2><p id="what-p">Prune.</p><h3 id="detail">In detail</h3><p id="detail-p">Wrap.</p>
                <h2 id="who">{s3}Who it suits</h2><p id="who-p">Gardens.</p><ul id="who-ul"><li>Lawns</li></ul>
            </div></section>
            <section id="cta"><h2>{b3.0}Book a visit</h2><a href="/contact">{b3.1}Talk to us</a></section>
        </main>
        <footer id="foot">© Northfold</footer>`));
    const map = [block('f1'), block('b1'), block('b2'), block('s1', { parent: 'b2' }), block('s2', { parent: 'b2' }), block('s3', { parent: 'b2' }), block('b3')];
    const result = locate(doc, map);

    assert.deepEqual(names(result, 'f1'), ['site']);
    assert.deepEqual(names(result, 'b1'), ['hero']);
    assert.deepEqual(names(result, 'b2'), ['text']);
    assert.deepEqual(names(result, 'b3'), ['cta']);
    assert.deepEqual(names(result, 's1'), ['lead']);
    assert.deepEqual(names(result, 's2'), ['what', 'what-p', 'detail', 'detail-p']);
    assert.deepEqual(names(result, 's3'), ['who', 'who-p', 'who-ul']);
    assert.deepEqual(Object.keys(result.byKey.b1.fields), ['0', '1']);
    assert.deepEqual(result.byKey.b1.fields[1].map(name), ['img'], 'the alt text names its image');
    assert.equal(result.byKey.b1.method, 'marker');
    assert.deepEqual(result.regions.map((region) => region.key), ['f1', 'b1', 'b2', 's1', 's2', 's3', 'b3'], 'in map order');
    assert.deepEqual(result.missing, []);
    assert.equal(result.partial, false);
    assert.equal(name(result.content), 'main');
});

test('an unwrapped rich-text block is a run of the shared container, and a run never takes the footer', () => {
    // The Craft test template's `{% case 'text' %}{{ block.text }}` prints no wrapper.
    const doc = parse(marked(`
        <main id="main">
            <section id="hero"><h1>{b1.0}Winter care</h1></section>
            <p id="lead">{b2.0}{s1}Winter is when a garden is set up.</p>
            <h2 id="what">{s2}What the visits are</h2>
            <p id="what-p">Prune the shrubs.</p>
            <h2 id="who">{s3}Who it suits</h2>
            <p id="who-p">Gardens with borders.</p>
            <section id="cta"><h2>{b3.0}Book a visit</h2></section>
        </main>
        <p id="last">{b4.0}A closing line printed straight into the body.</p>
        <p id="last-2">And a second paragraph of it.</p>
        <footer id="foot"><p>© Northfold</p></footer>`));
    const map = [block('b1'), block('b2'), block('s1', { parent: 'b2' }), block('s2', { parent: 'b2' }), block('s3', { parent: 'b2' }), block('b3'), block('b4')];
    const result = locate(doc, map);

    assert.deepEqual(names(result, 'b2'), ['lead', 'what', 'what-p', 'who', 'who-p']);
    assert.deepEqual(names(result, 's1'), ['lead']);
    assert.deepEqual(names(result, 's2'), ['what', 'what-p']);
    assert.deepEqual(names(result, 's3'), ['who', 'who-p']);
    assert.deepEqual(names(result, 'b3'), ['cta']);
    assert.deepEqual(names(result, 'b4'), ['last', 'last-2'], 'the footer is not part of the last run');
});

test('nested blocks are found inside their parent', () => {
    const doc = parse(marked(`
        <main>
            <section id="grid"><h2>{b1.0}Visits</h2><div id="cards">
                <article id="c1"><h3>{b2.0}November</h3><p>{b2.1}Cut back.</p></article>
                <article id="c2"><h3>{b3.0}February</h3><p>{b3.1}Feed.</p></article>
            </div></section>
            <section id="after"><p>{b4.0}After the grid.</p></section>
        </main>`));
    const result = locate(doc, [block('b1'), block('b2', { parent: 'b1' }), block('b3', { parent: 'b1' }), block('b4')]);

    assert.deepEqual(names(result, 'b1'), ['grid']);
    assert.deepEqual(names(result, 'b2'), ['c1']);
    assert.deepEqual(names(result, 'b3'), ['c2']);
    assert.deepEqual(names(result, 'b4'), ['after']);
});

test('image-only blocks are found by their files, and a block with nothing is given the gap between its neighbours', () => {
    const doc = parse(marked(`
        <main>
            <section id="one"><p>{b1.0}One block here.</p></section>
            <figure id="photo"><picture><source srcset="/img/containers/assets/garden.jpg/abc123.webp 1x, /x.webp 2x"><img src="/img/x.jpg" alt=""></picture></figure>
            <div id="divider" class="divider"></div>
            <section id="two"><p>{b4.0}Two.</p></section>
            <div id="bg" style="background-image: url('/uploads/_800x600_crop/hero%20image.webp')"></div>
            <section id="three"><p>{b6.0}Three.</p></section>
        </main>`));
    const map = [
        block('b1'),
        block('b2', { assets: ['garden.jpg'] }),
        block('b3'),
        block('b4'),
        block('b5', { assets: ['hero image.jpg'] }),
        block('b6'),
        block('b7', { assets: ['not-there.jpg'] }),
    ];
    const result = locate(doc, map);

    assert.deepEqual(names(result, 'b2'), ['photo']);
    assert.equal(result.byKey.b2.method, 'asset');
    assert.deepEqual(names(result, 'b3'), ['divider']);
    assert.equal(result.byKey.b3.method, 'gap');
    assert.deepEqual(names(result, 'b5'), ['bg'], 'a transform URL with another format still names the file');
    assert.deepEqual(result.missing, ['b7'], 'not on the page');
});

test('a block whose markers were lost is found by its text anchors, when they match once', () => {
    const doc = parse(marked(`
        <main>
            <section id="hero"><h1>{b1.0}Winter care</h1></section>
            <section id="shout"><h2>WHO IT SUITS</h2><p>Gardens with mixed borders and young trees.</p></section>
            <section id="twice-a"><p>Book a visit today</p></section>
            <section id="twice-b"><p>Book a visit today</p></section>
        </main>`));
    const result = locate(doc, [
        block('b1'),
        block('b2', { anchors: ['who it suits', 'gardens with mixed borders and young trees'] }),
        block('b3', { anchors: ['book a visit today'] }),
    ]);

    assert.deepEqual(names(result, 'b2'), ['shout']);
    assert.equal(result.byKey.b2.method, 'anchor');
    assert.equal(result.byKey.b3, undefined, 'an anchor found twice says nothing');
    assert.deepEqual(result.missing, ['b3']);
});

test('fewer than half the blocks located is a partial match, with the content area to fall back on', () => {
    const doc = parse(marked('<article id="post"><p>{b1.0}Only this one.</p></article>'));
    const result = locate(doc, [block('b1'), block('b2'), block('b3')]);

    assert.equal(result.partial, true);
    assert.deepEqual(result.missing, ['b2', 'b3']);
    assert.equal(name(result.content), 'post');
    assert.equal(name(contentArea(parse('<p>x</p>'))), 'body');
});

test('marks found earlier can be passed back in, after the page was stripped', () => {
    const doc = parse(marked('<main><section id="a"><p>{b1.0}A</p></section><section id="b"><p>{b2.0}B</p></section></main>'));
    const { marks } = findMarkers(doc);
    const result = locate(doc, [block('b1'), block('b2')], { marks });

    assert.deepEqual(names(result, 'b2'), ['b']);
});

test('a region is measured in document coordinates', () => {
    const doc = parse('<p id="a" data-rect="10,20,100,30"></p><p id="b" data-rect="0,60,50,10"></p><p id="c" data-rect="5,5,0,0"></p>');
    const elements = ['a', 'b', 'c'].map((id) => doc.body.querySelector(`#${id}`));

    assert.deepEqual(measure({ elements }, { scrollX: 0, scrollY: 200 }), { left: 0, top: 220, width: 110, height: 50 });
    assert.equal(measure({ elements: [elements[2]] }, {}), null, 'nothing with a size');
});

test('a frame that can’t be read, or loaded nothing, is refused', () => {
    assert.equal(canRead({ contentDocument: parse('<p>x</p>') }), true);
    assert.equal(canRead({ contentDocument: parse('') }), false);
    assert.equal(canRead({ get contentDocument() { throw new Error('cross-origin'); } }), false);
    assert.equal(canRead(null), false);
});

test('watching without a MutationObserver does nothing, safely', () => {
    const handle = watch(parse('<p>x</p>'), () => assert.fail('no observer, no calls'));

    handle.stop();
});

test('watching strips markers that scripts add later', async () => {
    const doc = parse('<main><p id="a">Static</p></main>');
    let callback = null;
    doc.defaultView = {
        MutationObserver: class {
            constructor(fn) { callback = fn; }
            observe() {}
            disconnect() {}
        },
        requestAnimationFrame: (fn) => fn(),
    };
    const seen = [];
    watch(doc, (marks) => seen.push(...marks.map((m) => m.payload)));

    const p = doc.body.querySelector('#a');
    p.childNodes[0].nodeValue = marked('{b2.0}Rendered by a carousel');
    callback([]);

    assert.deepEqual(seen, ['b2.0']);
    assert.equal(p.textContent, 'Rendered by a carousel');
});

test('the page core marks is located whole (tests/Fixtures/preview/page.json, written by PHP)', async () => {
    const { readFile } = await import('node:fs/promises');
    const fixture = JSON.parse(await readFile(new URL('../Fixtures/preview/page.json', import.meta.url), 'utf8'));
    const doc = parse(fixture.html);
    const result = locate(doc, fixture.map);
    const tags = (key) => result.byKey[key]?.elements.map((element) => element.getAttribute('id') ?? element.tagName.toLowerCase());

    assert.deepEqual(result.missing, []);
    assert.equal(result.partial, false);
    assert.ok(result.regions.every((region) => region.method === 'marker'));
    assert.deepEqual(tags('f1'), ['title']);
    assert.deepEqual(tags('f2'), ['intro']);
    assert.deepEqual(tags('b1'), ['block-0']);
    assert.deepEqual(tags('b2'), ['p', 'h2', 'p', 'h3', 'p', 'h2', 'p', 'ul'], 'the unwrapped rich text is a run');
    assert.deepEqual(tags('s1'), ['p']);
    assert.deepEqual(tags('s2'), ['h2', 'p', 'h3', 'p']);
    assert.deepEqual(tags('s3'), ['h2', 'p', 'ul']);
    assert.deepEqual(tags('b3'), ['block-2']);
    assert.deepEqual(tags('b4'), ['block-3']);
    assert.deepEqual(tags('f3'), ['notes']);
    assert.ok(!/[\u{E0000}-\u{E007F}]/u.test(doc.body.textContent), 'every marker is gone from the page');
    assert.deepEqual(result.byKey.s2.parent, 'b2');
});
