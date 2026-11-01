// Counts down to a repository's next check. Reads the parent's data-next each tick,
// since Livewire re-renders update that attribute but skip this wire:ignore'd element.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('countdown', () => ({
        text: '',
        init() {
            this.tick();
            this.timer = setInterval(() => this.tick(), 1000);
        },
        destroy() {
            clearInterval(this.timer);
        },
        tick() {
            const left = Math.round(Number(this.$el.parentElement.dataset.next) - Date.now() / 1000);

            if (left <= 0) {
                this.text = 'checking';
                return;
            }

            const h = Math.floor(left / 3600);
            const m = Math.floor((left % 3600) / 60);
            const s = String(left % 60).padStart(2, '0');
            this.text = h ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`;
        },
    }));

    // The model and effort popover on a dashboard row. The draft lives here, not in Livewire, so the
    // dashboard's poll can't reset it. Opening re-reads the row's saved values from the data attributes.
    window.Alpine.data('claudeSettings', (id) => ({
        model: '',
        effort: '',
        saving: false,
        error: '',
        reset() {
            this.model = this.$root.dataset.model;
            this.effort = this.$root.dataset.effort;
            this.error = '';
        },
        async save() {
            this.saving = true;
            this.error = '';

            try {
                this.error = (await this.$wire.saveClaudeSettings(id, this.model, this.effort)) ?? '';
            } catch {
                this.error = 'Couldn’t save. Try again.';
            }

            this.saving = false;

            if (!this.error) {
                this.$refs.popover.hidePopover();
            }
        },
    }));

    // The repository settings form: the side nav's current section and the unsaved-changes footer.
    // `fields` maps each Livewire property to its section's title.
    window.Alpine.data('repoSettings', (fields) => ({
        saved: {},
        current: 'repo',
        init() {
            this.snapshot();
            // The current section is the last one whose top has passed the top quarter of the screen
            const sections = [...this.$root.querySelectorAll('[data-section]')];
            this.update = () => {
                const passed = sections.filter((el) => el.getBoundingClientRect().top < window.innerHeight / 4);
                this.current = (passed.at(-1) ?? sections[0]).dataset.section;
            };
            // Capture, so it also hears the modal's own scrolling
            document.addEventListener('scroll', this.update, { capture: true, passive: true });
            this.update();
        },
        destroy() {
            document.removeEventListener('scroll', this.update, { capture: true });
        },
        snapshot() {
            this.saved = Object.fromEntries(Object.keys(fields).map((field) => [field, String(this.$wire[field] ?? '')]));
        },
        get changed() {
            return Object.keys(fields).filter((field) => String(this.$wire[field] ?? '') !== this.saved[field]);
        },
        get summary() {
            const sections = [...new Set(this.changed.map((field) => fields[field]))];
            const count = this.changed.length;
            return `${count} unsaved ${count === 1 ? 'change' : 'changes'} · ${sections.join(', ')}`;
        },
        async discard() {
            await this.$wire.discard();
            this.snapshot();
        },
        go(section) {
            document.getElementById(`settings-${section}`).scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
    }));

    // Allowed tools as grouped chips. The comma-separated string in Livewire stays the source of truth.
    window.Alpine.data('toolList', (legacy) => ({
        asText: false,
        adding: false,
        draft: '',
        get entries() {
            return String(this.$wire.allowed_tools ?? '').split(',').map((entry) => entry.trim()).filter(Boolean);
        },
        get groups() {
            const files = ['Read', 'Edit', 'Write', 'Glob', 'Grep', 'MultiEdit', 'NotebookEdit'];
            const agents = ['Skill', 'Agent', 'Task'];
            const groups = { Files: [], Agents: [], Shell: [], Other: [] };

            for (const entry of this.entries) {
                const shell = entry.match(/^Bash\((.*)\)$/);
                const group = shell ? 'Shell' : files.includes(entry) ? 'Files' : agents.includes(entry) ? 'Agents' : 'Other';
                groups[group].push({ entry, label: shell ? shell[1] : entry, legacy: legacy.includes(entry) });
            }

            return Object.entries(groups).filter(([, tools]) => tools.length).map(([name, tools]) => ({ name, tools }));
        },
        add() {
            const entry = this.draft.trim();

            if (entry && !this.entries.includes(entry)) {
                this.$wire.allowed_tools = [...this.entries, entry].join(',');
            }

            this.draft = '';
            this.adding = false;
        },
    }));

    // Locked paths as a list. The newline-separated string in Livewire stays the source of truth.
    window.Alpine.data('pathList', () => ({
        draft: '',
        get paths() {
            return String(this.$wire.locked_paths ?? '').split('\n').map((path) => path.trim()).filter(Boolean);
        },
        add() {
            const path = this.draft.trim();

            if (path && !this.paths.includes(path)) {
                this.$wire.locked_paths = [...this.paths, path].join('\n');
            }

            this.draft = '';
        },
        remove(index) {
            this.$wire.locked_paths = this.paths.filter((_, i) => i !== index).join('\n');
        },
    }));

    // Copies text a Livewire method returns. Handing ClipboardItem the promise keeps the click's
    // permission while the server builds the text, which Safari needs.
    window.Alpine.data('copyFrom', (method) => ({
        copied: false,
        async copy() {
            const text = this.$wire.call(method).then((t) => new Blob([t], { type: 'text/plain' }));
            await navigator.clipboard.write([new ClipboardItem({ 'text/plain': text })]);
            this.copied = true;
            setTimeout(() => (this.copied = false), 2000);
        },
    }));
});

// Shows the desktop notifications the dashboard's poll asks for. The tag stops two open tabs
// from showing the same run twice. Clicking one focuses this tab and opens the run.
window.addEventListener('foreman-notify', (event) => {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    for (const { id, title, body, url } of event.detail.notifications) {
        const notification = new Notification(title, { body, tag: `foreman-run-${id}` });

        notification.onclick = () => {
            window.focus();
            notification.close();
            window.Livewire.navigate(url);
        };
    }
});

// The Settings toggle: asks the browser for permission when switched on, and reports a refusal
document.addEventListener('alpine:init', () => {
    window.Alpine.data('desktopNotifications', () => ({
        permission: 'Notification' in window ? Notification.permission : 'unsupported',
        async ask(on) {
            if (on && this.permission === 'default') {
                this.permission = await Notification.requestPermission();
            }
        },
    }));
});
