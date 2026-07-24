// Minimal JSON POST helper that forwards Laravel's XSRF cookie as a header,
// so our non-Inertia endpoints (tinker/logs) pass CSRF verification.

export function csrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export async function sendJson(method, url, body = null) {
    const init = {
        method,
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
    };
    if (body !== null) {
        init.headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(body);
    }

    const res = await fetch(url, init);

    let data = null;
    try {
        data = await res.json();
    } catch {
        // no/invalid JSON body
    }

    return { ok: res.ok, status: res.status, data };
}

export function postJson(url, body = {}) {
    return sendJson('POST', url, body);
}

export function getJson(url) {
    return sendJson('GET', url);
}

export function deleteJson(url) {
    return sendJson('DELETE', url);
}
