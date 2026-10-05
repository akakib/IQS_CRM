import './bootstrap';
import Alpine from 'alpinejs';
import dateInput from './components/date-input';

window.Alpine = Alpine;
Alpine.data('dateInput', dateInput);
Alpine.start();
