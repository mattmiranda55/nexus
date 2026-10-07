import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The module keeps registration state at module level, so each test imports a
// fresh copy against a fresh fake window.
async function freshModule() {
    vi.resetModules();
    return import('./nativeEvents.js');
}

function fakeNative() {
    const handlers = [];
    return {
        on: (event, handler) => handlers.push({ event, handler }),
        emit: (payload) => handlers.forEach(({ handler }) => handler(payload)),
        get count() {
            return handlers.length;
        },
    };
}

describe('onChildProcessMessage', () => {
    beforeEach(() => {
        globalThis.window = new EventTarget();
    });

    afterEach(() => {
        delete globalThis.window;
    });

    it('registers one bridge listener even when several components subscribe before native:init', async () => {
        const { onChildProcessMessage } = await freshModule();
        const a = vi.fn();
        const b = vi.fn();

        onChildProcessMessage('tail', a);
        onChildProcessMessage('tail', b);

        window.Native = fakeNative();
        window.dispatchEvent(new Event('native:init'));
        window.Native.emit({ alias: 'tail', data: 'line\n' });

        expect(window.Native.count).toBe(1);
        expect(a).toHaveBeenCalledTimes(1);
        expect(b).toHaveBeenCalledTimes(1);
        expect(a).toHaveBeenCalledWith('line\n');
    });

    it('routes messages by alias and stops after unsubscribe', async () => {
        window.Native = fakeNative();
        const { onChildProcessMessage } = await freshModule();
        const tail = vi.fn();
        const mail = vi.fn();

        const stop = onChildProcessMessage('tail', tail);
        onChildProcessMessage('mailpit', mail);

        window.Native.emit({ alias: 'tail', data: 'x' });
        stop();
        window.Native.emit({ alias: 'tail', data: 'y' });

        expect(tail).toHaveBeenCalledTimes(1);
        expect(mail).not.toHaveBeenCalled();
    });
});

describe('onChildProcessExit', () => {
    beforeEach(() => {
        globalThis.window = new EventTarget();
    });

    afterEach(() => {
        delete globalThis.window;
    });

    function routingNative() {
        const handlers = [];
        return {
            on: (event, handler) => handlers.push({ event, handler }),
            emit: (event, payload) => handlers.filter((h) => h.event === event).forEach((h) => h.handler(payload)),
        };
    }

    it('fires on exit and on a failed start, only for its alias', async () => {
        window.Native = routingNative();
        const { onChildProcessExit, onChildProcessMessage } = await freshModule();
        const exit = vi.fn();
        const message = vi.fn();

        const stop = onChildProcessExit('tinker-1', exit);
        onChildProcessMessage('tinker-1', message);

        window.Native.emit('Native\\Desktop\\Events\\ChildProcess\\ProcessExited', { alias: 'tinker-2', code: 0 });
        window.Native.emit('Native\\Desktop\\Events\\ChildProcess\\ProcessExited', { alias: 'tinker-1', code: 0 });
        window.Native.emit('Native\\Desktop\\Events\\ChildProcess\\StartupError', { alias: 'tinker-1', error: 'x' });
        stop();
        window.Native.emit('Native\\Desktop\\Events\\ChildProcess\\ProcessExited', { alias: 'tinker-1', code: 1 });

        expect(exit.mock.calls).toEqual([[0], [null]]);
        expect(message).not.toHaveBeenCalled();
    });
});
