// Minimal JSON POST helper that forwards Laravel's XSRF cookie as a header,
// so our non-Inertia endpoints (tinker/logs) pass CSRF verification.

export function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function postJson(url, body = {}) {
    const res = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(body),
    });

    let data = null;
    try {
        data = await res.json();
    } catch {
        // no/invalid JSON body
    }

    return { ok: res.ok, status: res.status, data };
}
