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

// Position select of a CP listing (Cp\PositionColumn, `cp.position_columns`): [position, total,
// url]; changing it moves the entry right away, then the listing reloads (Statamic's listing
// provides `refresh` as "ListingContext").
Statamic.booting(() => {
    Statamic.$components.register('reps_position-fieldtype-index', {
        props: ['value', 'values', 'handle'],
        inject: { listing: { from: 'ListingContext', default: null } },
        data: () => ({ saving: false }),
        methods: {
            move(position) {
                if (position === this.value.position) return;
                this.saving = true;
                this.$axios
                    .post(this.value.url, { position })
                    .then(() => (this.listing?.refresh ? this.listing.refresh() : location.reload()))
                    .catch(() => this.$toast.error(__('Something went wrong')))
                    .finally(() => (this.saving = false));
            },
        },
        render() {
            if (!this.value) return null;

            return Vue.h('div', { style: 'width:4.5rem', onClick: (event) => event.stopPropagation() }, Vue.h(Vue.resolveComponent('ui-select'), {
                size: 'xs',
                modelValue: this.value.position,
                disabled: this.saving,
                options: Array.from({ length: this.value.total }, (_, i) => ({ label: String(i + 1), value: i + 1 })),
                'aria-label': __('Position'),
                'onUpdate:modelValue': (position) => this.move(position),
            }));
        },
    });
});

