import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createScratchSaver } from './scratchSaver.js';

describe('createScratchSaver', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('saves once typing pauses, with the latest value', () => {
        const save = vi.fn();
        const saver = createScratchSaver(save, 800);

        saver.schedule(1, 'a');
        vi.advanceTimersByTime(500);
        saver.schedule(1, 'ab');
        vi.advanceTimersByTime(799);
        expect(save).not.toHaveBeenCalled();

        vi.advanceTimersByTime(1);
        expect(save).toHaveBeenCalledExactlyOnceWith(1, 'ab');
    });

    it('debounces each project separately', () => {
        const save = vi.fn();
        const saver = createScratchSaver(save, 800);

        saver.schedule(1, 'one');
        saver.schedule(2, 'two');
        vi.advanceTimersByTime(800);

        expect(save.mock.calls).toEqual([[1, 'one'], [2, 'two']]);
    });

    it('flush writes pending saves immediately, and only once', () => {
        const save = vi.fn();
        const saver = createScratchSaver(save, 800);

        saver.schedule(1, 'x');
        saver.flush();
        vi.advanceTimersByTime(800);

        expect(save).toHaveBeenCalledExactlyOnceWith(1, 'x');
    });

    it('ignores the no-project buffer', () => {
        const save = vi.fn();
        const saver = createScratchSaver(save, 10);

        saver.schedule(null, 'x');
        vi.advanceTimersByTime(10);

        expect(save).not.toHaveBeenCalled();
    });
});
