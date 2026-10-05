import './bootstrap';
import Alpine from 'alpinejs';
import dateInput from './components/date-input';
import phoneDial from './components/phone-dial';

window.Alpine = Alpine;
Alpine.data('dateInput', dateInput);
Alpine.data('phoneDial', phoneDial);
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
