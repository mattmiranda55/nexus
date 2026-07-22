// Shapes an array of plain records into the envelope `table` prop that
// OutputTable renders ({ columns, rows, count, truncated }). Array/object cells
// collapse to a compact string so the grid stays flat.

function cell(value) {
    if (value === null || value === undefined) return null;
    if (Array.isArray(value)) return value.join(', ');
    if (typeof value === 'object') return JSON.stringify(value);
    return value;
}

export function toTable(records, columns) {
    const cols = columns ?? [...new Set(records.flatMap((r) => Object.keys(r)))];
    const rows = records.map((r) => cols.map((c) => cell(r[c])));
    return { columns: cols, rows, count: records.length, truncated: false };
}
