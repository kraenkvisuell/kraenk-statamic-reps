// CP additions of the addon. Plain JS (no build): Statamic exposes Vue and its component registry.

// listing cells of reps_html fields: the value is HTML built on the server (Cp\ArtistListing)
Statamic.booting(() => {
    Statamic.$components.register('reps_html-fieldtype-index', {
        props: ['value', 'values', 'handle'],
        render() {
            return Vue.h('div', { innerHTML: this.value || '' });
        },
    });
});

// "Copy Link" buttons in listings: <button data-reps-copy="a:123">
document.addEventListener(
    'click',
    (event) => {
        const button = event.target.closest('[data-reps-copy]');

        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        navigator.clipboard.writeText(button.dataset.repsCopy).then(() => {
            Statamic.$toast?.success(__('Copied to clipboard') + ': ' + button.dataset.repsCopy);
        });
    },
    true,
);
