export function subscribe(options) {
    const { onInit, onUpdate, debug = false, targetOrigin = '*' } = options;
    let currentData = {};
    const log = (...args) => {
        if (debug)
            console.log('[jambo-live-preview]', ...args);
    };
    // Extraire les params de l'URL
    const params = new URLSearchParams(window.location.search);
    const previewToken = params.get('jambo_preview');
    const entryUuid = params.get('jambo_entry');
    const collection = params.get('jambo_collection');
    const locale = params.get('jambo_locale') || 'en';
    const projectUuid = params.get('jambo_project') || '';
    if (!previewToken || !entryUuid || !collection) {
        // Pas en mode preview — retourne un noop
        return () => { };
    }
    let ctx = {
        entryUuid,
        collection,
        locale,
        token: previewToken,
        projectUuid,
    };
    let cleanup = false;
    const messageHandler = async (event) => {
        if (cleanup)
            return;
        if (!event.data || typeof event.data !== 'object')
            return;
        // Verifier l'origine si targetOrigin n'est pas '*'
        if (targetOrigin !== '*' && event.origin !== targetOrigin) {
            log(`Message ignore : origine ${event.origin} non autorisee`);
            return;
        }
        switch (event.data.type) {
            case 'jambo-init':
                log('jambo-init recu', event.data);
                ctx = {
                    entryUuid: event.data.entryUuid || ctx.entryUuid,
                    collection: event.data.collection || ctx.collection,
                    locale: event.data.locale || ctx.locale,
                    token: event.data.previewToken || ctx.token,
                    projectUuid: event.data.projectUuid || ctx.projectUuid,
                };
                try {
                    currentData = await onInit(ctx);
                    onUpdate(currentData);
                    // Signaler que la preview est prete
                    window.parent.postMessage({ type: 'jambo-ready' }, targetOrigin);
                }
                catch (err) {
                    log('Erreur onInit', err);
                    window.parent.postMessage({
                        type: 'jambo-error',
                        error: err?.message || 'Initialisation echouee',
                    }, targetOrigin);
                }
                break;
            case 'jambo-update':
                log('jambo-update recu', event.data);
                if (event.data.fields) {
                    // Shallow merge
                    currentData = { ...currentData, ...event.data.fields };
                    onUpdate(currentData);
                }
                break;
            case 'jambo-navigate':
                log('jambo-navigate recu', event.data);
                if (event.data.locale && event.data.locale !== ctx.locale) {
                    ctx.locale = event.data.locale;
                    window.location.search = new URLSearchParams({
                        ...Object.fromEntries(params),
                        jambo_locale: event.data.locale,
                    }).toString();
                }
                break;
        }
    };
    window.addEventListener('message', messageHandler);
    // Envoyer ready au chargement
    log('envoi jambo-ready');
    window.parent.postMessage({ type: 'jambo-ready' }, targetOrigin);
    return () => {
        cleanup = true;
        window.removeEventListener('message', messageHandler);
    };
}
const INLINE_EDITABLE_TYPES = ['text', 'textarea', 'number', 'email', 'url', 'slug'];
function isInlineEditable(el) {
    const type = el.getAttribute('data-jambo-type') || 'text';
    return INLINE_EDITABLE_TYPES.includes(type);
}
/**
 * Initialize visual editing: hover/click on [data-jambo-field] elements
 * sends postMessage to the admin. Returns a cleanup function.
 */
