/**
 * display-presets-editor.js — Éditeur des presets d'affichage (pages de contrôle).
 *
 * Charge le bootstrap JSON (#dp-bootstrap), permet de créer / dupliquer / supprimer
 * des presets, de réordonner les cadres (glisser-déposer + boutons) et d'ajuster
 * la visibilité des cartes (shown/hidden) et séries (shown/legend/hidden).
 * Écritures via /api/display-presets/{page} (CSRF X-CSRF-Token).
 */
(function () {
    'use strict';

    var SERIES_LABELS = { shown: 'Affichée', legend: 'Légende seule', hidden: 'Masquée' };

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function toast(msg, type) {
        if (window.showToast) {
            window.showToast(msg, type || 'info');
            return;
        }
        // Repli si toast-notifications.js n'est pas chargé.
        window.alert(msg);
    }

    function clone(obj) {
        return JSON.parse(JSON.stringify(obj));
    }

    function Editor(root) {
        this.root = root;
        this.api = root.dataset.api;
        this.monitoringUrl = root.dataset.monitoringUrl || '';
        this.data = null;
        this.currentSlug = null;
        this.dirty = false;

        this.els = {
            select: root.querySelector('#dp-preset-select'),
            slug: root.querySelector('#dp-slug'),
            label: root.querySelector('#dp-label'),
            description: root.querySelector('#dp-description'),
            isDefault: root.querySelector('#dp-is-default'),
            showOnPage: root.querySelector('#dp-show-on-page'),
            sortOrder: root.querySelector('#dp-sort-order'),
            blocks: root.querySelector('#dp-blocks'),
            status: root.querySelector('#dp-status'),
            preview: root.querySelector('#dp-btn-preview'),
            btnNew: root.querySelector('#dp-btn-new'),
            btnDup: root.querySelector('#dp-btn-duplicate'),
            btnDel: root.querySelector('#dp-btn-delete'),
            btnSave: root.querySelector('#dp-btn-save')
        };
    }

    Editor.prototype.setStatus = function (msg, kind) {
        this.els.status.textContent = msg || '';
        this.els.status.className = 'dp-status' + (kind ? ' is-' + kind : '');
    };

    Editor.prototype.presetBySlug = function (slug) {
        return (this.data.presets || []).find(function (p) { return p.slug === slug; }) || null;
    };

    Editor.prototype.catalogBlock = function (id) {
        return (this.data.catalog || []).find(function (b) { return b.id === id; }) || null;
    };

    Editor.prototype.loadBootstrap = function () {
        var node = this.root.querySelector('#dp-bootstrap');
        if (!node) return false;
        try {
            this.data = JSON.parse(node.textContent);
        } catch (e) {
            this.setStatus('Données d\'éditeur invalides.', 'error');
            return false;
        }
        return true;
    };

    Editor.prototype.applyServerData = function (data) {
        this.data = data;
        this.fillSelect();
        if (!this.presetBySlug(this.currentSlug) && this.data.presets.length) {
            this.currentSlug = this.data.presets[0].slug;
        }
        this.loadPreset(this.currentSlug);
        this.dirty = false;
    };

    Editor.prototype.fillSelect = function () {
        var select = this.els.select;
        var current = this.currentSlug;
        select.innerHTML = '';
        (this.data.presets || []).forEach(function (p) {
            var opt = document.createElement('option');
            opt.value = p.slug;
            opt.textContent = p.label + (p.customized ? ' (personnalisé)' : '') + (p.is_default ? ' ★' : '');
            select.appendChild(opt);
        });
        if (current) select.value = current;
    };

    Editor.prototype.loadPreset = function (slug) {
        var preset = this.presetBySlug(slug);
        if (!preset) return;
        this.currentSlug = slug;
        this.els.select.value = slug;
        this.els.slug.value = preset.slug;
        this.els.slug.readOnly = !!preset.builtin || !!preset.customized;
        this.els.label.value = preset.label;
        this.els.description.value = preset.description || '';
        this.els.isDefault.checked = !!preset.is_default;
        this.els.showOnPage.checked = !!preset.show_on_page;
        this.els.sortOrder.value = preset.sort_order;
        this.renderBlocks(preset.blocks || []);
        this.updatePreviewLink();
        this.setStatus(preset.customized
            ? 'Preset intégré personnalisé — « Supprimer » le réinitialise.'
            : (preset.builtin ? 'Preset intégré (modifiable, enregistré en base).' : ''));
        this.dirty = false;
    };

    Editor.prototype.renderBlocks = function (blocks) {
        var self = this;
        var list = this.els.blocks;
        list.innerHTML = '';
        blocks.forEach(function (state, index) {
            var def = self.catalogBlock(state.id);
            if (!def) return;
            list.appendChild(self.buildBlockEl(def, state, index, blocks.length));
        });
    };

    Editor.prototype.buildBlockEl = function (def, state, index, total) {
        var self = this;
        var li = document.createElement('li');
        li.className = 'dp-block' + (state.visible ? '' : ' is-hidden');
        li.draggable = true;
        li.dataset.blockId = def.id;

        var head = document.createElement('div');
        head.className = 'dp-block-head';
        head.innerHTML =
            '<span class="dp-handle" title="Glisser pour réordonner" aria-hidden="true"><i class="fas fa-grip-vertical"></i></span>' +
            '<span class="dp-block-title"><i class="fas ' + (def.icon || 'fa-square') + '"></i> ' + escapeHtml(def.title || def.id) + '</span>';

        var vis = document.createElement('label');
        vis.className = 'dp-check';
        vis.innerHTML = '<input type="checkbox"' + (state.visible ? ' checked' : '') + '> Visible';
        vis.querySelector('input').addEventListener('change', function (e) {
            state.visible = e.target.checked;
            li.classList.toggle('is-hidden', !state.visible);
            self.markDirty();
        });
        head.appendChild(vis);

        if (def.has_chart) {
            var chart = document.createElement('label');
            chart.className = 'dp-check';
            chart.innerHTML = '<input type="checkbox"' + (state.chart ? ' checked' : '') + '> Graphique';
            chart.querySelector('input').addEventListener('change', function (e) {
                state.chart = e.target.checked;
                self.markDirty();
            });
            head.appendChild(chart);
        }

        var up = document.createElement('button');
        up.type = 'button';
        up.className = 'dp-btn';
        up.textContent = '↑';
        up.title = 'Monter';
        up.disabled = index === 0;
        up.addEventListener('click', function () { self.moveBlock(def.id, -1); });
        head.appendChild(up);

        var down = document.createElement('button');
        down.type = 'button';
        down.className = 'dp-btn';
        down.textContent = '↓';
        down.title = 'Descendre';
        down.disabled = index === total - 1;
        down.addEventListener('click', function () { self.moveBlock(def.id, 1); });
        head.appendChild(down);

        li.appendChild(head);

        var body = document.createElement('div');
        body.className = 'dp-block-body';

        if (def.cards && def.cards.length) {
            var cardsCol = document.createElement('div');
            cardsCol.innerHTML = '<h4>Cartes</h4>';
            def.cards.forEach(function (c) {
                var row = document.createElement('label');
                row.className = 'dp-item';
                var checked = (state.cards[c.key] || 'shown') === 'shown';
                row.innerHTML = '<input type="checkbox"' + (checked ? ' checked' : '') + '> ' + escapeHtml(c.label);
                row.querySelector('input').addEventListener('change', function (e) {
                    state.cards[c.key] = e.target.checked ? 'shown' : 'hidden';
                    self.markDirty();
                });
                cardsCol.appendChild(row);
            });
            body.appendChild(cardsCol);
        }

        if (def.series && def.series.length) {
            var seriesCol = document.createElement('div');
            seriesCol.innerHTML = '<h4>Séries</h4>';
            def.series.forEach(function (s) {
                var row = document.createElement('div');
                row.className = 'dp-item';
                var swatch = document.createElement('span');
                swatch.className = 'dp-swatch';
                swatch.style.background = s.color || '#999';
                row.appendChild(swatch);
                var label = document.createElement('span');
                label.textContent = s.name;
                label.style.flex = '1 1 auto';
                row.appendChild(label);
                var sel = document.createElement('select');
                sel.setAttribute('aria-label', 'Visibilité de ' + s.name);
                ['shown', 'legend', 'hidden'].forEach(function (v) {
                    var opt = document.createElement('option');
                    opt.value = v;
                    opt.textContent = SERIES_LABELS[v];
                    if ((state.series[s.id] || 'shown') === v) opt.selected = true;
                    sel.appendChild(opt);
                });
                sel.addEventListener('change', function () {
                    state.series[s.id] = sel.value;
                    self.markDirty();
                });
                row.appendChild(sel);
                seriesCol.appendChild(row);
            });
            body.appendChild(seriesCol);
        }

        if (body.children.length) li.appendChild(body);

        li.addEventListener('dragstart', function (e) {
            li.classList.add('is-dragging');
            e.dataTransfer.setData('text/plain', def.id);
            e.dataTransfer.effectAllowed = 'move';
        });
        li.addEventListener('dragend', function () { li.classList.remove('is-dragging'); });
        li.addEventListener('dragover', function (e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });
        li.addEventListener('drop', function (e) {
            e.preventDefault();
            var fromId = e.dataTransfer.getData('text/plain');
            var toId = def.id;
            if (fromId && fromId !== toId) self.reorderBlocks(fromId, toId);
        });

        // Garde l'état mutable attaché à l'élément pour collectLayout().
        li._state = state;
        return li;
    };

    Editor.prototype.collectLayout = function () {
        var blocks = [];
        Array.prototype.forEach.call(this.els.blocks.children, function (li) {
            if (li._state) blocks.push(li._state);
        });
        return { blocks: blocks };
    };

    Editor.prototype.moveBlock = function (id, delta) {
        var nodes = Array.prototype.slice.call(this.els.blocks.children);
        var idx = nodes.findIndex(function (n) { return n.dataset.blockId === id; });
        var target = idx + delta;
        if (idx < 0 || target < 0 || target >= nodes.length) return;
        var ref = delta > 0 ? nodes[target].nextSibling : nodes[target];
        this.els.blocks.insertBefore(nodes[idx], ref);
        this.refreshMoveButtons();
        this.markDirty();
    };

    Editor.prototype.reorderBlocks = function (fromId, toId) {
        var from = this.els.blocks.querySelector('[data-block-id="' + cssEscape(fromId) + '"]');
        var to = this.els.blocks.querySelector('[data-block-id="' + cssEscape(toId) + '"]');
        if (!from || !to) return;
        this.els.blocks.insertBefore(from, to);
        this.refreshMoveButtons();
        this.markDirty();
    };

    Editor.prototype.refreshMoveButtons = function () {
        var nodes = this.els.blocks.children;
        Array.prototype.forEach.call(nodes, function (li, i) {
            var buttons = li.querySelectorAll('.dp-block-head > .dp-btn');
            if (buttons[0]) buttons[0].disabled = i === 0;
            if (buttons[1]) buttons[1].disabled = i === nodes.length - 1;
        });
    };

    Editor.prototype.markDirty = function () {
        this.dirty = true;
        this.setStatus('Modifications non enregistrées.', '');
    };

    Editor.prototype.updatePreviewLink = function () {
        var slug = this.els.slug.value.trim() || this.currentSlug;
        var url = this.monitoringUrl || this.data.monitoring_url || '';
        if (!url) {
            this.els.preview.removeAttribute('href');
            return;
        }
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        this.els.preview.href = url + sep + 'preset=' + encodeURIComponent(slug);
    };

    Editor.prototype.payloadFromForm = function () {
        return {
            slug: this.els.slug.value.trim().toLowerCase(),
            label: this.els.label.value.trim(),
            description: this.els.description.value.trim(),
            is_default: this.els.isDefault.checked,
            show_on_page: this.els.showOnPage.checked,
            sort_order: parseInt(this.els.sortOrder.value, 10) || 100,
            layout: this.collectLayout()
        };
    };

    Editor.prototype.request = function (method, path, body) {
        var opts = {
            method: method,
            headers: {
                'Accept': 'application/json',
                'X-CSRF-Token': csrfToken()
            },
            credentials: 'same-origin'
        };
        if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        return fetch(path, opts).then(function (res) {
            return res.json().then(function (json) {
                return { ok: res.ok, status: res.status, json: json };
            }).catch(function () {
                return { ok: res.ok, status: res.status, json: { success: false, error: 'Réponse invalide.' } };
            });
        });
    };

    Editor.prototype.save = function () {
        var self = this;
        var payload = this.payloadFromForm();
        if (!payload.slug || !payload.label) {
            this.setStatus('Identifiant et nom obligatoires.', 'error');
            return;
        }
        this.els.btnSave.disabled = true;
        this.setStatus('Enregistrement…');
        this.request('POST', this.api, payload).then(function (res) {
            self.els.btnSave.disabled = false;
            if (!res.json.success) {
                self.setStatus(res.json.error || 'Échec.', 'error');
                toast(res.json.error || 'Enregistrement impossible', 'error');
                return;
            }
            self.currentSlug = payload.slug;
            self.applyServerData(res.json.data);
            self.setStatus('Enregistré.', 'ok');
            toast('Preset enregistré', 'success');
        }).catch(function () {
            self.els.btnSave.disabled = false;
            self.setStatus('Erreur réseau.', 'error');
        });
    };

    Editor.prototype.remove = function () {
        var self = this;
        var preset = this.presetBySlug(this.currentSlug);
        if (!preset) return;
        var msg = preset.builtin
            ? 'Réinitialiser le preset intégré « ' + preset.label + ' » à sa définition du code ?'
            : 'Supprimer définitivement le preset « ' + preset.label + ' » ?';
        if (!window.confirm(msg)) return;

        this.request('DELETE', this.api + '/' + encodeURIComponent(preset.slug)).then(function (res) {
            if (!res.json.success) {
                self.setStatus(res.json.error || 'Échec.', 'error');
                toast(res.json.error || 'Suppression impossible', 'error');
                return;
            }
            self.currentSlug = null;
            self.applyServerData(res.json.data);
            self.setStatus(preset.builtin ? 'Preset réinitialisé.' : 'Preset supprimé.', 'ok');
            toast(preset.builtin ? 'Preset réinitialisé' : 'Preset supprimé', 'success');
        }).catch(function () {
            self.setStatus('Erreur réseau.', 'error');
        });
    };

    Editor.prototype.createNew = function (fromSlug) {
        var source = fromSlug ? this.presetBySlug(fromSlug) : null;
        var blocks = source
            ? clone(source.blocks)
            : (this.data.catalog || []).map(function (b) {
                var cards = {};
                (b.cards || []).forEach(function (c) { cards[c.key] = 'shown'; });
                var series = {};
                (b.series || []).forEach(function (s) { series[s.id] = 'shown'; });
                return { id: b.id, visible: true, chart: !!b.has_chart, cards: cards, series: series };
            });

        var base = 'custom';
        var slug = base;
        var n = 1;
        while (this.presetBySlug(slug)) {
            slug = base + '-' + n;
            n++;
        }

        var draft = {
            slug: slug,
            label: source ? (source.label + ' (copie)') : 'Nouveau',
            description: source ? (source.description || '') : '',
            is_default: false,
            show_on_page: true,
            sort_order: 50,
            builtin: false,
            customized: false,
            blocks: blocks
        };
        this.data.presets.push(draft);
        this.fillSelect();
        this.loadPreset(slug);
        this.els.slug.readOnly = false;
        this.markDirty();
        this.setStatus('Brouillon local — cliquez sur Enregistrer pour le persister.', '');
    };

    Editor.prototype.bind = function () {
        var self = this;
        this.els.select.addEventListener('change', function () {
            if (self.dirty && !window.confirm('Abandonner les modifications non enregistrées ?')) {
                self.els.select.value = self.currentSlug;
                return;
            }
            self.loadPreset(self.els.select.value);
        });
        this.els.btnSave.addEventListener('click', function () { self.save(); });
        this.els.btnDel.addEventListener('click', function () { self.remove(); });
        this.els.btnNew.addEventListener('click', function () { self.createNew(null); });
        this.els.btnDup.addEventListener('click', function () { self.createNew(self.currentSlug); });
        ['slug', 'label', 'description', 'sortOrder'].forEach(function (k) {
            self.els[k].addEventListener('input', function () {
                self.markDirty();
                if (k === 'slug') self.updatePreviewLink();
            });
        });
        this.els.isDefault.addEventListener('change', function () { self.markDirty(); });
        this.els.showOnPage.addEventListener('change', function () { self.markDirty(); });
    };

    Editor.prototype.init = function () {
        if (!this.loadBootstrap()) return;
        this.bind();
        var initial = (this.data.presets || []).find(function (p) { return p.is_default; })
            || (this.data.presets || [])[0];
        if (initial) this.loadPreset(initial.slug);
        this.fillSelect();
    };

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function cssEscape(str) {
        if (window.CSS && CSS.escape) return CSS.escape(str);
        return String(str).replace(/"/g, '\\"');
    }

    function boot() {
        var root = document.getElementById('display-presets-editor');
        if (!root) return;
        new Editor(root).init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
