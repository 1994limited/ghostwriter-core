// A small DOM for the locator's tests: enough of Node and Element for
// what locator.js uses, built from an HTML string. No install needed.

const VOID = new Set(['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr']);
const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'", nbsp: ' ' };

class Node {
    constructor(nodeType, ownerDocument) {
        this.nodeType = nodeType;
        this.ownerDocument = ownerDocument;
        this.parentNode = null;
        this.childNodes = [];
    }

    get previousSibling() {
        const siblings = this.parentNode?.childNodes ?? [];

        return siblings[siblings.indexOf(this) - 1] ?? null;
    }

    get nextSibling() {
        const siblings = this.parentNode?.childNodes ?? [];

        return siblings[siblings.indexOf(this) + 1] ?? null;
    }

    appendChild(node) {
        node.parentNode = this;
        this.childNodes.push(node);

        return node;
    }

    get textContent() {
        return this.nodeType === 3 ? this.nodeValue : this.childNodes.map((child) => child.textContent).join('');
    }
}

class Text extends Node {
    constructor(value, ownerDocument) {
        super(3, ownerDocument);
        this.nodeValue = value;
        this.nodeName = '#text';
    }
}

class Element extends Node {
    constructor(tag, attributes, ownerDocument) {
        super(1, ownerDocument);
        this.tagName = tag.toUpperCase();
        this.nodeName = this.tagName;
        this.attributes = new Map(attributes);
    }

    getAttribute(name) {
        return this.attributes.has(name) ? this.attributes.get(name) : null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    /** `data-rect="left,top,width,height"` stands in for layout. */
    getBoundingClientRect() {
        const [left, top, width, height] = (this.getAttribute('data-rect') ?? '0,0,0,0').split(',').map(Number);

        return { left, top, width, height, right: left + width, bottom: top + height };
    }

    querySelector(selector) {
        let found = null;
        const tag = selector.toUpperCase();
        const walk = (node) => {
            for (const child of node.childNodes) {
                if (!found && child.nodeType === 1) {
                    if (child.tagName === tag || child.getAttribute('id') === selector.replace(/^#/, '')) {
                        found = child;
                    }

                    walk(child);
                }
            }
        };
        walk(this);

        return found;
    }

    /** The page's markup, for checks: elements and text, attributes in order. */
    get outerHTML() {
        const attributes = [...this.attributes].map(([name, value]) => ` ${name}="${value}"`).join('');
        const inner = this.childNodes.map((child) => (child.nodeType === 3 ? child.nodeValue : child.outerHTML)).join('');

        return VOID.has(this.tagName.toLowerCase()) ? `<${this.tagName.toLowerCase()}${attributes}>` : `<${this.tagName.toLowerCase()}${attributes}>${inner}</${this.tagName.toLowerCase()}>`;
    }
}

function decode(text) {
    return text.replace(/&(#?\w+);/g, (whole, name) => ENTITIES[name] ?? whole);
}

/** A document from HTML. Only <body>'s inside is parsed when there's no <html>. */
export function parse(html) {
    const doc = { nodeType: 9 };
    const root = new Element('html', [], doc);
    const head = root.appendChild(new Element('head', [], doc));
    const body = root.appendChild(new Element('body', [], doc));
    doc.documentElement = root;
    doc.head = head;
    doc.body = body;
    doc.defaultView = { scrollX: 0, scrollY: 0 };

    const stack = [body];
    const tokens = html.matchAll(/<!--[\s\S]*?-->|<\/?([a-zA-Z][\w-]*)((?:\s+[^\s=>\/]+(?:\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]+))?)*)\s*\/?>|[^<]+/g);

    for (const token of tokens) {
        const [whole, tag, rest = ''] = token;
        const top = stack[stack.length - 1];

        if (whole.startsWith('<!--')) {
            continue;
        }

        if (!tag) {
            top.appendChild(new Text(decode(whole), doc));
            continue;
        }

        if (whole.startsWith('</')) {
            const at = stack.map((element) => element.tagName).lastIndexOf(tag.toUpperCase());

            if (at > 0) {
                stack.length = at;
            }

            continue;
        }

        if (['html', 'body', 'head'].includes(tag.toLowerCase())) {
            continue;
        }

        const attributes = [...rest.matchAll(/([^\s=>\/]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g)].map((m) => [m[1], decode(m[2] ?? m[3] ?? m[4] ?? '')]);
        const element = top.appendChild(new Element(tag, attributes, doc));

        if (!VOID.has(tag.toLowerCase()) && !whole.endsWith('/>')) {
            stack.push(element);
        }
    }

    return doc;
}

/** Tag characters for a payload: what PreviewMarkers::encode() writes. */
export function marker(payload) {
    return `\u{E0067}\u{E0077}${Array.from(payload, (char) => String.fromCodePoint(0xe0000 + char.charCodeAt(0))).join('')}\u{E007F}`;
}

/** `{b1.0}` in a fixture becomes that marker. */
export function marked(html) {
    return html.replace(/\{([bfs]\d+(?:\.\d+)?)\}/g, (whole, payload) => marker(payload));
}
