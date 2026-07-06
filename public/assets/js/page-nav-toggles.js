/**
 * page-nav-toggles.js — Pages visibles dans la barre de navigation.
 *
 * L'état est désormais PERSISTÉ CÔTÉ SERVEUR (table navPages) et GLOBAL : il
 * s'applique à tous les visiteurs, y compris non connectés. Le menu est rendu
 * côté serveur (partials/_nav.twig) ; ce script gère uniquement, sur la page de
 * supervision, les switchs (réservés aux admins) et la mise à jour live du menu.
 *
 * Config injectée par la page supervision (avant DOMContentLoaded) :
 *   window.NAV_PAGES_API      — URL POST de bascule
 *   window.NAV_PAGES_CAN_EDIT — booléen : l'utilisateur peut-il modifier
 *   window.NAV_PAGES_STATE    — map { clé: actif } pour l'état initial des switchs
 */
(function () {
    'use strict';

    function canEdit() {
        return window.NAV_PAGES_CAN_EDIT === true;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    /**
     * Listes de liens à mettre à jour : nav desktop (#nav) et panneau mobile
     * (#navPanelNav, cloné par main.js).
     */
    function getNavLists() {
        var lists = [];
        var desktop = document.querySelector('#nav ul.links');
        var mobile = document.querySelector('#navPanelNav ul.links');
        if (desktop) { lists.push(desktop); }
        if (mobile) { lists.push(mobile); }
        return lists;
    }

    /**
     * Reconstruit les <li> dynamiques du menu à partir de la liste active
     * renvoyée par le serveur (mise à jour live sans rechargement).
     */
    function renderNavFromList(activeList) {
        if (!Array.isArray(activeList)) { return; }
        getNavLists().forEach(function (ul) {
            var existing = ul.querySelectorAll('.nav-dyn-item');
            for (var i = 0; i < existing.length; i++) {
                existing[i].parentNode.removeChild(existing[i]);
            }
            var anchor = ul.querySelector('.nav-perm-item') || ul.querySelector('.nav-theme-toggle');
            activeList.forEach(function (page) {
                if (!page || !page.url || !page.label) { return; }
                var li = document.createElement('li');
                li.className = 'nav-dyn-item';
                li.setAttribute('data-nav-key', page.key || '');
                var a = document.createElement('a');
                a.href = page.url;
                a.textContent = page.label;
                a.title = page.label;
                li.appendChild(a);
                if (anchor) {
                    ul.insertBefore(li, anchor);
                } else {
                    ul.appendChild(li);
                }
            });
        });
    }

    /**
     * Applique un état coché/visuel à toutes les cases d'une même clé
     * (la clé peut apparaître plusieurs fois : section Live + grille de liens).
     */
    function setCheckboxesByKey(key, checked) {
        var all = document.querySelectorAll('.page-nav-cb');
        for (var i = 0; i < all.length; i++) {
            if (all[i].getAttribute('data-page-key') === key) {
                all[i].checked = checked;
                markWrapActive(all[i], checked);
            }
        }
    }

    function postToggle(key, label, url, active) {
        return fetch(window.NAV_PAGES_API, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'fetch',
                'X-CSRF-Token': csrfToken()
            },
            credentials: 'include',
            body: JSON.stringify({ key: key, label: label, url: url, active: active })
        }).then(function (r) {
            return r.json().then(function (d) {
                return { ok: r.ok, data: d };
            }).catch(function () {
                return { ok: r.ok, data: null };
            });
        });
    }

    function onToggleChange(e) {
        e.stopPropagation();
        var cb = e.currentTarget;
        var key = cb.getAttribute('data-page-key');
        var label = cb.getAttribute('data-page-label');
        var url = cb.getAttribute('data-page-url');
        var desired = cb.checked;

        // Reflet immédiat (optimiste) sur tous les doublons de la même page.
        setCheckboxesByKey(key, desired);
        cb.disabled = true;

        postToggle(key, label, url, desired).then(function (res) {
            if (!res.ok || !res.data || res.data.success !== true) {
                setCheckboxesByKey(key, !desired);
                window.alert((res.data && res.data.error) || 'Modification refusée.');
                return;
            }
            renderNavFromList(res.data.active);
        }).catch(function () {
            setCheckboxesByKey(key, !desired);
            window.alert('Erreur réseau — modification non enregistrée.');
        }).then(function () {
            cb.disabled = !canEdit();
        });
    }

    /**
     * Initialise les switchs sur la page supervision : état initial depuis le
     * serveur, écoute des changements, verrouillage si l'utilisateur n'est pas admin.
     */
    function initToggles() {
        var checkboxes = document.querySelectorAll('.page-nav-cb');
        if (checkboxes.length === 0) { return; }

        var state = window.NAV_PAGES_STATE || {};
        var editable = canEdit();

        for (var i = 0; i < checkboxes.length; i++) {
            var cb = checkboxes[i];
            var key = cb.getAttribute('data-page-key');
            var on = !!state[key];
            cb.checked = on;
            markWrapActive(cb, on);
            if (editable) {
                cb.addEventListener('change', onToggleChange);
            } else {
                cb.disabled = true;
                cb.title = 'Réservé aux administrateurs';
            }
        }
    }

    function markWrapActive(cb, active) {
        var wrap = cb;
        while (wrap && wrap !== document.body) {
            if (wrap.classList && (wrap.classList.contains('link-card-wrap') || wrap.classList.contains('live-card-wrap'))) {
                break;
            }
            wrap = wrap.parentElement;
        }
        if (wrap && wrap.classList) {
            if (active) {
                wrap.classList.add('nav-active');
            } else {
                wrap.classList.remove('nav-active');
            }
        }
    }

    /**
     * Empêche le clic sur le toggle de suivre le lien parent (carte cliquable).
     */
    function blockToggleNavigation() {
        document.addEventListener('click', function (e) {
            var el = e.target;
            while (el && el !== document) {
                if (el.classList && el.classList.contains('page-nav-toggle')) {
                    e.stopPropagation();
                    return;
                }
                el = el.parentElement;
            }
        }, true);
    }

    function onReady(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    onReady(function () {
        blockToggleNavigation();
        initToggles();
    });
})();
