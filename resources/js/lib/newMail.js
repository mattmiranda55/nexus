// Spotting newly arrived mail between two inbox loads, for notifications.

/**
 * Tracks which messages have been seen. The first load only records what's
 * there — existing mail isn't "new" just because Nexus started.
 */
export function createArrivalTracker() {
    let known = null;

    return {
        /** Unread messages not present in any earlier load (newest first). */
        update(messages) {
            const ids = new Set(messages.map((m) => m.id));
            const fresh = known === null ? [] : messages.filter((m) => !known.has(m.id) && !m.read);
            known = ids;
            return fresh;
        },
        reset() {
            known = null;
        },
    };
}

/** The /mail/notify payload for a batch of arrivals. */
export function notificationFor(arrivals) {
    const latest = arrivals[0];
    return {
        count: arrivals.length,
        id: latest.id,
        subject: latest.subject ?? '',
        from: latest.from?.name || latest.from?.address || '',
    };
}
