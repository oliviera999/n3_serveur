/**
 * Toast Notification Manager
 * Système de notifications visuelles non-intrusives
 */
class ToastManager {
    constructor() {
        this.container = null;
        this.init();
    }

    init() {
        // Créer le conteneur de toasts s'il n'existe pas
        if (!document.getElementById('toast-container')) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);
        } else {
            this.container = document.getElementById('toast-container');
        }
    }

    show(message, type = 'info', duration = 5000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        // Icône selon le type
        const icons = {
            info: '<i class="fas fa-info-circle"></i>',
            success: '<i class="fas fa-check-circle"></i>',
            warning: '<i class="fas fa-exclamation-triangle"></i>',
            error: '<i class="fas fa-times-circle"></i>'
        };

        toast.innerHTML = `
            <div class="toast-icon">${icons[type] || icons.info}</div>
            <div class="toast-message">${message}</div>
            <button class="toast-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        `;

        this.container.appendChild(toast);

        // Animation d'entrée
        setTimeout(() => toast.classList.add('toast-visible'), 10);

        // Auto-dismiss
        if (duration > 0) {
            setTimeout(() => {
                toast.classList.remove('toast-visible');
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

        return toast;
    }

    showInfo(message, duration = 5000) {
        return this.show(message, 'info', duration);
    }

    showSuccess(message, duration = 5000) {
        return this.show(message, 'success', duration);
    }

    showWarning(message, duration = 7000) {
        return this.show(message, 'warning', duration);
    }

    showError(message, duration = 10000) {
        return this.show(message, 'error', duration);
    }

    clear() {
        if (this.container) {
            this.container.innerHTML = '';
        }
    }
}

// Instance globale
const toastManager = new ToastManager();

