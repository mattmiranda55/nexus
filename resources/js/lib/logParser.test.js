import { expect, test } from 'bun:test';
import { parseLogLine, buildParsedLogs, levelStyle } from './logParser.js';

test('parses a standard Laravel log header line', () => {
    const p = parseLogLine('[2026-07-19 12:00:00] local.ERROR: Boom');
    expect(p.isNew).toBe(true);
    expect(p.timestamp).toBe('2026-07-19 12:00:00');
    expect(p.level).toBe('ERROR');
    expect(p.message).toBe('Boom');
});

test('treats a non-header line as a continuation', () => {
    const p = parseLogLine('#0 /app/foo.php(12): bar()');
    expect(p.isNew).toBe(false);
    expect(p.message).toBe('#0 /app/foo.php(12): bar()');
});

test('folds continuation lines into the preceding entry', () => {
    const content = [
        '[2026-07-19 12:00:00] local.ERROR: Boom',
        'Stack trace:',
        '#0 /app/foo.php(12): bar()',
        '[2026-07-19 12:00:01] local.INFO: Recovered',
    ].join('\n');

    const entries = buildParsedLogs(content);
    expect(entries).toHaveLength(2);
    expect(entries[0].level).toBe('error');
    expect(entries[0].details).toEqual(['Stack trace:', '#0 /app/foo.php(12): bar()']);
    expect(entries[1].level).toBe('info');
    expect(entries[1].message).toBe('Recovered');
});

test('returns empty for blank content', () => {
    expect(buildParsedLogs('')).toEqual([]);
});

test('maps levels to distinct colors', () => {
    expect(levelStyle('ERROR').text).toBe('text-red-500');
    expect(levelStyle('warning').text).toBe('text-amber-500');
    expect(levelStyle('info').text).toBe('text-emerald-500');
    expect(levelStyle('debug').text).toBe('text-sky-500');
});
