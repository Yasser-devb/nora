(() => {
    'use strict';

    const rootSelector = '[data-nora-root]';

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

    async function dispatch(root, control) {
        if (root.dataset.noraBusy === 'true') {
            return;
        }

        let argumentsObject = {};
        const encodedArguments = control.getAttribute('data-nora-args');
        if (encodedArguments !== null) {
            try {
                argumentsObject = JSON.parse(encodedArguments);
            } catch (_) {
                announce(root, 'This action could not be prepared.');
                return;
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
            return;
        }

        root.dataset.noraBusy = 'true';
        root.setAttribute('aria-busy', 'true');
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
                        action: control.getAttribute('data-nora-action'),
                        revision: Number(root.dataset.noraRevision),
                        arguments: argumentsObject
                    })
                });
            } catch (_) {
                announce(root, 'The connection failed. The action was not retried.');
                return;
            }
            let result;
            try {
                result = await response.json();
            } catch (_) {
                announce(root, response.ok
                    ? 'The server returned an invalid reactive response.'
                    : 'The action could not be completed.');
                return;
            }
            if (typeof result.html === 'string' && Number.isInteger(result.revision)) {
                try {
                    applyUpdate(root, result.html, result.revision);
                } catch (_) {
                    announce(root, 'The update could not be applied. Reload to synchronize.');
                    return;
                }
            }
            if (!response.ok) {
                announce(root, typeof result.error === 'string'
                    ? result.error
                    : 'The action could not be completed.');
                return;
            }
            if (typeof result.html !== 'string' || !Number.isInteger(result.revision)) {
                announce(root, 'The server returned an invalid reactive response.');
            }
        } finally {
            delete root.dataset.noraBusy;
            root.removeAttribute('aria-busy');
        }
    }

    document.addEventListener('click', event => {
        const target = event.target instanceof Element ? event.target : null;
        const control = target?.closest('[data-nora-action]');
        if (!control) {
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
