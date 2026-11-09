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

// Rows of the artist's slideshows field (Fieldtypes\Slideshows), like the old Nova list: position
// dropdown, title with categories, the works' thumbnails, number of works, actions (no unlink: a
// slideshow always belongs to its artist). Extends Statamic's own row (inline edit stack); `$parent` is the relationship input.
Statamic.booted(() => {
    const Base = Statamic.$app.component('relationship-input')?.components?.RelatedItem;

    if (!Base) {
        return;
    }

    const { Dropdown, DropdownMenu, DropdownItem, Button, InlineEditForm } = Base.components;
    const h = Vue.h;
    const url = (path) => Statamic.$config.get('cpUrl').replace(/\/$/, '') + '/reps/slideshows/' + path;
    const strike = (text) => h('div', { class: 'text-2xs font-bold uppercase text-gray-500', style: 'text-decoration:line-through' }, text);

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

            const title = valid && this.editable
                ? h('a', { href: item.edit_url, class: 'font-medium text-gray-800 dark:text-gray-200', onClick: (e) => { e.preventDefault(); this.edit(); } }, item.title)
                : h('div', { class: 'font-medium' }, valid ? item.title : __('messages.relationship_item_unavailable'));

            const details = h('div', { style: 'flex:1 1 14rem;min-width:10rem' }, [
                title,
                reps.categories?.length
                    ? h('div', { style: 'margin-top:4px' }, reps.categories.map((category) =>
                        h('span', { class: 'text-gray-500 dark:text-gray-400', style: 'display:inline-block;margin:0 4px 4px 0;padding:1px 4px;border:1px solid currentColor;font-size:10px;line-height:1.3;text-transform:uppercase' }, category)))
                    : null,
                valid && reps.published === false ? strike(__('Published')) : null,
                reps.visible_in_overview === false ? strike(__(reps.overview_label)) : null,
            ]);

            const thumbnails = h('div', { style: 'flex:2 1 16rem;display:flex;flex-wrap:wrap;gap:4px' }, (reps.thumbnails || []).map((thumbnail) =>
                thumbnail.video
                    ? h('video', { src: thumbnail.video, autoplay: true, muted: true, loop: true, playsinline: true, style: 'height:48px;width:auto' })
                    : h('img', { src: thumbnail.image, loading: 'lazy', style: 'height:48px;width:auto' })));

            const works = valid
                ? h('button', {
                    type: 'button',
                    onClick: () => this.edit(),
                    style: `white-space:nowrap;padding:2px 8px;border-radius:4px;font-size:12px;color:#fff;background:${reps.works ? '#2563eb' : '#dc2626'}`,
                }, `${reps.works ?? 0} ${__(reps.works_label ?? '')}`)
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
                ? h('select', {
                    value: this.position,
                    onChange: (e) => this.moveTo(Number(e.target.value)),
                    'aria-label': __('Position'),
                    class: 'border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900',
                    style: 'border-radius:4px;padding:2px 4px;font-size:13px',
                }, this.values.map((id, i) => h('option', { value: i, selected: i === this.position }, i + 1)))
                : null;

            return h('div', {
                class: 'shadow-ui-sm relative flex w-full items-start gap-3 rounded-lg border border-gray-300 bg-white px-3 py-2 mb-1.5 last:mb-0 text-sm dark:border-gray-700 dark:bg-gray-900' + (item.invalid ? ' invalid' : ''),
            }, [
                this.sortable ? h(Vue.resolveComponent('ui-icon'), { name: 'handles', class: 'item-move sortable-handle size-4 cursor-grab text-gray-300 dark:text-gray-700', style: 'margin-top:4px' }) : null,
                position,
                item.status ? h(Vue.resolveComponent('ui-status-indicator'), { status: item.status, style: 'margin-top:6px' }) : null,
                h('div', { style: 'flex:1;display:flex;flex-wrap:wrap;align-items:flex-start;gap:12px' }, [details, thumbnails]),
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
