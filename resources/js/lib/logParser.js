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
// POSIX roots ("/app/Foo.php") and Windows drive roots ("C:\app\Foo.php") both
// occur, depending on where the project runs. The drive letter's colon is
// consumed by the prefix so it can't be mistaken for the line separator.
// Known limit on both platforms: paths containing spaces won't match.
export function parseFrame(text) {
    const match = (text || '').match(/((?:[A-Za-z]:[\\/]|\/)[^\s:()]*\.php)[:(](\d+)\)?/);
    if (!match) return null;
    return { file: match[1], line: Number(match[2]) };
}

/**
 * Streaming parser over `tail` output.
 *
 * The obvious implementation — keep the raw text and re-run a whole-buffer
 * parse whenever a chunk lands — is quadratic over a session: a busy log
 * re-parses a growing buffer once per chunk and pins a core. This consumes each
 * line exactly once instead, holding back the trailing partial line until its
 * newline arrives (a chunk boundary lands mid-line often enough to matter).
 *
 * `maxEntries` bounds memory; the oldest entries fall off the front. Pass
 * `Infinity` for a one-shot parse of complete text.
 */
export function createLogAccumulator({ maxEntries = 2000 } = {}) {
    const entries = [];
    let current = null; // last header entry; continuation lines fold into it
    let pending = ''; // bytes after the last newline — not yet a whole line
    let dropped = 0;
    let nextId = 0;

    function open(entry, fresh) {
        entry.id = nextId++;
        entries.push(entry);
        current = entry;
        fresh.push(entry);
    }

    function consume(rawLine, fresh) {
        if (!rawLine) return;

        const parsed = parseLogLine(rawLine);

        if (parsed.isNew) {
            open({
                timestamp: parsed.timestamp,
                env: parsed.env ?? '',
                level: parsed.level.toLowerCase(),
                originalLevel: parsed.level,
                message: parsed.message,
                details: [],
                stack: [],
                raw: rawLine,
            }, fresh);

            // A location can sit on the header line itself ("… in /f.php:12").
            const headFrame = parseFrame(parsed.message);
            if (headFrame) current.stack.push({ ...headFrame, raw: parsed.message });
        } else if (current) {
            current.details.push(rawLine);
            const frame = parseFrame(rawLine);
            if (frame) current.stack.push({ ...frame, raw: rawLine.trim() });
        } else {
            // Orphan continuation before any header — treat as a plain info line.
            open({
                timestamp: '',
                env: '',
                level: 'info',
                originalLevel: 'INFO',
                message: rawLine,
                details: [],
                stack: [],
                raw: rawLine,
            }, fresh);
        }
    }

    function trim() {
        const excess = entries.length - maxEntries;
        if (excess > 0) {
            entries.splice(0, excess);
            dropped += excess;
        }
    }

    return {
        /** The live entry array — mutated in place, never reallocated. */
        get entries() {
            return entries;
        },

        /** How many entries have aged out of the front of the window. */
        get dropped() {
            return dropped;
        },

        /**
         * Feed a chunk of tail output.
         *
         * @returns {Array} the entries this chunk opened (for notifications) —
         *   note continuation lines mutate an existing entry and return nothing.
         */
        push(chunk) {
            const fresh = [];
            pending += chunk;

            const lines = pending.split(/\r?\n/);
            pending = lines.pop() ?? ''; // trailing fragment waits for its newline
            for (const line of lines) consume(line, fresh);

            trim();

            return fresh;
        },

        /** Consume a trailing fragment that will never get its newline. */
        flush() {
            const fresh = [];
            if (pending) {
                consume(pending, fresh);
                pending = '';
                trim();
            }

            return fresh;
        },

        /**
         * Empties the window in place. The array identity is deliberately
         * preserved — callers hold a reference to it (a Vue shallowRef, say)
         * and swapping it out here would silently orphan them.
         */
        reset() {
            entries.length = 0;
            current = null;
            pending = '';
            dropped = 0;
        },
    };
}

/** One-shot parse of complete log text. */
export function buildParsedLogs(content) {
    if (!content) return [];

    const acc = createLogAccumulator({ maxEntries: Infinity });
    acc.push(content);
    acc.flush();

    return acc.entries;
}

// Returns Tailwind classes { dot, text } for a log level.
export function levelStyle(level) {
    const l = (level || '').toLowerCase();
    if (['emergency', 'alert', 'critical', 'error'].includes(l)) {
        return { dot: 'bg-err', text: 'text-err' };
    }
    if (l === 'warning') return { dot: 'bg-warn', text: 'text-warn' };
    if (l === 'notice') return { dot: 'bg-num', text: 'text-num' };
    if (l === 'info') return { dot: 'bg-ok', text: 'text-ok' };
    if (l === 'debug') return { dot: 'bg-key', text: 'text-key' };
    return { dot: 'bg-ink-3', text: 'text-ink-3' };
}
