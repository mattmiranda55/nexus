// Parses raw Laravel log text into structured entries.
// Ported from the original Nexus Output.svelte log parsing.

export function parseLogLine(line) {
    const trimmed = line.trim();

    // e.g. "[2026-07-19 12:00:00] local.ERROR: Something broke"
    const laravelMatch = trimmed.match(/^\[([^\]]+)\]\s+(?:([\w-]+)\.)?([A-Z]+):\s*(.*)$/);
    if (laravelMatch) {
        return {
            isNew: true,
            timestamp: laravelMatch[1],
            env: laravelMatch[2] || '',
            level: laravelMatch[3],
            message: laravelMatch[4] || '',
        };
    }

    // Looser fallback: "[timestamp] label: message"
    const fallbackMatch = trimmed.match(/^\[([^\]]+)\]\s+([^:]+):\s*(.*)$/);
    if (fallbackMatch) {
        return {
            isNew: true,
            timestamp: fallbackMatch[1],
            env: '',
            level: fallbackMatch[2],
            message: fallbackMatch[3] || '',
        };
    }

    // Continuation line (stack trace, context, etc.)
    return { isNew: false, message: trimmed };
}

// Pulls an absolute source location out of a line — both PHP stack-frame shapes:
//   "#0 /app/Foo.php(123): Bar->baz()"  and  "... in /app/Foo.php:123"
export function parseFrame(text) {
    const match = (text || '').match(/(\/[^\s:()]+\.php)[:(](\d+)\)?/);
    if (!match) return null;
    return { file: match[1], line: Number(match[2]) };
}

export function buildParsedLogs(content) {
    if (!content) return [];

    const lines = content.split(/\r?\n/);
    const entries = [];
    let current = null;

    for (const rawLine of lines) {
        if (!rawLine) continue;

        const parsed = parseLogLine(rawLine);

        if (parsed.isNew) {
            if (current) entries.push(current);
            current = {
                timestamp: parsed.timestamp,
                env: parsed.env ?? '',
                level: parsed.level.toLowerCase(),
                originalLevel: parsed.level,
                message: parsed.message,
                details: [],
                stack: [],
                raw: rawLine,
            };
            // A location can sit on the header line itself ("… in /f.php:12").
            const headFrame = parseFrame(parsed.message);
            if (headFrame) current.stack.push({ ...headFrame, raw: parsed.message });
        } else if (current) {
            current.details.push(rawLine);
            const frame = parseFrame(rawLine);
            if (frame) current.stack.push({ ...frame, raw: rawLine.trim() });
        } else {
            // Orphan continuation before any header — treat as a plain info line.
            current = {
                timestamp: '',
                env: '',
                level: 'info',
                originalLevel: 'INFO',
                message: rawLine,
                details: [],
                stack: [],
                raw: rawLine,
            };
        }
    }

    if (current) entries.push(current);

    return entries;
}

// Returns Tailwind classes { dot, text } for a log level.
export function levelStyle(level) {
    const l = (level || '').toLowerCase();
    if (['emergency', 'alert', 'critical', 'error'].includes(l)) {
        return { dot: 'bg-red-500', text: 'text-red-500' };
    }
    if (l === 'warning') return { dot: 'bg-amber-500', text: 'text-amber-500' };
    if (l === 'notice') return { dot: 'bg-yellow-500', text: 'text-yellow-500' };
    if (l === 'info') return { dot: 'bg-emerald-500', text: 'text-emerald-500' };
    if (l === 'debug') return { dot: 'bg-sky-500', text: 'text-sky-500' };
    return { dot: 'bg-neutral-500', text: 'text-neutral-500' };
}
