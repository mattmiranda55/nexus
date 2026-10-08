// Which project sent a caught email, matched by its From address against
// each project's MAIL_FROM_ADDRESS.

/** address (lowercase) -> names of the projects that send as it */
export function senderIndex(projects) {
    const index = new Map();
    for (const project of projects ?? []) {
        if (!project.mailFrom) continue;
        const names = index.get(project.mailFrom) ?? [];
        names.push(project.name);
        index.set(project.mailFrom, names);
    }
    return index;
}

/**
 * The sending project's name, or null when nobody claims the address — or
 * when several do (a shared default like hello@example.com can't tell them
 * apart, and a wrong tag is worse than none).
 */
export function projectFor(message, index) {
    const names = index.get((message?.from?.address ?? '').toLowerCase());
    return names?.length === 1 ? names[0] : null;
}
