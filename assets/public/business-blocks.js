(function () {
    'use strict';

    function clearErrors(form) {
        form.querySelectorAll(
            '[data-form-field]'
        ).forEach(function (field) {
            field.classList.remove(
                'is-error'
            );
        });

        form.querySelectorAll(
            '[data-form-error]'
        ).forEach(function (node) {
            node.textContent = '';
        });

        form.querySelectorAll(
            '[aria-invalid="true"]'
        ).forEach(function (control) {
            control.removeAttribute(
                'aria-invalid'
            );
        });
    }

    function markFieldError(
        form,
        key,
        text
    ) {
        var field =
            form.querySelector(
                '[data-form-field="'
                + CSS.escape(
                    String(key)
                )
                + '"]'
            );

        if (!field) {
            return null;
        }

        field.classList.add(
            'is-error'
        );

        var error =
            field.querySelector(
                '[data-form-error]'
            );

        if (error) {
            error.textContent =
                String(text || '');
        }

        field.querySelectorAll(
            'input, select, textarea'
        ).forEach(function (control) {
            control.setAttribute(
                'aria-invalid',
                'true'
            );
        });

        return field;
    }

    function firstFocusable(field) {
        if (!field) {
            return null;
        }

        return field.querySelector(
            'input:not([type="hidden"]),'
            + 'select,textarea'
        );
    }

    function errorMessage(code) {
        var messages = {
            FORM_VALIDATION_FAILED:
                'Проверьте отмеченные поля.',
            FORM_RATE_LIMIT:
                'Слишком много отправок. Попробуйте позже.',
            FORM_NOT_AVAILABLE:
                'Форма сейчас недоступна.',
            FORM_FIELDS_EMPTY:
                'В форме не настроены поля.',
            SESSION_EXPIRED:
                'Сессия устарела. Обновите страницу и повторите отправку.'
        };

        return messages[
            String(code || '')
        ] || 'Не удалось отправить форму. Попробуйте ещё раз.';
    }

    document.addEventListener(
        'submit',
        async function (event) {
            var form =
                event.target.closest(
                    '[data-sb-public-form]'
                );

            if (!form) {
                return;
            }

            event.preventDefault();

            var message =
                form.querySelector(
                    '[data-form-message]'
                );
            var submit =
                form.querySelector(
                    '[type="submit"]'
                );

            clearErrors(form);

            if (message) {
                message.hidden = true;
                message.textContent = '';
                message.classList.remove(
                    'is-error'
                );
            }

            if (submit) {
                submit.disabled = true;
                submit.setAttribute(
                    'aria-busy',
                    'true'
                );
            }

            try {
                var response =
                    await fetch(
                        form.action,
                        {
                            method: 'POST',
                            body:
                                new FormData(
                                    form
                                ),
                            credentials:
                                'same-origin',
                            headers: {
                                'X-Requested-With':
                                    'XMLHttpRequest'
                            }
                        }
                    );

                var data = {};

                try {
                    data =
                        await response.json();
                } catch (parseError) {
                    data = {
                        ok: false,
                        error:
                            'FORM_SUBMIT_FAILED'
                    };
                }

                if (
                    !response.ok
                    || !data.ok
                ) {
                    var errors =
                        data.fieldErrors
                        && typeof data
                            .fieldErrors
                            === 'object'
                            ? data.fieldErrors
                            : {};

                    var firstInvalid =
                        null;

                    Object.keys(
                        errors
                    ).forEach(
                        function (key) {
                            var field =
                                markFieldError(
                                    form,
                                    key,
                                    errors[key]
                                );

                            if (
                                !firstInvalid
                                && field
                            ) {
                                firstInvalid =
                                    field;
                            }
                        }
                    );

                    if (firstInvalid) {
                        var focusable =
                            firstFocusable(
                                firstInvalid
                            );

                        if (focusable) {
                            focusable.focus({
                                preventScroll:
                                    true
                            });
                        }

                        firstInvalid
                            .scrollIntoView({
                                behavior:
                                    'smooth',
                                block:
                                    'center'
                            });
                    }

                    throw new Error(
                        data.error
                        || 'FORM_SUBMIT_FAILED'
                    );
                }

                form.reset();

                if (message) {
                    message.textContent =
                        message.getAttribute(
                            'data-success-text'
                        )
                        || 'Спасибо! Заявка отправлена.';
                    message.hidden = false;
                    message.classList.remove(
                        'is-error'
                    );
                }
            } catch (error) {
                if (message) {
                    message.textContent =
                        errorMessage(
                            error
                            && error.message
                        );
                    message.classList.add(
                        'is-error'
                    );
                    message.hidden = false;
                }
            } finally {
                if (submit) {
                    submit.disabled = false;
                    submit.removeAttribute(
                        'aria-busy'
                    );
                }
            }
        }
    );
})();
