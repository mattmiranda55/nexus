#!/usr/bin/env node
// Downloads Mailpit static binaries into resources/bin/mailpit/{mac,win,linux}/
// so MailpitManager::resolveBinary() can bundle them.
//
// This replaces the old download.sh. That was POSIX sh using curl/unzip/mktemp,
// which meant it could never run on the one platform that most needed it —
// Windows shipped without a Mailpit binary because nothing could fetch one.
//
// Mailpit is MIT-licensed (https://github.com/axllent/mailpit). We also fetch
// its LICENSE next to the binaries to satisfy the notice requirement.
//
//   node scripts/fetch-mailpit.mjs           # this machine's platform only
//   node scripts/fetch-mailpit.mjs --all     # all three desktop targets
//   node scripts/fetch-mailpit.mjs --force   # re-download even if present
//
// Overrides: MAILPIT_VERSION, MAILPIT_MAC_ARCH, MAILPIT_WIN_ARCH,
//            MAILPIT_LINUX_ARCH  (amd64 | arm64 | 386)

import { execFileSync } from 'node:child_process';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const VERSION = process.env.MAILPIT_VERSION || 'v1.30.5';
const BASE = `https://github.com/axllent/mailpit/releases/download/${VERSION}`;

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const DEST = join(ROOT, 'resources', 'bin', 'mailpit');

const force = process.argv.includes('--force');
const all = process.argv.includes('--all');

/** Node's process.arch → Mailpit's release-asset arch token. */
const ARCH = { x64: 'amd64', arm64: 'arm64', ia32: '386' }[process.arch] ?? 'amd64';

/** Node's process.platform → our per-OS directory. */
const HOST = { darwin: 'mac', win32: 'win', linux: 'linux' }[process.platform] ?? null;

// The host target gets this machine's real architecture; cross-compiled targets
// default to the safe common denominator. All three are overridable.
const TARGETS = {
    mac: {
        arch: process.env.MAILPIT_MAC_ARCH || (HOST === 'mac' ? ARCH : 'arm64'),
        asset: (arch) => `mailpit-darwin-${arch}.tar.gz`,
        binary: 'mailpit',
    },
    linux: {
        arch: process.env.MAILPIT_LINUX_ARCH || (HOST === 'linux' ? ARCH : 'amd64'),
        asset: (arch) => `mailpit-linux-${arch}.tar.gz`,
        binary: 'mailpit',
    },
    win: {
        arch: process.env.MAILPIT_WIN_ARCH || (HOST === 'win' ? ARCH : 'amd64'),
        asset: (arch) => `mailpit-windows-${arch}.zip`,
        binary: 'mailpit.exe',
    },
};

async function download(url, to) {
    const res = await fetch(url); // fetch follows GitHub's redirect to the CDN
    if (!res.ok) throw new Error(`${res.status} ${res.statusText} for ${url}`);
    writeFileSync(to, Buffer.from(await res.arrayBuffer()));
}

/**
 * bsdtar reads both gzip and zip, and ships in macOS and Windows 10 1803+.
 * GNU tar (Linux) can't do zip — but Linux only ever gets a .tar.gz, so the
 * fallbacks below exist purely for an unusually old Windows.
 */
function extract(archive, into) {
    try {
        execFileSync('tar', ['-xf', archive, '-C', into], { stdio: 'pipe' });
        return;
    } catch (error) {
        if (!archive.endsWith('.zip')) throw error;
    }

    if (process.platform === 'win32') {
        execFileSync('powershell', [
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            `Expand-Archive -LiteralPath '${archive}' -DestinationPath '${into}' -Force`,
        ], { stdio: 'pipe' });
    } else {
        execFileSync('unzip', ['-qo', archive, '-d', into], { stdio: 'pipe' });
    }
}

async function fetchTarget(os) {
    const { arch, asset, binary } = TARGETS[os];
    const dir = join(DEST, os);
    const final = join(dir, binary);

    if (existsSync(final) && !force) {
        console.log(`  ${os}: already present (--force to re-download)`);
        return;
    }

    const name = asset(arch);
    console.log(`  ${os}: ${name}`);

    const tmp = mkdtempSync(join(tmpdir(), 'mailpit-'));
    try {
        const archive = join(tmp, name);
        await download(`${BASE}/${name}`, archive);
        extract(archive, tmp);

        mkdirSync(dir, { recursive: true });
        renameSync(join(tmp, binary), final);
        if (binary !== 'mailpit.exe') chmodSync(final, 0o755);
    } finally {
        rmSync(tmp, { recursive: true, force: true });
    }
}

const targets = all ? Object.keys(TARGETS) : [HOST];

if (!targets[0]) {
    console.error(`Unrecognised platform: ${process.platform}. Use --all, or set the binary path with NEXUS_MAILPIT_PATH.`);
    process.exit(1);
}

console.log(`Fetching Mailpit ${VERSION} → resources/bin/mailpit/`);

for (const os of targets) {
    await fetchTarget(os);
}

// License compliance: keep Mailpit's MIT notice with the bundled binaries.
const license = join(DEST, 'LICENSE.mailpit');
if (!existsSync(license) || force) {
    await download(
        `https://raw.githubusercontent.com/axllent/mailpit/${VERSION}/LICENSE`,
        license,
    );
}

console.log(`✓ Mailpit ${VERSION} ready (host: ${HOST ?? 'unknown'}/${ARCH})`);
