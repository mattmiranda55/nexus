// Bridges NativePHP's injected `window.Native` event stream to the app.
// Registers a single global listener per event type and fans messages out to
// subscribers, so components can mount/unmount without stacking duplicate
// listeners.

const MESSAGE = 'Native\\Desktop\\Events\\ChildProcess\\MessageReceived';
const EXITED = 'Native\\Desktop\\Events\\ChildProcess\\ProcessExited';
const STARTUP_ERROR = 'Native\\Desktop\\Events\\ChildProcess\\StartupError';
const NOTIFICATION_CLICKED = 'Native\\Desktop\\Events\\Notifications\\NotificationClicked';

const subscribers = new Map(); // event -> Set of handlers
// An event is marked as soon as its registration is under way — not when it
// completes — so two components subscribing before `native:init` fires don't
// each queue their own bind and end up delivering every message twice.
const registering = new Set();

function ensureRegistered(event) {
    if (registering.has(event) || typeof window === 'undefined') return;
    registering.add(event);

    const bind = () => {
        if (!window.Native?.on) {
            registering.delete(event); // let the next subscriber try again
            return;
        }
        window.Native.on(event, (payload) => {
            for (const sub of subscribers.get(event) ?? []) sub(payload);
        });
    };

    // `window.Native` is only present inside the NativePHP (Electron) runtime,
    // and only after the `native:init` event fires.
    if (window.Native?.on) bind();
    else window.addEventListener('native:init', bind, { once: true });
}

// alias null = every payload of this event.
function subscribe(event, alias, callback) {
    ensureRegistered(event);

    const sub = (payload) => {
        if (alias === null || payload?.alias === alias) callback(payload);
    };
    if (!subscribers.has(event)) subscribers.set(event, new Set());
    subscribers.get(event).add(sub);

    return () => subscribers.get(event).delete(sub);
}

/**
 * Subscribe to child-process stdout for a given alias.
 * Returns an unsubscribe function.
 */
export function onChildProcessMessage(alias, callback) {
    return subscribe(MESSAGE, alias, (payload) => callback(payload.data ?? ''));
}

/**
 * Subscribe to a child process ending — exiting, or failing to start at all.
 * The callback gets the exit code (null when it never started).
 * Returns an unsubscribe function.
 */
export function onChildProcessExit(alias, callback) {
    const stops = [
        subscribe(EXITED, alias, (payload) => callback(payload.code ?? null)),
        subscribe(STARTUP_ERROR, alias, () => callback(null)),
    ];

    return () => stops.forEach((stop) => stop());
}

/**
 * Subscribe to clicks on OS notifications; the callback gets the reference
 * the notification was shown with. Returns an unsubscribe function.
 */
export function onNotificationClicked(callback) {
    return subscribe(NOTIFICATION_CLICKED, null, (payload) => callback(payload?.reference ?? ''));
}

export function nativeAvailable() {
    return typeof window !== 'undefined' && !!window.Native;
}
