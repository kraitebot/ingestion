import { initToast } from './modules/toast.js';
import html2canvas from 'html2canvas-pro';
import Alpine from 'alpinejs';

// Expose html2canvas globally for inline scripts
window.html2canvas = html2canvas;

// Expose Alpine globally and start it
window.Alpine = Alpine;
Alpine.start();

// Initialize toast notification system
document.addEventListener('DOMContentLoaded', function() {
    initToast();
});
