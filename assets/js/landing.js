(() => {
    const shell = document.querySelector('[data-landing]');
    const openButton = document.querySelector('[data-open-overview]');
    const closeButtons = document.querySelectorAll('[data-close-overview]');
    if (!shell || !openButton) return;

    openButton.addEventListener('click', () => shell.classList.add('show-overview'));
    closeButtons.forEach((button) => button.addEventListener('click', (event) => {
        if (window.innerWidth > 900) {
            event.preventDefault();
            shell.classList.remove('show-overview');
        }
    }));

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') shell.classList.remove('show-overview');
    });
})();
