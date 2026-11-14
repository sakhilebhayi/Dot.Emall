/*
 * The dashboards' small behaviours (R-51). Everything is driven by data attributes, so it works after a Livewire morph, and every page is already correct with this file switched off.
 *   [data-chart]  the sales chart: a tooltip for the day under the pointer or the keyboard focus (text set with textContent, never HTML).
 *   [data-count]  a count that rises once on arrival and ALWAYS ends on the number the server printed; [data-money] does the same for the one big figure.
 * No count-up under prefers-reduced-motion, and none on the operator's queue (read under pressure).
 */

const reduced = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function initChart(chart) {
    if (chart.dataset.ready) return;
    chart.dataset.ready = '1';
    const tip = chart.querySelector('.dx-chart__tip');
    if (!tip) return;
    const show = (hit) => {
        tip.textContent = hit.getAttribute('data-t') || '';
        const r = hit.getBoundingClientRect();
        const p = chart.getBoundingClientRect();
        tip.style.left = (r.left + r.width / 2 - p.left) + 'px';
        tip.style.top = (r.top - p.top) + 'px';
        tip.style.opacity = '1';
    };
    const hide = () => { tip.style.opacity = '0'; };
    chart.querySelectorAll('.dx-chart__hit').forEach((hit) => {
        hit.addEventListener('mouseenter', () => show(hit));
        hit.addEventListener('focus', () => show(hit));
        hit.addEventListener('mouseleave', hide);
        hit.addEventListener('blur', hide);
    });
}

const ease = (t) => 1 - Math.pow(1 - t, 3);

/* Rises from 0 to the printed text over `ms`, then writes the printed text back, so the page can never end on a rounded or partial number. */
function countUp(el, final, format, ms, delay) {
    const printed = el.textContent;
    el.style.display = 'inline-block';
    el.style.minWidth = el.getBoundingClientRect().width + 'px'; // the final width is reserved first: nothing around the figure moves
    const start = performance.now() + delay;
    const step = (now) => {
        if (now < start) { el.textContent = format(0); requestAnimationFrame(step); return; }
        const t = Math.min(1, (now - start) / ms);
        if (t >= 1) { el.textContent = printed; return; }
        el.textContent = format(Math.round(final * ease(t)));
        requestAnimationFrame(step);
    };
    el.textContent = format(0);
    requestAnimationFrame(step);
}

const rand = (cents) => (cents < 0 ? '-' : '') + 'R' + (Math.abs(cents) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function initCounts() {
    const dash = document.querySelector('.dx');
    if (!dash || dash.dataset.counted || dash.dataset.dashboard === 'operator' || reduced()) return;
    dash.dataset.counted = '1';
    dash.querySelectorAll('[data-count]').forEach((el, i) => {
        const n = parseInt(el.getAttribute('data-count'), 10);
        if (!Number.isFinite(n) || n < 2) return; // a 0 or 1 has nowhere to rise from
        countUp(el, n, (v) => v.toLocaleString('en-US'), 420, 220 + i * 40);
    });
    dash.querySelectorAll('[data-money]').forEach((el) => {
        const c = parseInt(el.getAttribute('data-money'), 10);
        if (!Number.isFinite(c) || c <= 0) return;
        countUp(el, c, rand, 620, 300);
    });
}

function init() {
    document.querySelectorAll('[data-chart]').forEach(initChart);
    initCounts();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
document.addEventListener('livewire:navigated', init);
