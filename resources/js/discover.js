/*
 * Discovery pages. Everything here is an enhancement: the search, the filters, the assistant and the saved searches all work as plain links and forms with scripting off.
 */

/* The phone filter sheet is a modal dialog. If the window grows to the desktop layout (a rotated tablet), the panel beside the results takes over, so close the sheet. */
(() => {
    const wide = window.matchMedia('(min-width: 64rem)');
    wide.addEventListener?.('change', (e) => {
        if (!e.matches) return;
        const sheet = document.getElementById('filters-sheet');
        if (sheet && sheet.open) sheet.close();
    });
})();

/* After "Ask Emall" the page loads at the answer; move keyboard and screen reader focus there so the answer is read. */
(() => {
    if (location.hash !== '#answer') return;
    const answer = document.getElementById('answer');
    if (answer) answer.focus({ preventScroll: false });
})();
