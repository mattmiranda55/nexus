// Reassembles complete lines from an arbitrary-chunk stream. The Electron
// child-process bridge delivers stdout in whatever chunks the pipe produces,
// so JSON-lines consumers must buffer the partial tail until its newline arrives.

export function createLineBuffer(onLine) {
    let buffer = '';

    return (chunk) => {
        buffer += String(chunk);
        const lines = buffer.split('\n');
        buffer = lines.pop() ?? '';
        for (const line of lines) {
            if (line.trim()) onLine(line);
        }
    };
}
