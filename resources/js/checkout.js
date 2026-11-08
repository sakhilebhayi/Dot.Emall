/*
 * Cart and checkout behaviour (R-43 W5). Plain progressive enhancement: every form below works without this file, which only removes a
 * button press or protects a second click. Driven by data attributes, so it keeps working after a Livewire morph.
 */

/* Cart: a quantity or delivery change posts itself shortly after the last change (the no-script page has an Update button instead). */
const timers = new WeakMap();
document.addEventListener('change', (e) => {
    const form = e.target.closest && e.target.closest('form[data-autosubmit-form]');
    if (!form) return;
    clearTimeout(timers.get(form));
    timers.set(form, setTimeout(() => { if (form.isConnected) form.requestSubmit(); }, e.target.type === 'radio' ? 0 : 500));
});

/* Checkout: choosing another saved address or another delivery option re-prices the parcels at once, so the total beside Pay is never one
   the buyer did not choose (the no-script page keeps the Update delivery options button). The server recomputes everything on Pay regardless. */
document.addEventListener('change', (e) => {
    const input = e.target;
    const form = input.closest && input.closest('form[data-checkout-form]');
    if (!form || input.type !== 'radio') return;
    const savedAddress = input.name === 'address_id' && input.value !== 'new';
    if (!savedAddress && !/^shipping_methods\[/.test(input.name)) return;
    const button = form.querySelector('[data-delivery-update]');
    if (!button || typeof form.requestSubmit !== 'function') return;
    let back = form.querySelector('input[name="back"]');
    if (!back) { back = document.createElement('input'); back.type = 'hidden'; back.name = 'back'; form.appendChild(back); }
    back.value = 'delivery';
    form.requestSubmit(button);
});

/* Checkout: pay once. The pay button locks the moment the form is sent (the server also treats a repeated token as the same order). */
document.addEventListener('submit', (e) => {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-checkout-form')) return;
    const pay = e.submitter && e.submitter.hasAttribute('data-pay') ? e.submitter : null;
    if (!pay) return;
    if (form.dataset.paying) { e.preventDefault(); return; }
    form.dataset.paying = '1';
    setTimeout(() => {
        pay.disabled = true; pay.setAttribute('aria-busy', 'true');
        const label = pay.querySelector('span'); if (label) label.textContent = 'Placing your order';
        const note = form.querySelector('[data-pay-note]'); if (note) note.textContent = 'Placing your order and taking you to the payment page. Please do not press back or reload.';
    }, 0);
});
/* Coming back with the browser's back button must not leave the button dead. */
window.addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    document.querySelectorAll('form[data-checkout-form]').forEach((f) => { delete f.dataset.paying; f.querySelectorAll('[data-pay]').forEach((b) => { b.disabled = false; b.removeAttribute('aria-busy'); }); });
});

/* Receipt: print from the page (data-print). */
document.addEventListener('click', (e) => { if (e.target.closest('[data-print]')) window.print(); });
