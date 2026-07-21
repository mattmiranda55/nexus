// Bridges NativePHP's injected `window.Native` event stream to the app.
// Registers a single global listener and fans messages out to subscribers,
// so components can mount/unmount without stacking duplicate listeners.

const EVENT = 'Native\\Desktop\\Events\\ChildProcess\\MessageReceived';
const subscribers = new Set();
let registered = false;

function ensureRegistered() {
    if (registered || typeof window === 'undefined') return;

    const bind = () => {
        if (!window.Native?.on) return;
        window.Native.on(EVENT, (event) => {
            for (const sub of subscribers) sub(event);
        });
        registered = true;
    };

    // `window.Native` is only present inside the NativePHP (Electron) runtime,
    // and only after the `native:init` event fires.
    if (window.Native?.on) bind();
    else window.addEventListener('native:init', bind, { once: true });
}

/**
 * Subscribe to child-process stdout for a given alias.
 * Returns an unsubscribe function.
 */
export function onChildProcessMessage(alias, callback) {
    ensureRegistered();

    const sub = (event) => {
        if (event?.alias === alias) callback(event.data ?? '');
    };
    subscribers.add(sub);

    return () => subscribers.delete(sub);
}

export function nativeAvailable() {
    return typeof window !== 'undefined' && !!window.Native;
}
