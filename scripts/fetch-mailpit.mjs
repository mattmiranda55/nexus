#!/usr/bin/env node
// Downloads Mailpit into DIR/{mac,win,linux}/ for Nexus to run. Nexus no
// longer bundles Mailpit: the user downloads it from Settings, and the app
// runs this script (as a ChildProcess on Electron's Node) with a --dest inside
// its own storage.
//
// Every download is verified against a pinned SHA-256 (or, for a non-default
// MAILPIT_VERSION, the digests GitHub publishes for the release).
//
// Mailpit is MIT-licensed (https://github.com/axllent/mailpit). We also fetch
// its LICENSE next to the binaries to satisfy the notice requirement.
//
//   node scripts/fetch-mailpit.mjs --dest=DIR            # this machine's platform
//   node scripts/fetch-mailpit.mjs --dest=DIR --all      # all three desktop targets
//   node scripts/fetch-mailpit.mjs --dest=DIR --force    # re-download even if present
//
// Overrides: MAILPIT_VERSION, MAILPIT_MAC_ARCH, MAILPIT_WIN_ARCH,
//            MAILPIT_LINUX_ARCH  (amd64 | arm64 | 386)

import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const DEFAULT_VERSION = 'v1.30.5';
const VERSION = process.env.MAILPIT_VERSION || DEFAULT_VERSION;
const BASE = `https://github.com/axllent/mailpit/releases/download/${VERSION}`;

// SHA-256 of each release asset for the default version, as published on the
// GitHub release. These are executables that end up bundled into the app, so
// a download is only accepted if it matches. For any other MAILPIT_VERSION the
// digests are looked up from the GitHub release API instead.
const PINNED_SHA256 = {
    'mailpit-darwin-amd64.tar.gz': '0e49cd351f1a72297ab4879c57895b15686bea534bf66037e8ad2738868617ed',
    'mailpit-darwin-arm64.tar.gz': '8713a5665dc6ba16e08f2b6b70a6ddd490ade23407d18ee59e5a9fceee47f171',
    'mailpit-linux-386.tar.gz': 'fa9ad106b86c54aefd9802fd1483d90ec07f82badfabffdedfc551466f094c40',
    'mailpit-linux-amd64.tar.gz': 'b1499b23e6207d2728a0f154fd27370d0f608dd8ae1a63b734d089dc7f7a8d6f',
    'mailpit-linux-arm64.tar.gz': 'c4c0a587770af1d5fb192e7b580aebfc03115c93da2af308a4b2e227c7d06cd2',
    'mailpit-windows-amd64.zip': 'e9663820476f6ac6bb642ac4d7c4e5c514ff42013137447b5163548d49fd8c88',
    'mailpit-windows-arm64.zip': '19d98b1aac56b9e1ce2c4115b97b4823b8cf1267106e9b18fbd5d2c3f739036c',
};

const destArg = process.argv.find((arg) => arg.startsWith('--dest='));
const DEST = destArg?.slice('--dest='.length);

if (!DEST) {
    console.error('Pass --dest=DIR: where to install Mailpit.');
    process.exit(2);
}

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
    const data = Buffer.from(await res.arrayBuffer());
    writeFileSync(to, data);
    return data;
}

let releaseDigests = null;

/** The expected SHA-256 for an asset: pinned, or from the release's API record. */
async function expectedSha256(asset) {
    if (VERSION === DEFAULT_VERSION && PINNED_SHA256[asset]) return PINNED_SHA256[asset];

    if (releaseDigests === null) {
        const res = await fetch(`https://api.github.com/repos/axllent/mailpit/releases/tags/${VERSION}`, {
            headers: { Accept: 'application/vnd.github+json' },
        });
        if (!res.ok) throw new Error(`Could not look up checksums for ${VERSION} (${res.status}).`);
        const release = await res.json();
        releaseDigests = Object.fromEntries(
            (release.assets ?? [])
                .filter((a) => typeof a.digest === 'string' && a.digest.startsWith('sha256:'))
                .map((a) => [a.name, a.digest.slice('sha256:'.length)]),
        );
    }

    const digest = releaseDigests[asset];
    if (!digest) throw new Error(`No published checksum for ${asset} in ${VERSION}; refusing to install it unverified.`);
    return digest;
}

function verify(data, expected, name) {
    const actual = createHash('sha256').update(data).digest('hex');
    if (actual !== expected.toLowerCase()) {
        throw new Error(`Checksum mismatch for ${name}: expected ${expected}, got ${actual}.`);
    }
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
        // PowerShell single quotes escape by doubling, and a temp path can
        // contain one (C:\Users\O'Brien\...).
        const ps = (value) => `'${value.replace(/'/g, "''")}'`;
        execFileSync('powershell', [
            '-NoProfile',
            '-NonInteractive',
            '-Command',
            `Expand-Archive -LiteralPath ${ps(archive)} -DestinationPath ${ps(into)} -Force`,
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
        const expected = await expectedSha256(name);
        verify(await download(`${BASE}/${name}`, archive), expected, name);
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

console.log(`Fetching Mailpit ${VERSION} → ${DEST}`);

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

// Lets Nexus show which version is installed.
writeFileSync(join(DEST, 'VERSION'), VERSION + '\n');

console.log(`✓ Mailpit ${VERSION} ready (host: ${HOST ?? 'unknown'}/${ARCH})`);
