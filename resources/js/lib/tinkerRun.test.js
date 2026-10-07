import { describe, expect, it, vi } from 'vitest';
import { DONE, watchTinkerRun } from './tinkerRun.js';

const ID = '0b6c1d2e-3f40-4a5b-8c6d-7e8f90a1b2c3';

// A minimal event bus standing in for nativeEvents.js.
function bus() {
    const subs = { message: new Set(), exit: new Set() };
    const on = (kind) => (alias, cb) => {
        const sub = { alias, cb };
        subs[kind].add(sub);
        return () => subs[kind].delete(sub);
    };
    return {
        onMessage: on('message'),
        onExit: on('exit'),
        emit: (kind, alias, value) => subs[kind].forEach((s) => s.alias === alias && s.cb(value)),
        get size() {
            return subs.message.size + subs.exit.size;
        },
    };
}

const flush = () => new Promise((r) => setTimeout(r, 0));

describe('watchTinkerRun', () => {
    it('fetches as soon as the worker prints DONE, even before result() is called', async () => {
        const events = bus();
        const fetchResult = vi.fn().mockResolvedValue({ status: 200, data: { raw: '5' } });
        const watcher = watchTinkerRun(ID, { ...events, fetchResult, delays: [60_000] });

        // The run finishes while the POST is still in flight.
        events.emit('message', `tinker-${ID}`, `${DONE}\n`);

        await expect(watcher.result()).resolves.toEqual({ status: 200, data: { raw: '5' } });
        expect(fetchResult).toHaveBeenCalledWith(ID, false);
        expect(events.size).toBe(0);
    });

    it('ignores other aliases and unrelated output', async () => {
        vi.useFakeTimers();
        const events = bus();
        const fetchResult = vi.fn().mockResolvedValue({ status: 200, data: {} });
        const watcher = watchTinkerRun(ID, { ...events, fetchResult, delays: [5000] });
        const done = watcher.result();

        events.emit('message', 'tinker-other', DONE);
        events.emit('message', `tinker-${ID}`, 'some log line');
        await vi.advanceTimersByTimeAsync(4999);
        expect(fetchResult).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        await done;
        expect(fetchResult).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
    });

    it('reports an exit to the server so a dead worker settles the run', async () => {
        const events = bus();
        const fetchResult = vi.fn().mockResolvedValue({ status: 200, data: { raw: 'Error: stopped' } });
        const watcher = watchTinkerRun(ID, { ...events, fetchResult, delays: [60_000] });
        const done = watcher.result();

        events.emit('exit', `tinker-${ID}`, 255);

        await expect(done).resolves.toMatchObject({ status: 200 });
        expect(fetchResult).toHaveBeenCalledWith(ID, true);
    });

    it('keeps checking with backoff while the server says the run is going', async () => {
        vi.useFakeTimers();
        const events = bus();
        const fetchResult = vi
            .fn()
            .mockResolvedValueOnce({ status: 202 })
            .mockResolvedValueOnce({ status: 202 })
            .mockResolvedValue({ status: 200, data: {} });
        const done = watchTinkerRun(ID, { ...events, fetchResult, delays: [10, 20] }).result();

        await vi.advanceTimersByTimeAsync(10);
        expect(fetchResult).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(19);
        expect(fetchResult).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(fetchResult).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(20); // last delay repeats
        await expect(done).resolves.toMatchObject({ status: 200 });
        vi.useRealTimers();
    });

    it('stop() unsubscribes when the run came back inline', async () => {
        const events = bus();
        const watcher = watchTinkerRun(ID, { ...events, fetchResult: vi.fn() });

        watcher.stop();
        await flush();

        expect(events.size).toBe(0);
    });
});

describe('watchTinkerRun cancel', () => {
    it('ends a pending wait with the stop response without fetching', async () => {
        const events = bus();
        const fetchResult = vi.fn();
        const watcher = watchTinkerRun(ID, { ...events, fetchResult, delays: [60_000] });
        const done = watcher.result();

        const stopped = { status: 200, data: { raw: 'Error: Run stopped.' } };
        await watcher.cancel(() => Promise.resolve(stopped));

        await expect(done).resolves.toBe(stopped);
        expect(fetchResult).not.toHaveBeenCalled();
        expect(events.size).toBe(0);
    });

    it('sends the stop request only once', async () => {
        const watcher = watchTinkerRun(ID, { ...bus(), fetchResult: vi.fn() });
        const request = vi.fn().mockResolvedValue({ status: 200 });

        watcher.cancel(request);
        watcher.cancel(request);

        expect(request).toHaveBeenCalledTimes(1);
    });

    it('keeps a real result that was already on its way', async () => {
        const events = bus();
        let finish;
        const fetchResult = vi.fn(() => new Promise((r) => (finish = r)));
        const watcher = watchTinkerRun(ID, { ...events, fetchResult, delays: [60_000] });
        const done = watcher.result();

        events.emit('message', `tinker-${ID}`, DONE);
        await flush();
        watcher.cancel(() => Promise.resolve({ status: 404 }));
        finish({ status: 200, data: { raw: '42' } });

        await expect(done).resolves.toEqual({ status: 200, data: { raw: '42' } });
    });
});
