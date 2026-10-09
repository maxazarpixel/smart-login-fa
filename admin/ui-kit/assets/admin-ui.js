/* ==========================================================================
   AzarPixel WP Admin UI Kit — behaviour layer (v1.0)
   --------------------------------------------------------------------------
   Dependency-free. Drives every interactive part of the kit purely from
   data-attributes, so the markup stays declarative and the same JS works for
   every plugin:

     data-apx-tab="key"            sidebar nav item          -> shows [data-apx-panel="key"]
     data-apx-panel="key"          panel container
     data-apx-goto="key"           any element; jumps to a tab
     data-apx-subtabs="group"      sub-tab bar
       data-apx-subtab="key"         sub-tab button          -> shows the matching subpanel
     data-apx-subpanel="key" data-apx-subgroup="group"
     data-apx-collapse             collapsible toggle button
     data-apx-field                any input tracked for unsaved changes
     data-apx-dep-master="name"    checkbox that gates a block
     data-apx-dep="name"           block shown while the master is checked
     data-apx-popup="fieldName"    button that opens the list modal for a textarea

   Saving: POSTs a flat {name: value} object to window.APX_UI.restUrl + '/settings'
   with the X-WP-Nonce header. Override window.APX_UI.save(payload) -> Promise
   if a plugin needs different transport.
   ========================================================================== */
