import { expect, test } from 'bun:test';
import { fuzzyScore, fuzzyFilter } from './fuzzy.js';

test('empty query matches everything with score 0', () => {
    expect(fuzzyScore('', 'anything')).toBe(0);
});

test('non-subsequence returns null', () => {
    expect(fuzzyScore('xyz', 'switch project')).toBeNull();
    expect(fuzzyScore('abc', '')).toBeNull();
});

test('matching is case-insensitive', () => {
    expect(fuzzyScore('USER', 'User::count()')).not.toBeNull();
});

test('start-of-string beats mid-string', () => {
    expect(fuzzyScore('log', 'Logs')).toBeGreaterThan(fuzzyScore('log', 'View catalog'));
});

test('word-boundary hits beat buried ones', () => {
    expect(fuzzyScore('pr', 'switch project')).toBeGreaterThan(fuzzyScore('pr', 'deeper'));
});

test('contiguous matches beat scattered ones', () => {
    expect(fuzzyScore('route', 'Routes')).toBeGreaterThan(fuzzyScore('route', 'r o u t e list'));
});

test('spaces in the query are ignored', () => {
    expect(fuzzyScore('db rows', 'db: rows')).not.toBeNull();
});

test('fuzzyFilter drops non-matches and prefers shorter targets on equal hits', () => {
    const items = ['Migrations', 'Models', 'Mail', 'Logs'];
    expect(fuzzyFilter('m', items)).toEqual(['Mail', 'Models', 'Migrations']);
});

test('fuzzyFilter keeps original order on exact score ties', () => {
    expect(fuzzyFilter('ab', ['abc', 'abd'])).toEqual(['abc', 'abd']);
});

test('fuzzyFilter uses the text extractor', () => {
    const items = [{ label: 'Run' }, { label: 'Clear output' }];
    expect(fuzzyFilter('run', items, (i) => i.label)).toEqual([{ label: 'Run' }]);
});
