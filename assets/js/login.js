(() => {
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.parentElement?.querySelector('input');
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Ocultar' : 'Ver';
        });
    });

    const password = document.querySelector('[data-password-form] #password');
    const rules = document.querySelector('[data-password-rules]');
    if (!password || !rules) return;

    const checks = {
        length: (value) => value.length >= 8,
        upper: (value) => /[A-ZÁÉÍÓÚÑ]/u.test(value),
        lower: (value) => /[a-záéíóúñ]/u.test(value),
        number: (value) => /\d/.test(value),
    };

    const update = () => {
        Object.entries(checks).forEach(([name, test]) => {
            rules.querySelector(`[data-rule="${name}"]`)?.classList.toggle('valid', test(password.value));
        });
    };
    password.addEventListener('input', update);
    update();
})();
