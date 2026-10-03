// node --test tests/js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { LABELS, LINK_PREFIX, PATTERNS, chipRow, countByRegion, find, gapsIn, has, linkHint, markGaps, segments, toHtml, toPlainText } from '../../resources/js/preview/markers.js';
import { locate } from '../../resources/js/preview/locator.js';
import { marked, parse } from './dom.js';

const core = JSON.parse(readFileSync(new URL('../../resources/gaps/patterns.json', import.meta.url), 'utf8'));
const block = (key, extra = {}) => ({ key, kind: 'block', label: key, parent: null, units: [], fields: {}, assets: [], anchors: [], ...extra });
const html = (element) => element.outerHTML;

test('the patterns are core’s, exactly', () => {
    for (const name of ['ask', 'check', 'link', 'sentinel']) {
        assert.deepEqual(PATTERNS[name], core[name], `${name} differs from resources/gaps/patterns.json`);
    }

    assert.equal(LINK_PREFIX, core.linkPrefix);
});

test('asks and counts to check are found in order, leniently, as core finds them', () => {
    const text = 'Tickets are [[ check : 3 areas | from : Northumberland, Durham and the Tyne Valley ]] and [[ASK: adult ticket price]] each. [[ask: ]] stays.';

    assert.deepEqual(find(text).map(({ kind, hint, list }) => [kind, hint, list]), [
        ['check', '3 areas', 'Northumberland, Durham and the Tyne Valley'],
        ['ask', 'adult ticket price', undefined],
    ]);
    assert.deepEqual(find('No markers here.'), []);
    assert.equal(has('[Talk to us](#gw-link:contact-page)'), true);
    assert.equal(has('[[leftover]]'), false);
});

test('a string formats as pieces, plain text and escaped HTML', () => {
    const text = 'From [[ask: adult ticket price]] in [[check: 3 areas | from: A & B, C]]. [Talk to us](#gw-link:contact-page) <b>';

    assert.deepEqual(segments(text).map((piece) => [piece.kind, piece.text]), [
        ['text', 'From '],
        ['ask', 'adult ticket price'],
        ['text', ' in '],
        ['check', '3 areas'],
        ['text', '. '],
        ['link', 'Talk to us'],
        ['text', ' <b>'],
    ]);
    assert.equal(toPlainText(text), 'From adult ticket price in 3 areas. Talk to us <b>');
    assert.equal(
        toHtml(text),
        'From <span class="gw-gap gw-gap-ask" data-gw-gap-kind="ask" title="Only you know this: add it before publishing"><span class="gw-gap-sr">Fact to add: </span>adult ticket price</span>'
        + ' in <span class="gw-gap gw-gap-check" data-gw-gap-kind="check" title="Counted from &#39;A &amp; B, C&#39;. Check it before publishing"><span class="gw-gap-sr">Count to check: </span>3 areas</span>'
        + '. <span class="gw-gap gw-gap-link" data-gw-gap-kind="link" title="Link to choose"><span class="gw-gap-sr">(link to choose) </span>Talk to us</span> &lt;b&gt;',
    );
    assert.equal(toHtml('<script>[[ask: <img src=x onerror=alert(1)>]]</script>').includes('<img'), false, 'everything is escaped');
    assert.equal(toPlainText('Nothing to show'), 'Nothing to show');
});

test('a value’s gaps, one line each, for a row under a plain text input', () => {
    assert.deepEqual(gapsIn('[[ask: adult ticket price]] from [[check: 3 | from: a, b and c]]').map((gap) => [gap.kind, gap.text, gap.title]), [
        ['ask', 'Add: adult ticket price', LABELS.ask],
        ['check', 'Check: 3', 'Counted from \'a, b and c\'. Check it before publishing'],
    ]);
    assert.deepEqual(gapsIn('[book](#gw-link:booking-page)').map((gap) => gap.text), ['Choose a link: booking page']);
    assert.deepEqual(gapsIn('[[ask: x]]', { labels: { askRow: 'À ajouter : :hint' } }).map((gap) => gap.text), ['À ajouter : x']);

    const doc = parse('<div></div>');
    const row = chipRow(doc, 'Adults [[ask: adult ticket price]]');

    assert.equal(html(row), '<div class="gw-gap-row" data-gw-gap-row=""><span class="gw-gap gw-gap-ask" data-gw-gap-kind="ask" title="Only you know this: add it before publishing">Add: adult ticket price</span></div>');
    assert.equal(chipRow(doc, 'Nothing'), null);
});

test('link hints decode', () => {
    assert.equal(linkHint('https://example.com/#gw-link:contact-page'), 'contact page');
    assert.equal(linkHint('#gw-link:caf%C3%A9_menu'), 'café menu');
    assert.equal(linkHint('/contact'), null);
});

