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
