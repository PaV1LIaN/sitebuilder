function openTemplateModal() {
    if (!IS_BITRIX_ADMIN) {
        alert('Создавать шаблоны может только администратор Битрикса');
        return;
    }

    var modal = document.getElementById('saveTemplateModal');
    if (!modal) return;

    var nameInput = document.getElementById('templateNameInput');
    var descInput = document.getElementById('templateDescriptionInput');
    var message = document.getElementById('templateMessage');

    if (nameInput && !nameInput.value) {
        var siteName = state.site && state.site.name ? state.site.name : 'Сайт';
        nameInput.value = siteName;
    }

    if (descInput && !descInput.value) {
        descInput.value = '';
    }

    if (message) {
        message.hidden = true;
        message.textContent = '';
        message.className = 'sb-template-message';
    }

    modal.hidden = false;

    setTimeout(function () {
        if (nameInput) {
            nameInput.focus();
            nameInput.select();
        }
    }, 50);
}

function closeTemplateModal() {
    var modal = document.getElementById('saveTemplateModal');
    if (!modal) return;

    modal.hidden = true;
}

function setTemplateMessage(text, type) {
    var message = document.getElementById('templateMessage');
    if (!message) return;

    message.hidden = !text;
    message.textContent = text || '';
    message.className = 'sb-template-message' + (type ? ' is-' + type : '');
}

async function createTemplateFromSite() {
    if (!IS_BITRIX_ADMIN) {
        alert('Создавать шаблоны может только администратор Битрикса');
        return;
    }

    var nameInput = document.getElementById('templateNameInput');
    var descInput = document.getElementById('templateDescriptionInput');
    var btn = document.getElementById('createTemplateBtn');

    var name = nameInput ? String(nameInput.value || '').trim() : '';
    var description = descInput ? String(descInput.value || '').trim() : '';

    if (!name) {
        alert('Введите название шаблона');
        if (nameInput) nameInput.focus();
        return;
    }

    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Создаю...';
    }

    setTemplateMessage('Создаю шаблон...', 'info');

    try {
        await api('template.createFromSite', {
            siteId: siteId,
            name: name,
            description: description
        });

        setTemplateMessage('Шаблон создан', 'success');

        setTimeout(function () {
            closeTemplateModal();
        }, 350);
    } catch (e) {
        var message = e && (e.message || e.error) ? (e.message || e.error) : 'UNKNOWN_ERROR';
        setTemplateMessage('Не удалось создать шаблон: ' + message, 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Создать шаблон';
        }
    }
}

async function deleteSite() {
    var siteName = state.site && state.site.name ? state.site.name : ('siteId ' + siteId);

    if (!confirm('Удалить сайт "' + siteName + '"?')) {
        return;
    }

    if (!confirm('Подтверди удаление ещё раз. Это действие нельзя отменить через интерфейс.')) {
        return;
    }

    await api('site.delete', {
        id: siteId
    });

    alert('Сайт удалён');
    window.location.href = BASE_PATH + '/index.php';
}