import './bootstrap';
import Alpine from 'alpinejs';
import dateInput from './components/date-input';
import phoneDial from './components/phone-dial';
import scanInput from './components/scan-input';
import voiceAlerts from './components/voice-alerts';
import orderPresence from './components/order-presence';
import pageTabs from './components/page-tabs';

window.Alpine = Alpine;
Alpine.data('dateInput', dateInput);
Alpine.data('phoneDial', phoneDial);
Alpine.data('scanInput', scanInput);
Alpine.data('voiceAlerts', voiceAlerts);
Alpine.data('orderPresence', orderPresence);
Alpine.data('tabs', pageTabs);
Alpine.start();

// One click, one action. Once a form is on its way to the server, a second
// click (or Enter, or a keyboard shortcut) does nothing and its buttons grey
// out until the next page arrives. Forms handled by script (preventDefault)
// are left alone. The server still checks on its own; this is the first guard.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (event.defaultPrevented || !(form instanceof HTMLFormElement)) return;
    if (form.dataset.submitting) {
        event.preventDefault();
        return;
    }
    form.dataset.submitting = '1';
    rememberScroll();
    // After this tick, so the clicked button's own name/value is still sent.
    // Buttons inside the form, plus any that point at it from outside with form="id".
    const buttons = [...form.querySelectorAll('button[type=submit]:not([disabled]), button:not([type]):not([disabled])'),
        ...(form.id ? document.querySelectorAll(`button[form="${form.id}"]:not([disabled])`) : [])];
    setTimeout(() => buttons.forEach((b) => {
        b.disabled = true;
        b.dataset.locked = '1';
    }), 0);
});
// Coming back with the browser's Back button restores the old page: unlock it.
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitting]').forEach((form) => {
        delete form.dataset.submitting;
        document.querySelectorAll('button[data-locked]').forEach((b) => {
            b.disabled = false;
            delete b.dataset.locked;
        });
    });
});

// Stay in place after a save. A form that sends the page back to itself (Hide,
// Save, Add…) used to land at the top; the scroll position is noted when the
// form is sent and put back when the same page returns within a few seconds.
// A form that leads to another page is not affected.
const SCROLL_KEY = 'iqs_scroll_back';

function rememberScroll() {
    try {
        sessionStorage.setItem(SCROLL_KEY, JSON.stringify({ path: location.pathname + location.search, y: window.scrollY, at: Date.now() }));
    } catch (e) {}
}

(function restoreScroll() {
    let saved = null;
    try {
        saved = JSON.parse(sessionStorage.getItem(SCROLL_KEY));
        sessionStorage.removeItem(SCROLL_KEY);
    } catch (e) {}
    if (!saved || Date.now() - saved.at > 15000 || saved.path !== location.pathname + location.search || !saved.y) return;
    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
    const go = () => window.scrollTo(0, saved.y);
    go();
    requestAnimationFrame(go); // again once Alpine has drawn the page
})();
