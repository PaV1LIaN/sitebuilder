(function () {
    'use strict';
    document.addEventListener('submit', async function (event) {
        var form = event.target.closest('[data-sb-public-form]');
        if (!form) return;
        event.preventDefault();
        var message = form.querySelector('[data-form-message]');
        var submit = form.querySelector('[type="submit"]');
        form.querySelectorAll('[data-form-error]').forEach(function (node) { node.textContent = ''; });
        if (message) { message.hidden = true; message.classList.remove('is-error'); }
        if (submit) submit.disabled = true;
        try {
            var response = await fetch(form.action, {method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}});
            var data = await response.json();
            if (!response.ok || !data.ok) {
                var errors = data.fieldErrors || {};
                Object.keys(errors).forEach(function (key) {
                    var field = form.querySelector('[data-form-field="' + key + '"] [data-form-error]');
                    if (field) field.textContent = errors[key];
                });
                throw new Error(data.error || 'FORM_SUBMIT_FAILED');
            }
            form.reset();
            if (message) {
                message.textContent = message.getAttribute('data-success-text') || 'Спасибо! Заявка отправлена.';
                message.hidden = false;
            }
        } catch (error) {
            if (message) {
                message.textContent = error.message === 'FORM_RATE_LIMIT' ? 'Слишком много отправок. Попробуйте позже.' : 'Не удалось отправить форму. Проверьте поля и повторите.';
                message.classList.add('is-error');
                message.hidden = false;
            }
        } finally {
            if (submit) submit.disabled = false;
        }
    });
})();
