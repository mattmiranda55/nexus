// One-line previews of tinker code for the run history list.

/**
 * Remove PHP comments (`//`, `#`, `/* *\/`) from code, leaving string
 * literals alone — `'http://x'` and `"#1"` are not comments. A `#[` is a PHP 8
 * attribute, not a comment, so it's kept too.
 */
export function stripComments(code) {
    let out = '';
    let i = 0;

    while (i < code.length) {
        const ch = code[i];
        const next = code[i + 1];

        if (ch === "'" || ch === '"' || ch === '`') {
            let j = i + 1;
            while (j < code.length && code[j] !== ch) j += code[j] === '\\' ? 2 : 1;
            out += code.slice(i, j + 1);
            i = j + 1;
        } else if (ch === '/' && next === '*') {
            const end = code.indexOf('*/', i + 2);
            i = end === -1 ? code.length : end + 2;
            out += ' ';
        } else if ((ch === '/' && next === '/') || (ch === '#' && next !== '[')) {
            while (i < code.length && code[i] !== '\n') i++;
        } else {
            out += ch;
            i++;
        }
    }

    return out;
}

/** The first line of actual code, comments removed, capped for display. */
export function codePreview(code, max = 80) {
    const line =
        stripComments(code)
            .split('\n')
            .map((l) => l.trim())
            .find(Boolean) ?? '';

    return line.length > max ? line.slice(0, max) + '…' : line;
}
