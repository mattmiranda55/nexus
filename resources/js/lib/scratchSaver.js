// Debounced saving of each project's tinker buffer, so edits survive a
// restart without a request per keystroke on the single-threaded server.

/**
 * save(id, value) does the actual write. schedule() waits for typing to pause
 * (per project); flush() writes everything pending right away — call it when
 * the window is being hidden or closed.
 */
export function createScratchSaver(save, delay = 800) {
    const pending = new Map(); // id -> { timer, value }

    function write(id) {
        const entry = pending.get(id);
        if (!entry) return;
        clearTimeout(entry.timer);
        pending.delete(id);
        save(id, entry.value);
    }

    return {
        schedule(id, value) {
            if (id === null || id === undefined) return;
            clearTimeout(pending.get(id)?.timer);
            pending.set(id, { value, timer: setTimeout(() => write(id), delay) });
        },
        flush() {
            for (const id of [...pending.keys()]) write(id);
        },
    };
}