// Rows of the artist's slideshows field (Fieldtypes\Slideshows), like the old Nova list: position
// dropdown, title with categories, the works' thumbnails, number of works, actions (no unlink: a
// slideshow always belongs to its artist; "Delete" deletes it after a confirmation modal). Built from Statamic's UI components (ui.statamic.dev);
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

        data: () => ({ confirmingDelete: false, deleting: false }),

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

            // deleted right away; the server also takes it off the artist's saved list
            // (RemoveDeletedChildren), the form drops the row
            destroy() {
                this.deleting = true;
                this.$axios
                    .delete(url(this.item.id))
                    .then(() => {
                        this.confirmingDelete = false;
                        this.$toast.success(__('Deleted'));
                        this.$emit('removed');
                    })
                    .catch(() => this.$toast.error(__('Something went wrong')))
                    .finally(() => (this.deleting = false));
            },

            refresh() {
                const params = this.reps.category ? { category: this.reps.category } : {};
                this.$axios.get(url(this.item.id), { params }).then(({ data }) => (this.item.reps = data.reps));
            },

            // after editing in the stack: categories and works may have changed too
            itemUpdated(data) {
                Base.methods.itemUpdated.call(this, data);
                this.refresh();
            },
        },

        // a slideshow just created with "Add slideshow" comes from the create form's response,
        // without the row's details (works, thumbnails, categories): load them
        mounted() {
            if (!this.item.reps && !this.item.invalid) {
                this.refresh();
            }
        },

        render() {
            const item = this.item;
            const reps = this.reps;
            const valid = !item.invalid;
            const ui = (name, props, slots) => h(Vue.resolveComponent('ui-' + name), props, slots);

            const title = valid && this.editable
                ? h('a', { href: item.edit_url, class: 'font-medium text-gray-800 dark:text-gray-200', onClick: (e) => { e.preventDefault(); this.edit(); } }, item.title)
                : h('div', { class: 'font-medium' }, valid ? item.title : __('messages.relationship_item_unavailable'));

            // in a category's slideshows field: no badges, only "Edit" (see Fieldtypes\CategorySlideshows)
            const inCategory = !!reps.category;

            const badges = inCategory ? [] : [
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

            const works = valid && item.reps
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
                    this.editable && valid && !inCategory ? h(DropdownItem, { text: reps.published ? __('Unpublish') : __('Publish'), onClick: () => this.toggle('published') }) : null,
                    this.editable && valid && !inCategory && reps.visible_in_overview !== null && reps.visible_in_overview !== undefined
                        ? h(DropdownItem, { text: __(reps.visible_in_overview ? 'Hide in the artist overview' : 'Show in the artist overview again'), onClick: () => this.toggle('visible_in_overview') })
                        : null,
                    valid && reps.can_delete ? h(DropdownItem, { text: __('Delete'), variant: 'destructive', onClick: () => (this.confirmingDelete = true) }) : null,
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
                this.confirmingDelete ? h(Vue.resolveComponent('confirmation-modal'), {
                    open: true,
                    title: __('Delete Entry'),
                    bodyText: __('Are you sure you want to delete this entry?'),
                    buttonText: __('Delete'),
                    danger: true,
                    busy: this.deleting,
                    onConfirm: () => this.destroy(),
                    onCancel: () => (this.confirmingDelete = false),
                }) : null,
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

// The artist's slideshows field (Fieldtypes\Slideshows): Statamic's relationship field with an
// "Add slideshow" button (label from the field's meta) on the right above the list (adds at position
// 1) and below it (adds at the end), and no "Link" button – a slideshow always belongs to its
// artist. The local `RelationshipInput`
// replaces the global one in the inherited render function; Statamic's own button row is hidden
// (canSelectOrCreate) and the inherited render is wrapped. `$parent` is the fieldtype.
Statamic.booted(() => {
    const Fieldtype = Statamic.$app.component('relationship-fieldtype');
    const Input = Statamic.$app.component('relationship-input');
    const CreateButton = Input?.components?.CreateButton;

    if (!Fieldtype || !Input || !CreateButton) {
        return;
    }

    const h = Vue.h;

    const SlideshowsInput = {
        extends: Input,
        computed: {
            canSelectOrCreate: () => false,
            canAdd() {
                return !this.readOnly && !this.maxItemsReached && this.canCreate && this.creatables.length > 0;
            },
        },
        methods: {
            // the top button adds the new slideshow at position 1, the bottom one at the end; the
            // saved slideshow already joined the artist at the end (SyncRelations), so the top
            // one is moved first on the server too (an artist without an id yet: 404, ignored)
            itemCreatedFirst(item) {
                if (this.readOnly) return;
                this.$emit('item-data-updated', [...this.data, item]);
                this.update([item.id, ...(this.value || [])]);
                this.$axios.post(Statamic.$config.get('cpUrl').replace(/\/$/, '') + '/reps/slideshows/' + item.id + '/first').catch(() => {});
            },
            addButton(key) {
                return h('div', { key, class: 'flex justify-end' }, h(CreateButton, {
                    creatables: this.creatables,
                    component: this.formComponent,
                    componentProps: this.formComponentProps,
                    icon: this.icon,
                    text: __('Add :label', { label: __(this.$parent?.meta?.reps_label || 'Slideshow') }),
                    site: this.site,
                    stackSize: this.formStackSize,
                    onCreated: (item) => (key === 'top' ? this.itemCreatedFirst(item) : this.itemCreated(item)),
                }));
            },
        },
        render(...args) {
            const list = Input.render.apply(this, args);
            const ready = !this.initializing && this.canAdd;

            return h('div', { class: 'flex flex-col gap-3' }, [
                ready && this.items.length ? this.addButton('top') : null,
                list,
                ready ? this.addButton('bottom') : null,
            ]);
        },
    };

    Statamic.$app.component('reps_slideshows-fieldtype', {
        extends: Fieldtype,
        components: { RelationshipInput: SlideshowsInput },
    });
});

// The category's slideshows (Fieldtypes\CategorySlideshows): Statamic's relationship field with the
// artist's rows, but only sortable – no link/create buttons, no "Unlink all" (which categories a
// slideshow is in is set in the slideshow). The local `RelationshipInput` replaces the global one
// in the inherited render function.
Statamic.booted(() => {
    const Fieldtype = Statamic.$app.component('relationship-fieldtype');
    const Input = Statamic.$app.component('relationship-input');

    if (!Fieldtype || !Input) {
        return;
    }

    const SortOnlyInput = {
        extends: Input,
        computed: {
            canSelectOrCreate: () => false,
        },
    };

    Statamic.$app.component('reps_category_slideshows-fieldtype', {
        extends: Fieldtype,
        components: { RelationshipInput: SortOnlyInput },
        computed: {
            canCreate: () => false,
            internalFieldActions: () => [],
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

// Assets cells of the artist list with cp.listing_videos (Fieldtypes\ListingAssets): videos play
// like in the old Nova index, everything else goes through Statamic's own assets cell.
Statamic.booting(() => {
    Statamic.$components.register('reps_listing_assets-fieldtype-index', {
        props: ['value', 'values', 'handle'],
        render() {
            const assets = (this.value?.assets || []).slice(0, this.value?.total > 6 ? 5 : 6);
            const cells = assets.map((asset) => asset.is_video
                ? Vue.h('a', { key: asset.id, href: asset.url, target: '_blank' }, [
                    Vue.h('video', { src: asset.url, autoplay: true, muted: true, loop: true, playsinline: true, preload: 'metadata', class: '-my-1 h-8 max-w-3xs' }),
                ])
                : Vue.h(Vue.resolveComponent('assets-fieldtype-index'), { key: asset.id, handle: this.handle, values: this.values, value: { total: 1, assets: [asset] } }));

            if (this.value?.total > 6) {
                cells.push(Vue.h('span', { class: '-my-1 flex h-8 min-w-8 items-center justify-center px-1.5 font-mono text-gray-600 dark:text-gray-400' }, ' + ' + (this.value.total - 5)));
            }

            return Vue.h('div', { class: 'flex text-2xs gap-2' }, cells);
        },
    });
});

// "Add images" of a slideshow (Fieldtypes\AddWorks): Statamic's assets field, whose files leave
// it as soon as they're uploaded (one at a time, in the order chosen) or chosen: each becomes a
// work of the set meta.set with that set's defaults, appended to the replicator meta.works_field
// like its own "Add work" does (set meta first, then the value), collapsed. Replicators only read
// their collapsed state once, so they follow meta.collapsed when someone else changes it.
Statamic.booted(() => {
    const app = Statamic.$app;
    const clone = (value) => JSON.parse(JSON.stringify(value ?? null));
    const sameIds = (a, b) => JSON.stringify(a ?? []) === JSON.stringify(b ?? []);
    const uid = () => Math.random().toString(36).slice(2, 12) + Date.now().toString(36);

    const Replicator = app.component('replicator-fieldtype');
    if (Replicator) {
        app.component('replicator-fieldtype', {
            extends: Replicator,
            watch: {
                'meta.collapsed'(collapsed) {
                    if (collapsed && !sameIds(collapsed, this.collapsed)) this.collapsed = clone(collapsed);
                },
            },
        });
    }

    const Assets = app.component('assets-fieldtype');
    if (!Assets) return;

    app.component('reps_add_works-fieldtype', {
        extends: Assets,
        watch: {
            // deep: uploads are pushed onto the array
            assets: {
                deep: true,
                handler(assets) {
                    if (this.initializing || !assets.length) return;
                    const added = [...assets];
                    this.assets = [];
                    // in order, also when the set's defaults are still loading for an earlier file
                    this.repsQueue = (this.repsQueue || Promise.resolve())
                        .then(() => this.repsAddWorks(added))
                        .catch(() => this.$toast.error(__('Something went wrong')));
                },
            },
        },
        methods: {
            // the value stays empty: otherwise the base field's value watcher loads the files again
            update() {},
            async repsSet(field, handle) {
                const meta = this.publishContainer.meta[field] || {};
                if (meta.new?.[handle]) return { new: meta.new[handle], defaults: meta.defaults?.[handle] || {} };
                this.repsSetCache ??= {};
                this.repsSetCache[handle] ??= this.$axios.post(cp_url('fieldtypes/replicator/set'), {
                    token: this.publishContainer.blueprint.token,
                    reference: this.publishContainer.reference,
                    field,
                    set: handle,
                }).then((response) => response.data);
                return this.repsSetCache[handle];
            },
            async repsAddWorks(assets) {
                const field = this.meta.works_field || 'works';
                const handle = this.meta.set || 'image';
                const data = await this.repsSet(field, handle);
                const container = this.injectedPublishContainer;

                const sets = assets.map((asset) => ({
                    ...clone(data.defaults),
                    _id: uid(),
                    type: handle,
                    enabled: true,
                    file: [asset.id],
                }));

                const meta = this.publishContainer.meta[field] || {};
                const existing = { ...(meta.existing || {}) };
                sets.forEach((set, i) => {
                    const setMeta = clone(data.new) || {};
                    // the asset is known already: no request per thumbnail
                    if (setMeta.file) setMeta.file = { ...setMeta.file, data: [assets[i]] };
                    existing[set._id] = setMeta;
                });
                const values = this.publishContainer.values[field] || [];
                const collapsed = meta.collapsed ? [...meta.collapsed, ...sets.map((set) => set._id)] : meta.collapsed;

                container.setFieldMeta(field, { ...meta, existing, collapsed });
                await this.$nextTick();
                container.setFieldValue(field, [...values, ...sets]);
            },
        },
    });
});

// The slideshow of a news item (Fieldtypes\ArtistSlideshows): Statamic's entries select, asking
// only for the slideshows of the artist chosen in meta.artist_field (`?artist=`, none without an
// artist). Statamic's select loads its options once and doesn't watch its URL, so the field is
// rebuilt (new key) when the artist changes; the slideshow is cleared then, it belonged to the
// other (or no) artist.
Statamic.booted(() => {
    const app = Statamic.$app;
    const Fieldtype = app.component('relationship-fieldtype');
    // the fieldtype may be extended already (work rows above): its template and URL are on a base
    const inherited = (get) => { for (let c = Fieldtype; c; c = c.extends) if (get(c)) return get(c); };
    const render = inherited((c) => c.render);
    const selectionsUrl = inherited((c) => c.computed?.selectionsUrl);
    if (!render || !selectionsUrl) return;

    app.component('reps_artist_slideshows-fieldtype', {
        extends: Fieldtype,
        computed: {
            repsArtist() {
                const value = this.publishContainer.values?.[this.meta.artist_field || 'artist'];
                return (Array.isArray(value) ? value[0] : value) || '';
            },
            selectionsUrl() {
                const url = selectionsUrl.call(this);
                return `${url}&artist=${encodeURIComponent(this.repsArtist)}`;
            },
        },
        watch: {
            repsArtist() {
                if ((this.value || []).length) this.update([]);
            },
        },
        render(...args) {
            return Vue.cloneVNode(render.apply(this, args), { key: `artist-${this.repsArtist}` });
        },
    });
});
