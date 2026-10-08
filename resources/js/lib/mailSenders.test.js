import { describe, expect, it } from 'vitest';
import { projectFor, senderIndex } from './mailSenders.js';

const projects = [
    { name: 'billing', mailFrom: 'billing@acme.test' },
    { name: 'portal', mailFrom: 'hello@example.com' },
    { name: 'blog', mailFrom: 'hello@example.com' },
    { name: 'ops', mailFrom: null },
];

describe('projectFor', () => {
    const index = senderIndex(projects);

    it('tags mail from an address exactly one project uses, ignoring case', () => {
        expect(projectFor({ from: { address: 'Billing@Acme.test' } }, index)).toBe('billing');
    });

    it('leaves an address shared by several projects untagged', () => {
        expect(projectFor({ from: { address: 'hello@example.com' } }, index)).toBeNull();
    });

    it('leaves unknown senders untagged', () => {
        expect(projectFor({ from: { address: 'someone@else.test' } }, index)).toBeNull();
        expect(projectFor({}, index)).toBeNull();
    });
});
