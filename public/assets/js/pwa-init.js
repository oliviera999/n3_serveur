/**
 * Initialisation de la Progressive Web App
 * Enregistre le service worker et gère l'installation
 */

// Enregistrer le service worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/ffp3/public/service-worker.js')
            .then(registration => {
                console.log('[PWA] Service Worker registered:', registration.scope);
                
                // Vérifier les mises à jour toutes les heures
                setInterval(() => {
                    registration.update();
                }, 3600000);
                
                // Gérer les mises à jour
                registration.addEventListener('updatefound', () => {
                    const newWorker = registration.installing;
                    
                    newWorker.addEventListener('statechange', () => {
                        if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                            // Nouvelle version disponible
                            if (typeof toastManager !== 'undefined') {
                                const toast = toastManager.showInfo(
                                    'Nouvelle version disponible. <a href="#" onclick="window.location.reload()">Recharger</a>',
                                    0
                                );
                            } else {
                                if (confirm('Nouvelle version disponible. Recharger maintenant ?')) {
                                    window.location.reload();
                                }
                            }
                        }
                    });
                });
            })
            .catch(error => {
                console.error('[PWA] Service Worker registration failed:', error);
            });
    });
}

// Détecter si l'app est installée
let deferredPrompt;
window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    
    // Afficher un bouton d'installation personnalisé
    showInstallButton();
});

/**
 * Affiche un bouton pour installer l'app
 */
function showInstallButton() {
    const installBtn = document.getElementById('install-pwa-btn');
    if (installBtn) {
        installBtn.style.display = 'block';
        installBtn.addEventListener('click', () => {
            installBtn.style.display = 'none';
            
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then((choiceResult) => {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('[PWA] User accepted install');
                        if (typeof toastManager !== 'undefined') {
                            toastManager.showSuccess('Application installée avec succès !');
                        }
                    }
                    deferredPrompt = null;
                });
            }
        });
    }
}

// Détecter si l'app est déjà installée
window.addEventListener('appinstalled', () => {
    console.log('[PWA] App installed successfully');
    if (typeof toastManager !== 'undefined') {
        toastManager.showSuccess('FFP3 Aqua installé ! Vous pouvez maintenant l\'utiliser hors ligne.');
    }
});

// Détecter le mode standalone (app installée)
function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches ||
           window.navigator.standalone ||
           document.referrer.includes('android-app://');
}

if (isStandalone()) {
    console.log('[PWA] Running in standalone mode');
    document.body.classList.add('pwa-installed');
}

// Gérer l'état online/offline
window.addEventListener('online', () => {
    console.log('[PWA] Back online');
    if (typeof toastManager !== 'undefined') {
        toastManager.showSuccess('Connexion rétablie');
    }
    
    // Synchroniser les données
    if ('serviceWorker' in navigator && 'sync' in navigator.serviceWorker) {
        navigator.serviceWorker.ready.then(registration => {
            return registration.sync.register('sync-data');
        });
    }
});

window.addEventListener('offline', () => {
    console.log('[PWA] Gone offline');
    if (typeof toastManager !== 'undefined') {
        toastManager.showWarning('Mode hors ligne activé', 0);
    }
});

// Exposer fonction pour contrôle manuel
window.PWA = {
    isInstalled: isStandalone,
    install: () => {
        if (deferredPrompt) {
            deferredPrompt.prompt();
        } else {
            alert('L\'application est déjà installée ou le navigateur ne supporte pas l\'installation.');
        }
    },
    update: () => {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then(registrations => {
                registrations.forEach(registration => registration.update());
            });
        }
    },
    clearCache: () => {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_CACHE' });
            toastManager.showSuccess('Cache vidé. Rechargez la page.');
        }
    }
};

