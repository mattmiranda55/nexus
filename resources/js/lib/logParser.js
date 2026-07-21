// Parses raw Laravel log text into structured entries.
// Ported from the original Nexus Output.svelte log parsing.

export function parseLogLine(line) {
    const trimmed = line.trim();

    // e.g. "[2026-07-19 12:00:00] local.ERROR: Something broke"
    const laravelMatch = trimmed.match(/^\[([^\]]+)\]\s+(?:[\w-]+\.)?([A-Z]+):\s*(.*)$/);
    if (laravelMatch) {
        return {
            isNew: true,
            timestamp: laravelMatch[1],
            level: laravelMatch[2],
            message: laravelMatch[3] || '',
        };
    }

    // Looser fallback: "[timestamp] label: message"
    const fallbackMatch = trimmed.match(/^\[([^\]]+)\]\s+([^:]+):\s*(.*)$/);
    if (fallbackMatch) {
        return {
            isNew: true,
            timestamp: fallbackMatch[1],
            level: fallbackMatch[2],
            message: fallbackMatch[3] || '',
        };
    }

    // Continuation line (stack trace, context, etc.)
    return { isNew: false, message: trimmed };
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
                level: parsed.level.toLowerCase(),
                originalLevel: parsed.level,
                message: parsed.message,
                details: [],
                raw: rawLine,
            };
        } else if (current) {
            current.details.push(rawLine);
        } else {
            // Orphan continuation before any header — treat as a plain info line.
            current = {
                timestamp: '',
                level: 'info',
                originalLevel: 'INFO',
                message: rawLine,
                details: [],
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