(function () {
    'use strict';

    var CFG = window.APX_UI || (window.APX_UI = {});
    var root = document.querySelector('.apx-wrap');
    if (!root) { return; }

    var STORE_KEY = 'apx-ui:' + (CFG.slug || 'default') + ':tab';

    /* ── helpers ─────────────────────────────────────────────────────── */
    var TXT = CFG.i18n || {};
    function t(key, fallback, n) {
        var str = TXT[key] || fallback;
        return n === undefined ? str : str.replace('%d', n);
    }
    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $$(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }

    function fieldValue(el) {
        if (el.type === 'checkbox') { return el.checked ? '1' : '0'; }
        return el.value;
    }

    /* ── 1. Tabs ─────────────────────────────────────────────────────── */
    function switchTab(key, sub) {
        var panel = $('[data-apx-panel="' + key + '"]');
        if (!panel) { return; }

        $$('[data-apx-panel]').forEach(function (p) { p.style.display = 'none'; });
        panel.style.display = 'block';

        $$('[data-apx-tab]').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-apx-tab') === key);
        });

        var crumb = $('[data-apx-crumb]');
        var active = $('[data-apx-tab="' + key + '"]');
        if (crumb && active) { crumb.textContent = (active.textContent || '').trim(); }

        try { localStorage.setItem(STORE_KEY, key); } catch (e) {}
        if (location.hash.slice(1) !== key) { history.replaceState(null, '', '#' + key); }

        if (sub) { switchSubTab(key, sub); }
        panel.scrollIntoView({ block: 'start' });
    }

    function switchSubTab(group, key) {
        $$('[data-apx-subgroup="' + group + '"]').forEach(function (p) {
            p.hidden = p.getAttribute('data-apx-subpanel') !== key;
        });
        var bar = $('[data-apx-subtabs="' + group + '"]');
        if (!bar) { return; }
        $$('[data-apx-subtab]', bar).forEach(function (b) {
            b.classList.toggle('apx-subtab--active', b.getAttribute('data-apx-subtab') === key);
        });
    }

    document.addEventListener('click', function (e) {
        var nav = e.target.closest('[data-apx-tab]');
        if (nav) { e.preventDefault(); switchTab(nav.getAttribute('data-apx-tab')); return; }

        var goto = e.target.closest('[data-apx-goto]');
        if (goto) {
            e.preventDefault();
            switchTab(goto.getAttribute('data-apx-goto'), goto.getAttribute('data-apx-goto-sub') || '');
            return;
        }

        var sub = e.target.closest('[data-apx-subtab]');
        if (sub) {
            var bar = sub.closest('[data-apx-subtabs]');
            switchSubTab(bar.getAttribute('data-apx-subtabs'), sub.getAttribute('data-apx-subtab'));
            return;
        }

        var collapse = e.target.closest('[data-apx-collapse]');
        if (collapse) {
            var box = collapse.parentNode;
            var body = $('.apx-collapsible__body', box);
            var open = body.hidden;
            body.hidden = !open;
            box.classList.toggle('apx-collapsible--open', open);
            var use = $('use', collapse);
            if (use) { use.setAttribute('href', open ? '#apx-i-chev-down' : '#apx-i-chev-right'); }
        }
    });

    /* ── 2. Dependency blocks ────────────────────────────────────────── */
    function syncDeps() {
        $$('[data-apx-dep-master]').forEach(function (master) {
            var name = master.getAttribute('data-apx-dep-master');
            var on = master.type === 'checkbox' ? master.checked : !!master.value;
            $$('[data-apx-dep="' + name + '"]').forEach(function (block) {
                // A block may name the master values it is shown for
                // (data-apx-dep-value="a,b"); otherwise any truthy master shows it.
                var want = block.getAttribute('data-apx-dep-value');
                var show = want ? want.split(',').indexOf(master.value) !== -1 : on;
                block.style.display = show ? '' : 'none';
            });
        });
    }

    /* ── 3. Dirty tracking + save bar ────────────────────────────────── */
    var savebar = $('.apx-savebar');
    var toastEl = $('.apx-toast');
    var initial = {};
    var dirty = {};

    function snapshot() {
        initial = {};
        $$('[data-apx-field]').forEach(function (el) { initial[el.name] = fieldValue(el); });
        dirty = {};
        renderSavebar();
    }

    function renderSavebar() {
        if (!savebar) { return; }
        var n = Object.keys(dirty).length;
        savebar.classList.toggle('apx-savebar--visible', n > 0);
        savebar.setAttribute('aria-hidden', n === 0 ? 'true' : 'false');
        var count = $('.apx-savebar__count', savebar);
        if (count) { count.textContent = n === 1 ? t('unsavedOne', 'You have 1 unsaved change.') : t('unsavedMany', 'You have %d unsaved changes.', n); }
    }

    function markDirty(el) {
        var v = fieldValue(el);
        if (v === initial[el.name]) { delete dirty[el.name]; } else { dirty[el.name] = v; }
        renderSavebar();
    }

    root.addEventListener('change', function (e) {
        var el = e.target.closest('[data-apx-field]');
        if (!el) { return; }
        markDirty(el);
        syncDeps();
    });
    root.addEventListener('input', function (e) {
        var el = e.target.closest('[data-apx-field]');
        if (el && el.type !== 'checkbox') { markDirty(el); }
    });

    function toast(msg, kind) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.className = 'apx-toast apx-toast--visible apx-toast--' + (kind || 'ok');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { toastEl.classList.remove('apx-toast--visible'); }, 3200);
    }
    CFG.toast = toast;

    CFG.save = CFG.save || function (payload) {
        return fetch(CFG.restUrl.replace(/\/$/, '') + '/settings', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.restNonce },
            body: JSON.stringify(payload)
        }).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.json();
        });
    };

    if (savebar) {
        $('.apx-savebar__save', savebar).addEventListener('click', function () {
            var btn = this;
            if (!Object.keys(dirty).length) { return; }
            btn.disabled = true;
            btn.classList.add('apx-savebar__save--busy');
            CFG.save(dirty)
                .then(function () { snapshot(); toast(t('saved', 'Settings saved.'), 'ok'); })
                .catch(function () { toast(t('saveFailed', 'Could not save — please try again.'), 'error'); })
                .then(function () {
                    btn.disabled = false;
                    btn.classList.remove('apx-savebar__save--busy');
                });
        });

        $('.apx-savebar__discard', savebar).addEventListener('click', function () {
            $$('[data-apx-field]').forEach(function (el) {
                if (!(el.name in initial)) { return; }
                if (el.type === 'checkbox') { el.checked = initial[el.name] === '1'; }
                else { el.value = initial[el.name]; }
            });
            dirty = {};
            renderSavebar();
            syncDeps();
            refreshPopupLabels();
            toast(t('discarded', 'Changes discarded.'), 'muted');
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (Object.keys(dirty).length) { e.preventDefault(); e.returnValue = ''; }
    });

    /* ── 4. List modal ───────────────────────────────────────────────── */
    var overlay = $('.apx-popup-overlay');
    var activeTextarea = null;

    function countEntries(v) {
        return (v || '').split('\n').map(function (s) { return s.trim(); })
                        .filter(Boolean).length;
    }

    function refreshPopupLabels() {
        $$('[data-apx-popup]').forEach(function (btn) {
            var ta = $('[name="' + btn.getAttribute('data-apx-popup') + '"]');
            if (!ta) { return; }
            var n = countEntries(ta.value);
            btn.textContent = n === 1 ? t('editListOne', 'Edit list (1 entry)') : t('editListMany', 'Edit list (%d entries)', n);
        });
    }

    function closePopup() {
        if (overlay) { overlay.hidden = true; }
        activeTextarea = null;
    }

    if (overlay) {
        document.addEventListener('click', function (e) {
            var open = e.target.closest('[data-apx-popup]');
            if (open) {
                activeTextarea = $('[name="' + open.getAttribute('data-apx-popup') + '"]');
                $('.apx-popup-title', overlay).textContent = open.getAttribute('data-apx-popup-title') || t('editList', 'Edit list');
                var help = $('.apx-popup-help', overlay);
                help.textContent = open.getAttribute('data-apx-popup-help') || '';
                help.hidden = !help.textContent;
                var ta = $('.apx-popup-textarea', overlay);
                ta.value = activeTextarea ? activeTextarea.value : '';
                ta.placeholder = open.getAttribute('data-apx-popup-placeholder') || '';
                overlay.hidden = false;
                ta.focus();
                return;
            }
            if (e.target === overlay || e.target.closest('.apx-popup-close, .apx-popup-cancel')) {
                closePopup();
                return;
            }
            if (e.target.closest('.apx-popup-save')) {
                if (activeTextarea) {
                    activeTextarea.value = $('.apx-popup-textarea', overlay).value;
                    markDirty(activeTextarea);
                    refreshPopupLabels();
                }
                closePopup();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !overlay.hidden) { closePopup(); }
        });
    }

    /* ── 5. Boot ─────────────────────────────────────────────────────── */
    var start = location.hash.slice(1);
    if (!start) { try { start = localStorage.getItem(STORE_KEY) || ''; } catch (e) {} }
    if (!start || !$('[data-apx-panel="' + start + '"]')) {
        var first = $('[data-apx-panel]');
        start = first ? first.getAttribute('data-apx-panel') : '';
    }
    if (start) { switchTab(start); }

    syncDeps();
    snapshot();
    refreshPopupLabels();
})();
