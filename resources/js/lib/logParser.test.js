import { expect, test } from 'vitest';
import { createLogAccumulator, parseLogLine, parseFrame, buildParsedLogs, levelStyle } from './logParser.js';

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

test('parses Windows drive-rooted source locations', () => {
    expect(parseFrame('#0 C:\\app\\Foo.php(123): Bar->baz()')).toEqual({
        file: 'C:\\app\\Foo.php',
        line: 123,
    });
    expect(parseFrame('Uncaught error in C:\\app\\Http\\Kernel.php:88')).toEqual({
        file: 'C:\\app\\Http\\Kernel.php',
        line: 88,
    });
    // Forward-slash Windows paths turn up too (Laravel normalises some of them).
    expect(parseFrame('#0 D:/app/Foo.php(7): bar()')).toEqual({ file: 'D:/app/Foo.php', line: 7 });
});

test('does not mistake a drive letter colon for the line separator', () => {
    expect(parseFrame('#0 C:\\app\\Foo.php(123): Bar->baz()').line).toBe(123);
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

test('accumulator matches a one-shot parse when fed the same text', () => {
    const content = [
        '[2026-07-19 12:00:00] local.ERROR: Boom',
        'Stack trace:',
        '#0 /app/foo.php(12): bar()',
        '[2026-07-19 12:00:01] local.INFO: Recovered',
    ].join('\n');

    const acc = createLogAccumulator({ maxEntries: Infinity });
    acc.push(content);
    acc.flush();

    expect(acc.entries.map((e) => e.message)).toEqual(buildParsedLogs(content).map((e) => e.message));
    expect(acc.entries[0].details).toEqual(['Stack trace:', '#0 /app/foo.php(12): bar()']);
});

test('accumulator holds back a line split across chunks', () => {
    const acc = createLogAccumulator();

    // The newline hasn't arrived yet — nothing may be emitted.
    acc.push('[2026-07-19 12:00:00] local.ERR');
    expect(acc.entries).toHaveLength(0);

    acc.push('OR: Boom\n');
    expect(acc.entries).toHaveLength(1);
    expect(acc.entries[0].level).toBe('error');
    expect(acc.entries[0].message).toBe('Boom');
});

test('accumulator handles a CRLF chunk boundary', () => {
    const acc = createLogAccumulator();
    acc.push('[2026-07-19 12:00:00] local.INFO: One\r');
    acc.push('\n[2026-07-19 12:00:01] local.INFO: Two\r\n');

    expect(acc.entries.map((e) => e.message)).toEqual(['One', 'Two']);
});

test('accumulator returns only the entries a chunk opened', () => {
    const acc = createLogAccumulator();

    const first = acc.push('[2026-07-19 12:00:00] local.ERROR: Boom\n');
    expect(first).toHaveLength(1);

    // A continuation folds into the open entry rather than opening a new one.
    const second = acc.push('#0 /app/foo.php(12): bar()\n');
    expect(second).toHaveLength(0);
    expect(acc.entries[0].stack).toHaveLength(1);
});

test('accumulator drops the oldest entries past its window', () => {
    const acc = createLogAccumulator({ maxEntries: 3 });

    for (let i = 0; i < 5; i++) {
        acc.push(`[2026-07-19 12:00:0${i}] local.INFO: Line ${i}\n`);
    }

    expect(acc.entries.map((e) => e.message)).toEqual(['Line 2', 'Line 3', 'Line 4']);
    expect(acc.dropped).toBe(2);
});

test('accumulator gives entries stable ids that survive trimming', () => {
    const acc = createLogAccumulator({ maxEntries: 2 });

    for (let i = 0; i < 4; i++) {
        acc.push(`[2026-07-19 12:00:0${i}] local.INFO: Line ${i}\n`);
    }

    // Ids track the entry, not its position, so expansion state can't slide
    // onto whatever row inherited an index.
    expect(acc.entries.map((e) => e.id)).toEqual([2, 3]);
});

test('accumulator reset keeps the array identity callers hold', () => {
    const acc = createLogAccumulator();
    const held = acc.entries;

    acc.push('[2026-07-19 12:00:00] local.INFO: One\n');
    acc.reset();

    expect(acc.entries).toBe(held);
    expect(held).toHaveLength(0);
    expect(acc.dropped).toBe(0);
});

test('maps levels to distinct colors', () => {
    expect(levelStyle('ERROR').text).toBe('text-red-500');
    expect(levelStyle('warning').text).toBe('text-amber-500');
    expect(levelStyle('info').text).toBe('text-emerald-500');
    expect(levelStyle('debug').text).toBe('text-sky-500');
});
