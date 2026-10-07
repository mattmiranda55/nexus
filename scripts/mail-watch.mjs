#!/usr/bin/env node
// Watches a mail catcher for new mail and prints one line per change:
//
//   node scripts/mail-watch.mjs <websocket|signalr> <ws-url>
//
// Nexus runs this as a NativePHP ChildProcess; each stdout line reaches the
// inbox as a MessageReceived event, which then re-fetches the list. It exists
// because the app window can't hold these sockets itself: Mailpit refuses
// websocket connections whose Origin isn't its own host, and Node sends none.
//
// Dependency-free on purpose (Node 22+ has a global WebSocket): it runs from
// the packaged app on Electron's bundled Node.

const [type, url] = process.argv.slice(2);
const CHANGED = '__NEXUS_MAIL_CHANGED__';

// SignalR's JSON protocol: frames end with the ASCII record separator.
const RS = '\x1e';

if (!['websocket', 'signalr'].includes(type) || !/^wss?:\/\//.test(url ?? '')) {
    console.error('usage: mail-watch.mjs <websocket|signalr> <ws-url>');
    process.exit(2);
}

let retry = 0;
let debounce = null;

// Bursts (a queued job sending ten mails) become one refresh.
function changed() {
    clearTimeout(debounce);
    debounce = setTimeout(() => console.log(CHANGED), 250);
}

function connect() {
    const ws = new WebSocket(url);
    let ping = null;

    ws.addEventListener('open', () => {
        retry = 0;
        // Report the (re)connect too: mail may have arrived while we were away.
        changed();

        if (type === 'signalr') {
            ws.send(JSON.stringify({ protocol: 'json', version: 1 }) + RS);
            // The server drops a client that stays silent for ~30s.
            ping = setInterval(() => ws.send(JSON.stringify({ type: 6 }) + RS), 15000);
        }
    });

    ws.addEventListener('message', (event) => {
        if (type === 'websocket') return changed();

        for (const frame of String(event.data).split(RS)) {
            if (!frame) continue;
            try {
                const msg = JSON.parse(frame);
                if (msg.type === 1 && msg.target === 'messageschanged') changed();
            } catch {
                // handshake ack "{}" or noise
            }
        }
    });

    ws.addEventListener('close', () => {
        clearInterval(ping);
        // Back off 1s → 30s while the catcher is down (restarting, rebooting).
        const delay = Math.min(30000, 1000 * 2 ** retry++);
        setTimeout(connect, delay);
    });

    ws.addEventListener('error', () => {
        // 'close' follows and schedules the retry.
    });
}

connect();
