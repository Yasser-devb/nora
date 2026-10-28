(() => {
    'use strict';

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
        if (active instanceof HTMLElement && active.isConnected) {
            active.focus({ preventScroll: true });
        }
        window.scrollTo(beforeX, beforeY);
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
})();
