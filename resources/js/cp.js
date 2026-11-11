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

// "Copy Link" buttons of the artist list (Fieldtypes\CopyLinks): [{ label, text }]
Statamic.booting(() => {
    Statamic.$components.register('reps_copy_links-fieldtype-index', {
        props: ['value', 'values', 'handle'],
        render() {
            return Vue.h('div', { class: 'flex flex-col items-start gap-1' }, (this.value || []).map((link) =>
                Vue.h(Vue.resolveComponent('ui-button'), {
                    size: 'xs',
                    variant: 'primary',
                    text: link.label,
                    onClick: (event) => {
                        event.stopPropagation();
                        navigator.clipboard.writeText(link.text).then(() => Statamic.$toast.success(__('Copied to clipboard') + ': ' + link.text));
                    },
                })));
        },
    });
});

// Rows of the artist's slideshows field (Fieldtypes\Slideshows), like the old Nova list: position
// dropdown, title with categories, the works' thumbnails, number of works, actions (no unlink: a
// slideshow always belongs to its artist). Built from Statamic's UI components (ui.statamic.dev);
// extends Statamic's own row (inline edit stack). `$parent` is the relationship input.
Statamic.booted(() => {
    const Base = Statamic.$app.component('relationship-input')?.components?.RelatedItem;

    if (!Base) {
        return;
    }

    const { Dropdown, DropdownMenu, DropdownItem, Button, InlineEditForm } = Base.components;
    const h = Vue.h;
    const url = (path) => Statamic.$config.get('cpUrl').replace(/\/$/, '') + '/reps/slideshows/' + path;

    Statamic.$app.component('reps-slideshow-item', {
        extends: Base,

        computed: {
            reps() {
                return this.item.reps || {};
            },
            values() {
                return this.$parent?.value || [];
            },
            position() {
                return this.values.indexOf(this.item.id);
            },
        },

        methods: {
            moveTo(index) {
                const values = [...this.values];
                values.splice(index, 0, values.splice(this.position, 1)[0]);
                this.$parent.update(values);
            },

            toggle(action) {
                this.$axios
                    .post(url(this.item.id + '/toggle'), { action })
                    .then(({ data }) => {
                        this.item.reps = data.reps;
                        this.item.published = data.reps.published;
                        this.item.status = data.status;
                    })
                    .catch(() => this.$toast.error(__('Something went wrong')));
            },

            // after editing in the stack: categories and works may have changed too
            itemUpdated(data) {
                Base.methods.itemUpdated.call(this, data);
                this.$axios.get(url(this.item.id)).then(({ data }) => (this.item.reps = data.reps));
            },
        },

        render() {
            const item = this.item;
            const reps = this.reps;
            const valid = !item.invalid;
            const ui = (name, props, slots) => h(Vue.resolveComponent('ui-' + name), props, slots);

            const title = valid && this.editable
                ? h('a', { href: item.edit_url, class: 'font-medium text-gray-800 dark:text-gray-200', onClick: (e) => { e.preventDefault(); this.edit(); } }, item.title)
                : h('div', { class: 'font-medium' }, valid ? item.title : __('messages.relationship_item_unavailable'));

            const badges = [
                ...(reps.categories || []).map((category) => ui('badge', { size: 'sm', text: category })),
                valid && reps.published === false ? ui('badge', { size: 'sm', color: 'red', text: __('Draft') }) : null,
                reps.visible_in_overview === false ? ui('badge', { size: 'sm', color: 'red', text: __('Hidden in the artist overview') }) : null,
            ].filter(Boolean);

            const details = h('div', { class: 'flex flex-col gap-1.5', style: 'flex:1 1 14rem;min-width:10rem' }, [
                title,
                badges.length ? h('div', { class: 'flex flex-wrap gap-1' }, badges) : null,
            ]);

            const thumbnails = h('div', { class: 'flex flex-wrap gap-1', style: 'flex:2 1 16rem' }, (reps.thumbnails || []).map((thumbnail) =>
                thumbnail.video
                    ? h('video', { src: thumbnail.video, autoplay: true, muted: true, loop: true, playsinline: true, class: 'h-12 w-auto rounded-sm' })
                    : h('img', { src: thumbnail.image, loading: 'lazy', class: 'h-12 w-auto rounded-sm' })));

            const works = valid
                ? ui('button', {
                    size: 'xs',
                    variant: reps.works ? 'primary' : 'danger',
                    text: `${reps.works ?? 0} ${__(reps.works_label ?? '')}`,
                    onClick: () => this.edit(),
                })
                : null;

            const actions = this.readOnly ? null : h(Dropdown, null, {
                trigger: () => h(Button, { icon: 'dots', variant: 'ghost', size: 'xs', 'aria-label': __('Open dropdown menu') }),
                default: () => h(DropdownMenu, null, () => [
                    this.editable && valid ? h(DropdownItem, { text: __('Edit'), onClick: () => this.edit() }) : null,
                    this.editable && valid ? h(DropdownItem, { text: reps.published ? __('Unpublish') : __('Publish'), onClick: () => this.toggle('published') }) : null,
                    this.editable && valid && reps.visible_in_overview !== null && reps.visible_in_overview !== undefined
                        ? h(DropdownItem, { text: __(reps.visible_in_overview ? 'Hide in the artist overview' : 'Show in the artist overview again'), onClick: () => this.toggle('visible_in_overview') })
                        : null,
                    // only to clean up a slideshow that no longer exists
                    valid ? null : h(DropdownItem, { text: __('Unlink'), variant: 'destructive', onClick: () => this.$emit('removed') }),
                ]),
            });

            const position = this.sortable && this.values.length > 1
                ? h('div', { class: 'shrink-0', style: 'width:4rem' }, ui('select', {
                    size: 'xs',
                    modelValue: this.position,
                    options: this.values.map((id, i) => ({ label: String(i + 1), value: i })),
                    'aria-label': __('Position'),
                    'onUpdate:modelValue': (index) => this.moveTo(index),
                }))
                : null;

            return h('div', {
                class: 'shadow-ui-sm relative flex w-full items-start gap-3 rounded-lg border border-gray-300 bg-white px-3 py-2 mb-1.5 last:mb-0 text-sm dark:border-gray-700 dark:bg-gray-900' + (item.invalid ? ' invalid' : ''),
            }, [
                this.sortable ? ui('icon', { name: 'handles', class: 'item-move sortable-handle mt-2 size-4 cursor-grab text-gray-300 dark:text-gray-700' }) : null,
                position,
                item.status ? ui('status-indicator', { status: item.status, class: 'mt-2.5' }) : null,
                h('div', { class: 'flex flex-1 flex-wrap items-start gap-3' }, [details, thumbnails]),
                works,
                actions,
                this.isEditing ? h(InlineEditForm, {
                    item,
                    component: this.formComponent,
                    componentProps: this.formComponentProps,
                    stackSize: this.formStackSize,
                    onUpdated: (data) => this.itemUpdated(data),
                    onClosed: () => (this.isEditing = false),
                }) : null,
            ]);
        },
    });
});