test('in a document, markers become chips and links to choose are marked, once', () => {
    const doc = parse(`
        <main>
            <p id="p">Adults pay [[ask: adult ticket price]], children [[ask: child price]].</p>
            <div id="stat"><strong>[[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]]</strong><span>covered</span></div>
            <p><a id="a" href="https://example.com/#gw-link:contact-page" class="btn">Talk to us</a> or <a id="b" href="/about">read more</a></p>
            <script>var x = "[[ask: never]]";</script>
            <textarea>[[ask: never either]]</textarea>
        </main>`);
    const chips = markGaps(doc);

    assert.deepEqual(chips.map((found) => [found.kind, found.hint]), [
        ['ask', 'adult ticket price'],
        ['ask', 'child price'],
        ['check', '3 areas'],
        ['link', 'contact page'],
    ]);
    assert.equal(chips[2].list, 'Northumberland, Durham and the Tyne Valley');

    const p = doc.body.querySelector('#p');
    assert.equal(p.textContent, 'Adults pay Fact to add: adult ticket price, children Fact to add: child price.');
    assert.equal(p.childNodes.length, 5);
    assert.equal(chips[0].element.getAttribute('title'), 'Only you know this: add it before publishing');
    assert.equal(chips[2].element.getAttribute('title'), 'Counted from \'Northumberland, Durham and the Tyne Valley\'. Check it before publishing');
    assert.equal(doc.body.querySelector('strong').textContent, 'Count to check: 3 areas');

    const link = doc.body.querySelector('#a');
    assert.equal(link.getAttribute('class'), 'btn gw-gap gw-gap-link');
    assert.equal(link.getAttribute('title'), 'Link to choose');
    assert.equal(link.textContent, 'Talk to us (link to choose)');
    assert.equal(doc.body.querySelector('#b').getAttribute('class'), null);

    assert.match(doc.body.querySelector('script').textContent, /\[\[ask: never\]\]/);
    assert.match(doc.body.querySelector('textarea').textContent, /\[\[ask: never either\]\]/);

    // The styles are in the frame's head, once.
    assert.equal(doc.head.childNodes.filter((node) => node.getAttribute?.('id') === 'gw-gap-styles').length, 1);

    // Again: nothing changes.
    const before = html(doc.documentElement);
    assert.equal(markGaps(doc).length, 4);
    assert.equal(html(doc.documentElement), before);
});

test('labels are the addon’s translations', () => {
    const doc = parse('<p>[[ask: prix]] [[check: 3 zones | from: a, b, c]]</p>');
    const chips = markGaps(doc, { labels: { ask: 'Vous seul le savez', askSpoken: 'À ajouter :', check: 'Compté depuis « :list »' } });

    assert.equal(chips[0].element.getAttribute('title'), 'Vous seul le savez');
    assert.equal(chips[0].element.textContent, 'À ajouter : prix');
    assert.equal(chips[1].element.getAttribute('title'), 'Compté depuis « a, b, c »');
});

test('with onActivate, chips are buttons: click, Enter and Space call it', () => {
    const doc = parse('<p>[[ask: adult ticket price]] <a href="#gw-link:contact">Contact</a></p>');
    const seen = [];
    const chips = markGaps(doc, { onActivate: (found) => seen.push(found.hint) });
    const [ask, link] = chips.map((found) => found.element);

    assert.equal(ask.getAttribute('role'), 'button');
    assert.equal(ask.getAttribute('tabindex'), '0');
    assert.equal(link.getAttribute('role'), null, 'a link stays a link');

    assert.equal(ask.dispatch({ type: 'click' }).prevented, true);
    ask.dispatch({ type: 'keydown', key: 'Enter' });
    ask.dispatch({ type: 'keydown', key: ' ' });
    ask.dispatch({ type: 'keydown', key: 'a' });
    link.dispatch({ type: 'click' });
    assert.deepEqual(seen, ['adult ticket price', 'adult ticket price', 'adult ticket price', 'contact']);

    // Marked again: no second listener.
    markGaps(doc, { onActivate: (found) => seen.push(found.hint) });
    ask.dispatch({ type: 'click' });
    assert.equal(seen.length, 5);
});

test('after the locator, chips are counted in the right block regions', () => {
    const doc = parse(marked(`
        <header><p>Site</p></header>
        <main>
            <section id="hero"><h1>Winter visits{b1.0}</h1><p>From [[ask: price per visit]]{b1.1}</p></section>
            <section id="stats"><div id="s1"><b>[[check: 3 areas | from: A, B and C]]{b2.0}</b></div><div id="s2"><b>[[ask: years trading]]{b3.0}</b></div></section>
            <section id="cta"><a href="#gw-link:contact">Talk to us{b4.0}</a></section>
        </main>`));
    const map = [block('b1'), block('b2'), block('b3'), block('b4')];
    const result = locate(doc, map);
    const chips = markGaps(doc);

    assert.equal(chips.length, 4);
    assert.deepEqual(countByRegion(result.regions, chips), { b1: 1, b2: 1, b3: 1, b4: 1 });
    assert.equal(doc.body.querySelector('h1').textContent, 'Winter visits', 'the locator stripped its markers first');
});
