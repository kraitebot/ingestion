/**
 * Toast Notification System (with stacking support)
 *
 * Usage:
 *   window.showToast('Profile updated!', 'success');
 *   window.showToast('An error occurred', 'error');
 *   window.showToast('Please wait...', 'info');
 */

export function initToast() {
    let toastCounter = 0;

    /**
     * Show a toast notification (creates new toast element for stacking)
     *
     * @param {string} message - The message to display
     * @param {string} type - The type: 'success', 'error', 'info', 'warning'
     * @param {number} duration - Duration in milliseconds (default: 10000)
     */
    window.showToast = function(message, type = 'success', duration = 10000) {
        const container = document.getElementById('toast-container');

        if (!container) {
            console.warn('Toast container not found in DOM');
            return;
        }

        // Create unique toast ID
        const toastId = `toast-${++toastCounter}`;

        // Determine icon and styling based on type
        let iconName, borderColor, bgColor, textColor, iconColor;

        switch (type) {
            case 'success':
                iconName = 'check-circle';
                borderColor = 'border-emerald-400/30';
                bgColor = 'bg-emerald-500/10';
                textColor = 'text-emerald-200';
                iconColor = 'text-emerald-300';
                break;
            case 'error':
                iconName = 'x-circle';
                borderColor = 'border-red-400/30';
                bgColor = 'bg-red-500/10';
                textColor = 'text-red-200';
                iconColor = 'text-red-300';
                break;
            case 'warning':
                iconName = 'alert-triangle';
                borderColor = 'border-yellow-400/30';
                bgColor = 'bg-yellow-500/10';
                textColor = 'text-yellow-200';
                iconColor = 'text-yellow-300';
                break;
            case 'info':
                iconName = 'info';
                borderColor = 'border-blue-400/30';
                bgColor = 'bg-blue-500/10';
                textColor = 'text-blue-200';
                iconColor = 'text-blue-300';
                break;
            default:
                iconName = 'bell';
                borderColor = 'border-white/10';
                bgColor = 'bg-white/5';
                textColor = 'text-white';
                iconColor = 'text-white/60';
        }

        // Create toast element
        const toast = document.createElement('div');
        toast.id = toastId;
        toast.className = 'opacity-0 -translate-y-[50px] transition-all duration-300 transform pointer-events-auto cursor-pointer';

        toast.innerHTML = `
            <div class="rounded-lg backdrop-blur-sm px-6 py-3 text-sm shadow-lg flex items-center gap-3 min-w-[300px] max-w-[500px] border ${borderColor} ${bgColor} ${textColor}">
                <span class="h-5 w-5 shrink-0 ${iconColor}">
                    <i data-feather="${iconName}"></i>
                </span>
                <span class="flex-1">${message}</span>
            </div>
        `;

        // Add to container
        container.appendChild(toast);

        // Re-render Feather icons
        if (typeof feather !== 'undefined') {
            feather.replace();
        }

        // Trigger animation (after DOM insertion)
        requestAnimationFrame(function() {
            toast.classList.remove('opacity-0', '-translate-y-[50px]');
            toast.classList.add('opacity-100', 'translate-y-0');
        });

        // Function to remove toast
        const removeToast = function() {
            // Get current height to pull it up completely
            const currentHeight = toast.offsetHeight;

            // Start fade and slide up animation, plus negative margin to pull space up
            toast.classList.remove('opacity-100', 'translate-y-0');
            toast.classList.add('opacity-0');

            // Pull the toast up by its full height plus gap using negative margin
            toast.style.marginTop = `-${currentHeight + 12}px`; // 12px = gap-3

            // Remove from DOM after animation
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
            }, 300);
        };

        // Allow clicking toast to dismiss
        toast.addEventListener('click', removeToast);

        // Auto-hide after duration
        if (duration > 0) {
            setTimeout(removeToast, duration);
        }
    };

    /**
     * Hide all toast notifications
     */
    window.hideAllToasts = function() {
        const container = document.getElementById('toast-container');
        if (container) {
            while (container.firstChild) {
                container.removeChild(container.firstChild);
            }
        }
    };
}