// Multi-site with translatable fields (Translations\Translations): every language is edited in the
// default site's form, so the "Working in" panel of entries and the site dropdown of the globals
// (switching to the other site's entry) are hidden.
Statamic.booted(() => {
    if (!Statamic.$config.get('repsTranslatableFields')) {
        return;
    }

    const hide = () => {
        document.querySelectorAll('[data-ui-panel-header]').forEach((header) => {
            if (header.textContent.trim() === __('Working In')) {
                header.closest('[data-ui-panel]').style.display = 'none';
            }
        });

        // the site dropdown in the header of a global set
        if (/\/globals\/[^/]+\/?$/.test(location.pathname)) {
            document.querySelectorAll('[data-ui-header] [data-ui-combobox]').forEach((combobox) => {
                combobox.parentElement.parentElement.style.display = 'none';
            });
        }
    };

    new MutationObserver(hide).observe(document.body, { childList: true, subtree: true });
    hide();
});

// Work rows of a slideshow (the `works` replicator), like the old Nova list (see
// Listeners\AdjustBlueprints::workRows): the collapsed row shows the file large (videos play),
// then the title and a badge per flag or category that is set (the flags themselves are switches
// on top of the expanded row). Statamic's own fieldtypes are extended only for fields marked in
// the blueprint.
Statamic.booted(() => {
    const app = Statamic.$app;
    const escape = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
    const badge = (text) => `<span class="reps-badge inline-block rounded-sm border border-gray-300 px-1 text-2xs font-medium uppercase leading-normal text-gray-700 dark:border-gray-600 dark:text-gray-300">${escape(text)}</span>`;
    const extend = (name, preview) => {
        const Base = app.component(name);
        if (!Base) return;
        app.component(name, {
            extends: Base,
            computed: {
                replicatorPreview() {
                    const own = this.showFieldPreviews ? preview.call(this) : undefined;
                    return own === undefined ? Base.computed.replicatorPreview.call(this) : own;
                },
            },
        });
    };

    extend('assets-fieldtype', function () {
        if (!this.config.reps_large_preview) return undefined;

        return replicatorPreviewHtml(this.assets.map((asset) => {
            const media = asset.extension?.toLowerCase() === 'mp4'
                ? `<video src="${escape(asset.url)}" autoplay muted loop playsinline class="reps-work-media inline-block h-12 w-auto rounded-sm align-middle"></video>`
                : (asset.isImage || asset.isSvg ? `<img src="${escape(asset.thumbnail)}" class="reps-work-media inline-block h-12 w-auto rounded-sm align-middle" alt="">` : '');
            return media || escape(asset.basename);
        }).join(' '));
    });

    extend('toggle-fieldtype', function () {
        if (!this.config.reps_flag) return undefined;
        // reps_flag may hold a shorter label for the badge
        const label = typeof this.config.reps_flag === 'string' ? this.config.reps_flag : this.config.display;
        return this.value ? replicatorPreviewHtml(badge(__(label))) : null;
    });

    extend('relationship-fieldtype', function () {
        if (!this.config.reps_flag) return undefined;
        const titles = (this.meta?.data || []).filter((item) => (this.value || []).includes(item.id)).map((item) => item.title);
        const label = typeof this.config.reps_flag === 'string' ? this.config.reps_flag : this.config.display;
        return titles.length ? replicatorPreviewHtml(badge(`${__(label)}: ${titles.join(', ')}`)) : null;
    });
});
