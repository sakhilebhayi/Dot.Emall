/*
 * Operator desk behaviours (R-43 W7). Vanilla, driven by data attributes, delegated, CSP-safe (no inline handlers).
 *   data-reason-template="<textarea id>"  on a <select>: choosing a template copies its text into the reason field to edit
 *   data-collection-preview="<url>"       on the collection form: previews what the rule matches as the rule is edited
 *   data-collection-find="<url>"          on the manual list: search listings by name and add them to the list
 */

const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));
const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

document.addEventListener('change', (e) => {
    const select = e.target.closest?.('select[data-reason-template]');
    if (!select) return;
    const field = document.getElementById(select.dataset.reasonTemplate);
    if (field && select.value) { field.value = select.value; field.focus(); }
});

function initPreview(form) {
    const url = form.dataset.collectionPreview;
    const out = form.querySelector('[data-preview-out]');
    if (!url || !out) return;
    let timer = null;
    let latest = 0;

    const render = (json) => {
        out.replaceChildren();
        const p = document.createElement('p');
        if (!json.ok) { p.textContent = json.message; out.append(p); return; }
        p.textContent = json.total === 0 ? 'This rule matches no listing a shopper can buy right now.' : 'Matches ' + json.total + (json.total === 1 ? ' listing' : ' listings') + ' right now' + (json.total > json.items.length ? ' (the newest ' + json.items.length + ' shown)' : '') + '.';
        out.append(p);
        if (json.items.length) {
            const ol = document.createElement('ol');
            json.items.forEach((i) => { const li = document.createElement('li'); li.textContent = i.name + ' · ' + (i.store || '') + ' · ' + i.price; ol.append(li); });
            out.append(ol);
        }
    };

    const run = async () => {
        const mine = ++latest;
        const typeDynamic = form.querySelector('input[name="type"]:checked')?.value === 'dynamic';
        if (!typeDynamic) return;
        out.setAttribute('aria-busy', 'true');
        const body = new FormData(form);
        try {
            const r = await fetch(url, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() }, body });
            const json = await r.json();
            if (mine === latest) render(json);
        } catch (err) {
            if (mine === latest) render({ ok: false, message: 'The preview could not be loaded. Saving still works.' });
        } finally {
            if (mine === latest) out.removeAttribute('aria-busy');
        }
    };

    const queue = () => { clearTimeout(timer); timer = setTimeout(run, 350); };
    form.addEventListener('input', (e) => { if (e.target.name && e.target.name.startsWith('rule')) queue(); });
    form.addEventListener('change', (e) => { if (e.target.name && (e.target.name.startsWith('rule') || e.target.name === 'type')) queue(); });
    run();
}

function initFind(box) {
    const url = box.dataset.collectionFind;
    const input = box.querySelector('input[type="search"]');
    const results = box.querySelector('[data-find-results]');
    const ids = document.getElementById(box.dataset.target);
    if (!url || !input || !results || !ids) return;
    let timer = null;

    const add = (id, name) => {
        const current = ids.value.split(/[\s,]+/).filter(Boolean);
        if (!current.includes(String(id))) current.push(String(id));
        ids.value = current.join('\n');
        results.replaceChildren();
        input.value = '';
        const note = document.createElement('p'); note.className = 'op-muted'; note.setAttribute('role', 'status'); note.textContent = 'Added ' + name + '.'; results.append(note);
    };

    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 2) { results.replaceChildren(); return; }
        timer = setTimeout(async () => {
            try {
                const r = await fetch(url + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
                const json = await r.json();
                const ul = document.createElement('ul'); ul.className = 'op-found';
                json.items.forEach((i) => {
                    const li = document.createElement('li');
                    const span = document.createElement('span'); span.textContent = i.name + ' · ' + (i.store || '') + ' · ' + i.price;
                    const b = document.createElement('button'); b.type = 'button'; b.className = 'ds-btn ds-btn--quiet ds-btn--sm'; b.textContent = 'Add'; b.setAttribute('aria-label', 'Add ' + i.name);
                    b.addEventListener('click', () => add(i.id, i.name));
                    li.append(span, b); ul.append(li);
                });
                results.replaceChildren(json.items.length ? ul : Object.assign(document.createElement('p'), { className: 'op-muted', textContent: 'No listing matches that.' }));
            } catch (err) { results.replaceChildren(); }
        }, 250);
    });
}

/* Collection form: show the hand-picked list or the rule, whichever is chosen. */
document.addEventListener('change', (e) => {
    if (e.target.name !== 'type') return;
    const form = e.target.closest('form');
    if (!form) return;
    form.querySelectorAll('[data-when-type]').forEach((s) => { s.hidden = s.dataset.whenType !== e.target.value; });
});

const boot = () => { $$('[data-collection-preview]').forEach(initPreview); $$('[data-collection-find]').forEach(initFind); };
boot();
document.addEventListener('livewire:navigated', boot);
