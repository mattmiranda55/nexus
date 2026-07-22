import { expect, test } from 'bun:test';
import { parseLogLine, parseFrame, buildParsedLogs, levelStyle } from './logParser.js';

test('parses a standard Laravel log header line', () => {
    const p = parseLogLine('[2026-07-19 12:00:00] local.ERROR: Boom');
    expect(p.isNew).toBe(true);
    expect(p.timestamp).toBe('2026-07-19 12:00:00');
    expect(p.env).toBe('local');
    expect(p.level).toBe('ERROR');
    expect(p.message).toBe('Boom');
});

test('parses source locations from both frame shapes', () => {
    expect(parseFrame('#0 /app/Foo.php(123): Bar->baz()')).toEqual({ file: '/app/Foo.php', line: 123 });
    expect(parseFrame('Uncaught error in /app/Http/Kernel.php:88')).toEqual({ file: '/app/Http/Kernel.php', line: 88 });
    expect(parseFrame('no path here')).toBeNull();
});

test('collects stack frames from continuation lines', () => {
    const content = [
        '[2026-07-19 12:00:00] local.ERROR: Boom',
        'Stack trace:',
        '#0 /app/foo.php(12): bar()',
        '#1 /app/baz.php(34): qux()',
    ].join('\n');

    const [entry] = buildParsedLogs(content);
    expect(entry.stack).toEqual([
        { file: '/app/foo.php', line: 12, raw: '#0 /app/foo.php(12): bar()' },
        { file: '/app/baz.php', line: 34, raw: '#1 /app/baz.php(34): qux()' },
    ]);
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
