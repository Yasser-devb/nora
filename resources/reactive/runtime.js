(() => {
    'use strict';

    const initialized = Symbol.for('nora.reactive.runtime.initialized');
    if (document[initialized]) {
        return;
    }
    document[initialized] = true;

    const rootSelector = '[data-nora-root]';
    let formErrorSequence = 0;

    function findRoot(element) {
        return element && typeof element.closest === 'function' ? element.closest(rootSelector) : null;
    }

    function announce(root, message) {
        const status = root.querySelector('[data-nora-status]');
        if (status) {
            status.textContent = message;
        }
    }

    function isCompatible(current, fresh) {
        if (current.nodeType !== fresh.nodeType) {
            return false;
        }
        if (current.nodeType !== Node.ELEMENT_NODE) {
            return true;
        }
        return current.namespaceURI === fresh.namespaceURI
            && current.tagName === fresh.tagName
            && current.getAttribute('data-nora-key') === fresh.getAttribute('data-nora-key');
    }

    function patchNode(current, fresh) {
        if (current.nodeType === Node.TEXT_NODE || current.nodeType === Node.COMMENT_NODE) {
            if (current.nodeValue !== fresh.nodeValue) {
                current.nodeValue = fresh.nodeValue;
            }
            return;
        }

        const inputState = current instanceof HTMLInputElement
            ? { value: current.value, checked: current.checked }
            : null;
        const selection = current === document.activeElement
            && (current instanceof HTMLInputElement || current instanceof HTMLTextAreaElement)
            && typeof current.selectionStart === 'number'
            ? [current.selectionStart, current.selectionEnd, current.selectionDirection]
            : null;

        for (const attribute of Array.from(current.attributes)) {
            if (!fresh.hasAttribute(attribute.name)) {
                if (
                    current instanceof HTMLDialogElement
                    && attribute.name === 'open'
                    && !fresh.hasAttribute('open')
                ) {
                    continue;
                }
                current.removeAttribute(attribute.name);
            }
        }
        for (const attribute of Array.from(fresh.attributes)) {
            if (current.getAttribute(attribute.name) !== attribute.value) {
                current.setAttribute(attribute.name, attribute.value);
            }
        }

        if (inputState) {
            current.value = inputState.value;
            current.checked = inputState.checked;
        }
        if (current instanceof HTMLTextAreaElement) {
            const value = current.value;
            patchChildren(current, Array.from(fresh.childNodes));
            current.value = value;
        } else {
            patchChildren(current, Array.from(fresh.childNodes));
        }
        if (selection && typeof current.setSelectionRange === 'function') {
            try {
                current.setSelectionRange(selection[0], selection[1], selection[2]);
            } catch (_) {
                // Some input types do not support selection ranges.
            }
        }
    }

    function patchChildren(parent, freshChildren) {
        const oldChildren = Array.from(parent.childNodes);
        const keyed = new Map();
        for (const child of oldChildren) {
            if (child.nodeType === Node.ELEMENT_NODE && child.hasAttribute('data-nora-key')) {
                keyed.set(child.getAttribute('data-nora-key'), child);
            }
        }
        const used = new Set();
        let cursor = parent.firstChild;

        for (const fresh of freshChildren) {
            const key = fresh.nodeType === Node.ELEMENT_NODE
                ? fresh.getAttribute('data-nora-key')
                : null;
            let candidate = key !== null ? keyed.get(key) : null;
            if (candidate && (!isCompatible(candidate, fresh) || used.has(candidate))) {
                candidate = null;
            }
            if (!candidate && key === null) {
                for (const old of oldChildren) {
                    if (
                        !used.has(old)
                        && !(old.nodeType === Node.ELEMENT_NODE && old.hasAttribute('data-nora-key'))
                        && isCompatible(old, fresh)
                    ) {
                        candidate = old;
                        break;
                    }
                }
            }
            if (!candidate) {
                candidate = fresh.cloneNode(true);
            } else {
                used.add(candidate);
                patchNode(candidate, fresh);
            }

            if (candidate !== cursor) {
                parent.insertBefore(candidate, cursor);
            }
            cursor = candidate.nextSibling;
        }

        while (cursor) {
            const next = cursor.nextSibling;
            parent.removeChild(cursor);
            cursor = next;
        }
    }

    function applyUpdate(root, html, revision) {
        const beforeX = window.scrollX;
        const beforeY = window.scrollY;
        const active = document.activeElement;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        const freshChildren = Array.from(wrapper.childNodes);
        const status = root.querySelector('[data-nora-status]');
        if (status) {
            freshChildren.push(status);
        }
        patchChildren(root, freshChildren);
        root.dataset.noraRevision = String(revision);
        syncModalStates(root);
        const activeDialog = active instanceof HTMLElement ? active.closest('dialog') : null;
        const openDialog = root.querySelector('dialog[open]');
        if (
            active instanceof HTMLElement
            && active.isConnected
            && (!activeDialog || activeDialog.open)
            && (!openDialog || openDialog.contains(active))
        ) {
            active.focus({ preventScroll: true });
        }
        window.scrollTo(beforeX, beforeY);
    }

    function syncModalStates(root = document) {
        root.querySelectorAll('dialog[data-nora-modal-state]').forEach(dialog => {
            if (dialog.dataset.noraModalState === 'open' && !dialog.open) {
                dialog.showModal();
            } else if (dialog.dataset.noraModalState === 'closed' && dialog.open) {
                dialog.close();
            }
        });
    }

    function clearFormErrors(form) {
        form.querySelectorAll('[data-nora-field-error], [data-nora-form-summary]').forEach(node => node.remove());
        form.querySelectorAll('[data-nora-field]').forEach(field => {
            field.removeAttribute('aria-invalid');
            const errorId = field.dataset.noraErrorId;
            if (errorId) {
                const describedBy = (field.getAttribute('aria-describedby') || '')
                    .split(/\s+/)
                    .filter(value => value && value !== errorId);
                if (describedBy.length) {
                    field.setAttribute('aria-describedby', describedBy.join(' '));
                } else {
                    field.removeAttribute('aria-describedby');
                }
                delete field.dataset.noraErrorId;
            }
        });
    }

    function showFormErrors(form, errors) {
        clearFormErrors(form);
        const summary = document.createElement('div');
        summary.dataset.noraFormSummary = '';
        summary.setAttribute('role', 'alert');
        summary.setAttribute('tabindex', '-1');
        summary.textContent = 'Please correct the highlighted fields.';
        form.prepend(summary);

        let firstInvalid = null;
        if (errors && typeof errors === 'object' && !Array.isArray(errors)) {
            for (const field of form.querySelectorAll('[data-nora-field]')) {
                const name = field.getAttribute('name');
                const fieldErrors = name && Array.isArray(errors[name]) ? errors[name] : null;
                if (!fieldErrors || fieldErrors.length === 0) {
                    continue;
                }
                const error = document.createElement('span');
                error.id = `nora-field-error-${++formErrorSequence}`;
                error.dataset.noraFieldError = '';
                error.setAttribute('role', 'alert');
                error.textContent = fieldErrors.filter(message => typeof message === 'string').join(' ');
                field.insertAdjacentElement('afterend', error);
                field.setAttribute('aria-invalid', 'true');
                const describedBy = (field.getAttribute('aria-describedby') || '')
                    .split(/\s+/)
                    .filter(Boolean);
                field.setAttribute('aria-describedby', [...describedBy, error.id].join(' '));
                field.dataset.noraErrorId = error.id;
                firstInvalid ||= field;
            }
        }
        firstInvalid?.focus({ preventScroll: true });

        return summary.textContent;
    }

    function collectFormArguments(form) {
        const values = {};
        for (const field of form.querySelectorAll('[data-nora-field]')) {
            if (!(field instanceof HTMLInputElement
                || field instanceof HTMLSelectElement
                || field instanceof HTMLTextAreaElement)) {
                throw new Error('The form field binding is unsupported.');
            }
            if (field.disabled) {
                continue;
            }
            const name = field.getAttribute('name');
            if (!name || field.dataset.noraField !== name) {
                throw new Error('The form field binding is invalid.');
            }
            if (field instanceof HTMLInputElement && field.type === 'file') {
                throw new Error('Reactive forms do not accept file fields.');
            }
            if (
                (field instanceof HTMLInputElement
                    && ['button', 'image', 'reset', 'submit'].includes(field.type))
                || (field instanceof HTMLSelectElement && field.multiple)
            ) {
                throw new Error('The form field binding is unsupported.');
            }
            if (field instanceof HTMLInputElement && field.type === 'radio') {
                if (field.checked) {
                    values[name] = field.value;
                }
                continue;
            }
            if (Object.prototype.hasOwnProperty.call(values, name)) {
                throw new Error('A reactive form field is bound more than once.');
            }
            values[name] = field instanceof HTMLInputElement && field.type === 'checkbox'
                ? field.checked
                : field.value;
        }

        return values;
    }

    async function dispatch(root, control, form = null) {
        if (root.dataset.noraBusy === 'true') {
            return;
        }

        let argumentsObject = {};
        if (form) {
            try {
                argumentsObject = collectFormArguments(form);
            } catch (_) {
                announce(root, 'The form could not be submitted.');
                form.dataset.noraState = 'failed';
                return;
            }
        } else {
            const encodedArguments = control.getAttribute('data-nora-args');
            if (encodedArguments !== null) {
                try {
                    argumentsObject = JSON.parse(encodedArguments);
                } catch (_) {
                    announce(root, 'This action could not be prepared.');
                    return;
                }
            }
        }
        if (
            argumentsObject === null
            || Array.isArray(argumentsObject)
            || typeof argumentsObject !== 'object'
        ) {
            announce(root, 'This action could not be prepared.');
            return;
        }

        const token = document.querySelector('meta[name="nora-csrf"]')?.content;
        if (!token) {
            announce(root, 'The security token is unavailable. Reload the page.');
            if (form) {
                form.dataset.noraState = 'failed';
            }
            return;
        }

        root.dataset.noraBusy = 'true';
        root.setAttribute('aria-busy', 'true');
        if (form) {
            form.dataset.noraState = 'pending';
            form.setAttribute('aria-busy', 'true');
        }
        announce(root, '');
        try {
            let response;
            try {
                response = await fetch('/_nora/action', {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    redirect: 'error',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': token
                    },
                    body: JSON.stringify({
                        page: root.dataset.noraPage,
                        action: form ? form.getAttribute('data-nora-action') : control.getAttribute('data-nora-action'),
                        revision: Number(root.dataset.noraRevision),
                        arguments: argumentsObject,
                        ...((form || control).closest('[data-nora-child-id]')
                            ? { component: (form || control).closest('[data-nora-child-id]').dataset.noraChildId }
                            : {})
                    })
                });
            } catch (_) {
                announce(root, 'The connection failed. The action was not retried.');
                if (form) {
                    form.dataset.noraState = 'failed';
                }
                return;
            }
            let result;
            try {
                result = await response.json();
            } catch (_) {
                announce(root, response.ok
                    ? 'The server returned an invalid reactive response.'
                    : 'The action could not be completed.');
                if (form) {
                    form.dataset.noraState = 'failed';
                }
                return;
            }
            if (typeof result.html === 'string' && Number.isInteger(result.revision)) {
                try {
                    applyUpdate(root, result.html, result.revision);
                } catch (_) {
                    announce(root, 'The update could not be applied. Reload to synchronize.');
                    if (form) {
                        form.dataset.noraState = 'failed';
                    }
                    return;
                }
            }
            if (!response.ok) {
                const message = form && response.status === 422
                    ? showFormErrors(form, result.errors)
                    : (typeof result.error === 'string'
                    ? result.error
                    : 'The action could not be completed.');
                announce(root, message);
                if (form) {
                    form.dataset.noraState = response.status === 422 ? 'invalid' : 'failed';
                }
                return;
            }
            if (typeof result.html !== 'string' || !Number.isInteger(result.revision)) {
                announce(root, 'The server returned an invalid reactive response.');
                if (form) {
                    form.dataset.noraState = 'failed';
                }
                return;
            }
            if (form) {
                clearFormErrors(form);
                form.dataset.noraState = 'success';
                announce(root, form.getAttribute('data-nora-success') || 'The form was submitted.');
            }
        } finally {
            delete root.dataset.noraBusy;
            root.removeAttribute('aria-busy');
            if (form) {
                form.removeAttribute('aria-busy');
            }
        }
    }

    document.addEventListener('submit', event => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form || !form.hasAttribute('data-nora-form')) {
            return;
        }
        const root = findRoot(form);
        if (!root) {
            return;
        }
        event.preventDefault();
        void dispatch(root, form, form);
    });

    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }
        const modalOpener = target.closest('[data-nora-modal-open]');
        if (modalOpener) {
            const dialog = document.getElementById(modalOpener.dataset.noraModalOpen);
            if (dialog instanceof HTMLDialogElement && !dialog.open) {
                dialog.showModal();
            }
            return;
        }
        const toastDismiss = target.closest('[data-nora-toast-dismiss]');
        if (toastDismiss) {
            if (toastDismiss.hasAttribute('data-nora-action')) {
                const root = findRoot(toastDismiss);
                if (root) {
                    void dispatch(root, toastDismiss);
                }
            } else {
                toastDismiss.closest('[data-nora-toast]')?.remove();
            }
            return;
        }
        const dismiss = target.closest('[data-nora-dismiss]');
        if (dismiss) {
            dismiss.closest('.nora-alert')?.remove();
            return;
        }
        const searchClear = target.closest('[data-nora-search-clear]');
        if (searchClear) {
            const search = searchClear.parentElement?.querySelector('input[type="search"]');
            if (search instanceof HTMLInputElement) {
                search.value = '';
                search.dispatchEvent(new Event('input', { bubbles: true }));
                search.focus();
            }
            return;
        }
        const modalCloser = target.closest('[data-nora-modal-close]');
        if (modalCloser) {
            const dialog = modalCloser.closest('dialog');
            if (dialog instanceof HTMLDialogElement && dialog.open) {
                dialog.close();
            }
            return;
        }
        const tab = target.closest('[data-nora-tab-target]');
        if (tab) {
            selectTab(tab);
            return;
        }
        if (!target.closest('[data-nora-dropdown]')) {
            document.querySelectorAll('[data-nora-dropdown][open]').forEach(dropdown => {
                dropdown.removeAttribute('open');
            });
        }
        const control = target?.closest('[data-nora-action]');
        if (!control) {
            return;
        }
        const form = target.closest('form[data-nora-form]');
        if (
            form
            && (control === form
                || (control instanceof HTMLButtonElement && control.type === 'submit' && control.form === form))
        ) {
            return;
        }
        event.preventDefault();
        const root = findRoot(control);
        if (!root || root.dataset.noraBusy === 'true') {
            return;
        }
        void dispatch(root, control);
    });

    function showAvatarFallback(image) {
        image.hidden = true;
        const fallback = image.parentElement?.querySelector('[data-nora-avatar-fallback]');
        if (fallback) {
            fallback.hidden = false;
        }
    }

    document.addEventListener('error', event => {
        const image = event.target instanceof HTMLImageElement
            ? event.target.closest('[data-nora-avatar-image]')
            : null;
        if (image) {
            showAvatarFallback(image);
        }
    }, true);
    document.querySelectorAll('img[data-nora-avatar-image]').forEach(image => {
        if (image.complete && image.naturalWidth === 0) {
            showAvatarFallback(image);
        }
    });

    document.addEventListener('close', event => {
        const dialog = event.target instanceof HTMLDialogElement ? event.target : null;
        if (!dialog || dialog.dataset.noraModalState !== 'open') {
            return;
        }
        dialog.dataset.noraModalState = 'closed';
        const closeControl = dialog.querySelector('[data-nora-modal-close-action]');
        const root = findRoot(dialog);
        if (closeControl && root && root.dataset.noraBusy !== 'true') {
            void dispatch(root, closeControl);
        }
    }, true);

    function selectTab(tab) {
        const group = tab.closest('[data-nora-tabs]');
        if (!group || tab.matches(':disabled')) {
            return;
        }
        const panelId = tab.getAttribute('aria-controls');
        const selectedPanel = panelId ? document.getElementById(panelId) : null;
        if (!selectedPanel || !group.contains(selectedPanel)) {
            return;
        }
        group.querySelector('[role="tablist"]')?.querySelectorAll('[role="tab"]').forEach(candidate => {
            const selected = candidate === tab;
            candidate.setAttribute('aria-selected', selected ? 'true' : 'false');
            candidate.setAttribute('tabindex', selected ? '0' : '-1');
        });
        Array.from(group.children)
            .filter(panel => panel.getAttribute('role') === 'tabpanel')
            .forEach(panel => {
                panel.hidden = panel !== selectedPanel;
            });
    }

    document.addEventListener('keydown', event => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) {
            return;
        }
        const tab = target.closest('[data-nora-tab-target]');
        if (tab) {
            const group = tab.closest('[data-nora-tabs]');
            const tabList = group?.querySelector('[role="tablist"]');
            const tabs = tabList
                ? Array.from(tabList.querySelectorAll('[role="tab"]:not(:disabled)'))
                : [];
            if (tabs.length === 0) {
                return;
            }
            const index = tabs.indexOf(tab);
            let nextIndex = index;
            if (event.key === 'ArrowRight') {
                nextIndex = (index + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft') {
                nextIndex = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                nextIndex = 0;
            } else if (event.key === 'End') {
                nextIndex = tabs.length - 1;
            } else {
                return;
            }
            event.preventDefault();
            tabs[nextIndex].focus();
            selectTab(tabs[nextIndex]);
            return;
        }

        const dropdown = target.closest('[data-nora-dropdown][open]');
        if (dropdown && event.key === 'Escape') {
            dropdown.removeAttribute('open');
            dropdown.querySelector('summary')?.focus();
        }
    });

    syncModalStates();
})();
