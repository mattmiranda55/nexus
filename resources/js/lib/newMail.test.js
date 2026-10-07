import { describe, expect, it } from 'vitest';
import { createArrivalTracker, notificationFor } from './newMail.js';

const msg = (id, extra = {}) => ({ id, subject: `S${id}`, read: false, from: { name: '', address: `${id}@x.test` }, ...extra });

describe('createArrivalTracker', () => {
    it('treats the first load as a baseline, not as arrivals', () => {
        const tracker = createArrivalTracker();
        expect(tracker.update([msg('a'), msg('b')])).toEqual([]);
    });

    it('reports unread messages that were not there before', () => {
        const tracker = createArrivalTracker();
        tracker.update([msg('a')]);

        expect(tracker.update([msg('c'), msg('b', { read: true }), msg('a')]).map((m) => m.id)).toEqual(['c']);
        expect(tracker.update([msg('c'), msg('b'), msg('a')])).toEqual([]);
    });

    it('starts over after a reset (e.g. switching mail server)', () => {
        const tracker = createArrivalTracker();
        tracker.update([msg('a')]);
        tracker.reset();

        expect(tracker.update([msg('z')])).toEqual([]);
    });
});

describe('notificationFor', () => {
    it('describes one message by sender and subject', () => {
        expect(notificationFor([msg('a', { from: { name: 'Shop', address: 's@x.test' } })])).toEqual({
            count: 1, id: 'a', subject: 'Sa', from: 'Shop',
        });
    });

    it('summarizes a batch by the newest message', () => {
        expect(notificationFor([msg('c'), msg('b')])).toMatchObject({ count: 2, id: 'c', from: 'c@x.test' });
    });
});
