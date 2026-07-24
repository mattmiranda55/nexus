// Dependency-free fuzzy matcher for the command palette.
//
// Subsequence matching with a small scorer: every query character must appear
// in order, and hits score higher when they start the string, sit on a word
// boundary, or run contiguously. Gaps between hits cost a little, so tighter
// matches ("dbrows" → "db: rows") beat scattered ones.

const BOUNDARY = /[\s\-_:/.(]/;

/**
 * Score `query` against `text`. Returns a number (higher = better) or null
 * when the query is not a subsequence of the text. An empty query matches
 * everything with score 0 so the palette can show the full list.
 */
export function fuzzyScore(query, text) {
    if (!query) return 0;
    if (!text) return null;

    const q = query.toLowerCase();
    const t = text.toLowerCase();

    let score = 0;
    let searchFrom = 0;
    let prev = -2;

    for (const ch of q) {
        if (ch === ' ') continue; // spaces separate words, they don't match
        const idx = t.indexOf(ch, searchFrom);
        if (idx === -1) return null;

        score += 1;
        if (idx === prev + 1) score += 3; // contiguous run
        if (idx === 0) score += 4; // very start
        else if (BOUNDARY.test(t[idx - 1])) score += 3; // word boundary

        score -= Math.min(idx - searchFrom, 10) * 0.1; // drift penalty

        prev = idx;
        searchFrom = idx + 1;
    }

    // Prefer shorter targets when hit quality ties.
    return score - t.length * 0.01;
}

/**
 * Filter + rank `items` by fuzzy match. `getText` extracts the searchable
 * string. Ties keep the original order (stable sort over indexed pairs).
 */
export function fuzzyFilter(query, items, getText = (x) => x) {
    return items
        .map((item, index) => ({ item, index, score: fuzzyScore(query, getText(item)) }))
        .filter((entry) => entry.score !== null)
        .sort((a, b) => b.score - a.score || a.index - b.index)
        .map((entry) => entry.item);
}