export function initVisualEditing(options) {
    const { allowedOrigin, inlineEditEnabled = true, debug = false } = options;
    const log = (...args) => {
        if (debug)
            console.log('[jambo-visual-edit]', ...args);
    };
    // Inject CSS
    const style = document.createElement('style');
    style.textContent = `
    [data-jambo-field] { transition: outline 0.2s ease; cursor: pointer; }
    [data-jambo-field].jambo-hover { outline: 2px solid #58a6ff; outline-offset: 2px; }
    .jambo-popover { position: absolute; z-index: 9999; background: #fff; border: 1px solid #d1d5db; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); padding: 12px; min-width: 200px; font-family: -apple-system, sans-serif; }
    .jambo-popover-input { width: 100%; padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; margin-bottom: 8px; outline: none; }
    .jambo-popover-input:focus { border-color: #58a6ff; box-shadow: 0 0 0 2px rgba(88,166,255,0.3); }
    .jambo-popover-apply { padding: 4px 12px; background: #58a6ff; color: #fff; border: none; border-radius: 6px; font-size: 13px; cursor: pointer; }
    .jambo-popover-apply:hover { background: #3b8fd9; }
  `;
    document.head.appendChild(style);
    let currentPopover = null;
    function closePopover() {
        if (currentPopover) {
            currentPopover.remove();
            currentPopover = null;
        }
    }
    function showPopover(el) {
        closePopover();
        if (!inlineEditEnabled || !isInlineEditable(el))
            return;
        const fieldSlug = el.getAttribute('data-jambo-field');
        const currentValue = el.textContent?.trim() || '';
        const rect = el.getBoundingClientRect();
        const popover = document.createElement('div');
        popover.className = 'jambo-popover';
        popover.style.top = `${rect.bottom + window.scrollY + 4}px`;
        popover.style.left = `${rect.left + window.scrollX}px`;
        const input = document.createElement(fieldSlug === 'textarea' ? 'textarea' : 'input');
        input.className = 'jambo-popover-input';
        if (fieldSlug !== 'textarea') {
            const typeMap = { number: 'number', email: 'email', url: 'url' };
            input.type = typeMap[el.getAttribute('data-jambo-type') || ''] || 'text';
        }
        input.value = currentValue;
        const applyBtn = document.createElement('button');
        applyBtn.className = 'jambo-popover-apply';
        applyBtn.textContent = 'Apply';
        applyBtn.onclick = () => {
            const newValue = input.value;
            window.parent.postMessage({
                type: 'jambo-inline-update',
                fieldSlug,
                value: newValue,
            }, allowedOrigin);
            log('inline update:', fieldSlug, newValue);
            closePopover();
        };
        popover.appendChild(input);
        popover.appendChild(applyBtn);
        document.body.appendChild(popover);
        currentPopover = popover;
        input.focus();
        input.addEventListener('keydown', (e) => {
            const ke = e;
            if (ke.key === 'Escape')
                closePopover();
            if (ke.key === 'Enter')
                applyBtn.click();
        });
    }
    // Attach listeners to all [data-jambo-field] elements
    let debounceTimer = null;
    const observer = new MutationObserver(() => {
        if (debounceTimer !== null)
            clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => attachListeners(), 50);
    });
    observer.observe(document.body, { childList: true, subtree: true });
    const cleanupFns = [];
    function attachListeners() {
        const elements = document.querySelectorAll('[data-jambo-field]');
        elements.forEach((el) => {
            const htmlEl = el;
            if (htmlEl.dataset.jamboVisualBound)
                return;
            htmlEl.dataset.jamboVisualBound = '1';
            const onMouseEnter = () => {
                htmlEl.classList.add('jambo-hover');
                window.parent.postMessage({
                    type: 'jambo-hover-field',
                    fieldSlug: htmlEl.getAttribute('data-jambo-field'),
                    collection: htmlEl.getAttribute('data-jambo-collection'),
                }, allowedOrigin);
            };
            const onMouseLeave = () => {
                htmlEl.classList.remove('jambo-hover');
            };
            const onClick = (e) => {
                e.preventDefault();
                e.stopPropagation();
                window.parent.postMessage({
                    type: 'jambo-select-field',
                    fieldSlug: htmlEl.getAttribute('data-jambo-field'),
                    collection: htmlEl.getAttribute('data-jambo-collection'),
                }, allowedOrigin);
                showPopover(htmlEl);
            };
            htmlEl.addEventListener('mouseenter', onMouseEnter);
            htmlEl.addEventListener('mouseleave', onMouseLeave);
            htmlEl.addEventListener('click', onClick);
            cleanupFns.push(() => {
                htmlEl.classList.remove('jambo-hover');
                htmlEl.removeEventListener('mouseenter', onMouseEnter);
                htmlEl.removeEventListener('mouseleave', onMouseLeave);
                htmlEl.removeEventListener('click', onClick);
                delete htmlEl.dataset.jamboVisualBound;
            });
        });
    }
    attachListeners();
    // Listen for admin highlight-clear
    const handler = (event) => {
        if (!event.data || typeof event.data !== 'object')
            return;
        if (event.origin !== allowedOrigin)
            return;
        if (event.data.type === 'jambo-highlight-clear') {
            document.querySelectorAll('.jambo-hover').forEach(el => el.classList.remove('jambo-hover'));
        }
        if (event.data.type === 'jambo-popover-close') {
            closePopover();
        }
    };
    window.addEventListener('message', handler);
    return () => {
        if (debounceTimer !== null)
            clearTimeout(debounceTimer);
        observer.disconnect();
        cleanupFns.forEach(fn => fn());
        style.remove();
        closePopover();
        window.removeEventListener('message', handler);
    };
}
