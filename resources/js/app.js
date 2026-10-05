import './bootstrap';
import Alpine from 'alpinejs';
import dateInput from './components/date-input';
import phoneDial from './components/phone-dial';

window.Alpine = Alpine;
Alpine.data('dateInput', dateInput);
Alpine.data('phoneDial', phoneDial);
Alpine.start();
