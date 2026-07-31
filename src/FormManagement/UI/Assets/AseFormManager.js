/**
 * AseFormManager.js — Vanilla JS progressive enhancement for ASE forms.
 */
(function () {
    if (window.__aseFormManager) return;
    window.__aseFormManager = true;

    const ICON = {
        eye: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>`,
        eyeOff: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>`,
        copy: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>`,
        check: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
    };

    function initSensitiveFields(root) {
        root.querySelectorAll('[data-ase-sensitive]').forEach(function (wrapper) {
            const valueEl = wrapper.querySelector('[data-ase-sensitive-value]');
            const submitEl = wrapper.querySelector('[data-ase-sensitive-submit]');
            const newInput = wrapper.querySelector('[data-ase-sensitive-new-input]');
            const previewEl = wrapper.querySelector('[data-ase-sensitive-preview]');

            const toggleBtn = wrapper.querySelector('[data-ase-sensitive-toggle]');
            const copyBtn = wrapper.querySelector('[data-ase-sensitive-copy]');

            // Remove changeBtn if exists (unifying with Toggle)
            const changeBtn = wrapper.querySelector('[data-ase-sensitive-change]');
            if (changeBtn) changeBtn.hidden = true;

            if (!valueEl || !previewEl || !submitEl || !newInput) return;
            const fullValue = valueEl.value;

            let isRevealed = false;

            if (toggleBtn) {
                toggleBtn.innerHTML = ICON.eye;
                toggleBtn.onclick = function () {
                    isRevealed = !isRevealed;
                    
                    if (isRevealed) {
                        previewEl.hidden = true;
                        newInput.hidden = false;
                        newInput.value = fullValue;
                        newInput.focus();
                        toggleBtn.innerHTML = ICON.eyeOff;
                        toggleBtn.title = "Ocultar y cancelar cambios";
                    } else {
                        previewEl.hidden = false;
                        newInput.hidden = true;
                        newInput.value = '';
                        submitEl.value = ''; // Cancel any unsaved change
                        toggleBtn.innerHTML = ICON.eye;
                        toggleBtn.title = "Mostrar y editar";
                    }
                };
            }

            if (copyBtn) {
                copyBtn.innerHTML = ICON.copy;
                copyBtn.onclick = function () {
                    if (!navigator.clipboard) return;
                    navigator.clipboard.writeText(isRevealed ? newInput.value : fullValue).then(function () {
                        copyBtn.innerHTML = ICON.check;
                        setTimeout(() => { copyBtn.innerHTML = ICON.copy; }, 2000);
                    });
                };
            }

            if (newInput && submitEl) {
                newInput.oninput = () => { 
                    // Only mark as changed if value is different from original
                    if (newInput.value !== fullValue) {
                        submitEl.value = newInput.value;
                    } else {
                        submitEl.value = '';
                    }
                };
            }
        });
    }

    function initPasswordFields(root) {
        root.querySelectorAll('[data-ase-password-wrapper]').forEach(function (wrapper) {
            const input = wrapper.querySelector('input');
            const toggleBtn = wrapper.querySelector('[data-ase-password-toggle]');
            if (!input || !toggleBtn) return;
            toggleBtn.innerHTML = ICON.eye;
            toggleBtn.onclick = function () {
                const isPass = input.type === 'password';
                input.type = isPass ? 'text' : 'password';
                toggleBtn.innerHTML = isPass ? ICON.eyeOff : ICON.eye;
            };
        });
    }

    function initConditionalFields(form) {
        const conditionals = form.querySelectorAll('[data-condition]');
        const evaluate = () => {
            conditionals.forEach(el => {
                try {
                    const cond = JSON.parse(el.dataset.condition);
                    const trigger = form.querySelector(`[name="${cond.field}"]`);
                    if (!trigger) return;
                    const val = trigger.type === 'checkbox' ? trigger.checked : trigger.value;
                    const match = Array.isArray(cond.values) ? cond.values.includes(val) : val == cond.value;
                    el.hidden = !match;
                    el.querySelectorAll('input, select, textarea').forEach(i => i.disabled = !match);
                } catch (_) { }
            });
        };

        const triggers = new Set();
        conditionals.forEach(el => {
            try { triggers.add(JSON.parse(el.dataset.condition).field); } catch (_) { }
        });

        triggers.forEach(name => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el) el.addEventListener(el.type === 'checkbox' ? 'change' : 'input', evaluate);
        });

        evaluate();
    }

    function boot() {
        document.querySelectorAll('[data-ase-gateway-form]').forEach(form => {
            initSensitiveFields(form);
            initPasswordFields(form);
            initConditionalFields(form);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
