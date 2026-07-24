import { expect, test } from 'bun:test';
import { createLineBuffer } from './lineBuffer.js';

function collect() {
    const lines = [];
    return { lines, feed: createLineBuffer((l) => lines.push(l)) };
}

test('emits complete lines and holds the partial tail', () => {
    const { lines, feed } = collect();
    feed('{"a":1}\n{"b"');
    expect(lines).toEqual(['{"a":1}']);
    feed(':2}\n');
    expect(lines).toEqual(['{"a":1}', '{"b":2}']);
});

test('handles multiple lines in one chunk', () => {
    const { lines, feed } = collect();
    feed('one\ntwo\nthree\n');
    expect(lines).toEqual(['one', 'two', 'three']);
});

test('skips blank lines', () => {
    const { lines, feed } = collect();
    feed('one\n\n  \ntwo\n');
    expect(lines).toEqual(['one', 'two']);
});

test('a line split across three chunks arrives once, whole', () => {
    const { lines, feed } = collect();
    feed('{"long');
    feed('er":');
    feed('true}\n');
    expect(lines).toEqual(['{"longer":true}']);
});
