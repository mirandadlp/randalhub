import { reactive } from 'vue';

export const store = reactive({
    user: null,
    needsSetup: false,
    toasts: [],
    sidebarOpen: false,
});

let toastId = 0;
export function toast(message, type = 'info', timeout = 4500) {
    const id = ++toastId;
    store.toasts.push({ id, message, type });
    setTimeout(() => {
        store.toasts = store.toasts.filter((t) => t.id !== id);
    }, timeout);
}
