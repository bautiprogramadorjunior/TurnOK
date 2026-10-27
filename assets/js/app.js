(() => {
    const shell = document.querySelector('[data-app-shell]');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const closeSidebar = () => shell?.classList.remove('sidebar-open');
    toggle?.addEventListener('click', () => shell?.classList.toggle('sidebar-open'));
    backdrop?.addEventListener('click', closeSidebar);

    document.querySelectorAll('[data-alert-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('[data-alert]')?.remove());
    });

    document.querySelectorAll('[data-dialog-open]').forEach((button) => {
        const id = button.getAttribute('data-dialog-open');
        button.addEventListener('click', () => document.getElementById(id)?.showModal());
    });
    document.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => button.closest('dialog')?.close());
    });
    document.querySelectorAll('dialog.modal').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            const rect = dialog.getBoundingClientRect();
            const outside = event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;
            if (outside) dialog.close();
        });
    });

    document.querySelectorAll('[data-confirm]').forEach((element) => {
        element.addEventListener('click', (event) => {
            if (!window.confirm(element.getAttribute('data-confirm') || '¿Confirmar esta acción?')) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-table-filter]').forEach((input) => {
        const table = document.querySelector(input.getAttribute('data-table-filter'));
        if (!table) return;
        input.addEventListener('input', () => {
            const needle = input.value.toLocaleLowerCase('es').trim();
            table.querySelectorAll('tbody tr').forEach((row) => {
                row.hidden = needle !== '' && !row.textContent.toLocaleLowerCase('es').includes(needle);
            });
        });
    });

    document.querySelectorAll('[data-conditional-source]').forEach((source) => {
        const update = () => {
            const value = source.value;
            document.querySelectorAll(`[data-conditional-for="${source.id}"]`).forEach((target) => {
                const values = (target.getAttribute('data-show-on') || '').split(',');
                target.classList.toggle('hidden', !values.includes(value));
                target.querySelectorAll('input,select,textarea').forEach((control) => {
                    control.disabled = !values.includes(value);
                });
            });
        };
        source.addEventListener('change', update);
        update();
    });
})();

(() => {
    const button = document.querySelector('.notification-button');
    if (!button) return;
    const base = window.TURNOK?.baseUrl ? `${window.TURNOK.baseUrl}/` : '';

    const updateUnread = async () => {
        try {
            const response = await fetch(`${base}api/notificaciones.php`, {
                headers: {'Accept': 'application/json'},
                credentials: 'same-origin'
            });
            if (!response.ok) return;
            const data = await response.json();
            let badge = button.querySelector('[data-unread-count]');
            if (data.unread > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'notification-dot';
                    badge.dataset.unreadCount = '';
                    button.appendChild(badge);
                }
                badge.textContent = data.unread > 99 ? '99+' : String(data.unread);
            } else {
                badge?.remove();
            }
        } catch (_) {
            // La interfaz sigue funcionando aunque la actualización en vivo no esté disponible.
        }
    };

    window.setInterval(updateUnread, 60000);
})();
