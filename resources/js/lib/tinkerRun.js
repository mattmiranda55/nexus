// Waits for a background tinker run (see TinkerController) to finish.
//
// The worker prints DONE on stdout once its result is stored, and the process
// exiting is reported too; either one triggers a fetch of GET /tinker/{id}.
// A backed-off fallback check covers missed events, and the server gives up on
// a run that has gone silent, so this always settles.

export const DONE = '__NEXUS_TINKER_DONE__';

// ms before each fallback check; the last value repeats.
const FALLBACK_DELAYS = [1000, 2000, 4000, 8000, 10000];

/**
 * Start listening for run `id` — before POSTing it, so a fast run can't
 * finish unobserved. Call `result()` once the server says it is running, or
 * `stop()` if it answered inline instead. `cancel(request)` ends a pending
 * `result()` with whatever `request()` resolves to (the stop endpoint's
 * response) — unless the run's real result arrives first.
 *
 * deps: { fetchResult(id, exited) -> {status, data}, onMessage, onExit, delays? }
 */
export function watchTinkerRun(id, { fetchResult, onMessage, onExit, delays = FALLBACK_DELAYS }) {
    const alias = `tinker-${id}`;
    let signalled = false;
    let exited = false;
    let wake = null;
    let cancelled = null; // promise of the cancel request's response

    const signal = () => {
        signalled = true;
        wake?.();
    };

    const stops = [
        onMessage(alias, (data) => {
            if (String(data).includes(DONE)) signal();
        }),
        onExit(alias, () => {
            exited = true;
            signal();
        }),
    ];

    function stop() {
        stops.forEach((s) => s());
        wake?.();
    }

    // Unsubscribe first: the kill's exit event must not trigger a fetch that
    // races the stop request.
    function cancel(request) {
        if (!cancelled) {
            cancelled = request();
            stop();
        }
        return cancelled;
    }

    function sleep(ms) {
        return new Promise((resolve) => {
            if (signalled) return resolve();
            const timer = setTimeout(done, ms);
            function done() {
                clearTimeout(timer);
                wake = null;
                resolve();
            }
            wake = done;
        });
    }

    async function result() {
        try {
            for (let attempt = 0; ; attempt++) {
                await sleep(delays[Math.min(attempt, delays.length - 1)]);
                if (cancelled) return cancelled;
                signalled = false;
                const response = await fetchResult(id, exited);
                // A finished run beats a stop that came too late.
                if (response.status !== 202) return response;
                if (cancelled) return cancelled;
            }
        } finally {
            stop();
        }
    }

    return { result, stop, cancel };
}
