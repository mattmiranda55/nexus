#!/usr/bin/env node
// One-shot project initialisation. Safe to re-run — every step is idempotent.
//
//   node scripts/setup.mjs          (or: npm run setup)
//
// Deliberately does NOT install PHP, Composer or Node itself. Those need admin
// rights and differ wildly per machine; the script checks for them and tells
// you what's missing instead of guessing.

import { execFileSync, spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const WINDOWS = process.platform === 'win32';

let failed = false;

const log = {
    step: (msg) => console.log(`\n\x1b[1m→ ${msg}\x1b[0m`),
    ok: (msg) => console.log(`  \x1b[32m✓\x1b[0m ${msg}`),
    skip: (msg) => console.log(`  \x1b[90m·\x1b[0m ${msg}`),
    warn: (msg) => console.log(`  \x1b[33m!\x1b[0m ${msg}`),
    fail: (msg) => { failed = true; console.log(`  \x1b[31m✗\x1b[0m ${msg}`); },
};

/**
 * npm and composer are batch shims on Windows, which spawn() can't execute
 * without a shell. Everything here is a fixed command string with no user
 * input, so the shell is safe.
 */
function run(command, args, { cwd = ROOT, optional = false } = {}) {
    const result = spawnSync(command, args, { cwd, stdio: 'inherit', shell: WINDOWS });

    if (result.status !== 0) {
        const label = `${command} ${args.join(' ')}`;
        optional ? log.warn(`${label} failed — continuing`) : log.fail(`${label} failed`);
        return false;
    }

    return true;
}

function version(command, args) {
    try {
        return execFileSync(command, args, { stdio: 'pipe', shell: WINDOWS })
            .toString()
            .split('\n')[0]
            .trim();
    } catch {
        return null;
    }
}

// ---------------------------------------------------------------------------

log.step('Checking prerequisites');

// Minimums: PHP from composer.json (^8.3), Node from NativePHP's Electron
// package (engines.node >=22). Presence alone isn't enough — an older PHP
// fails composer install with a wall of platform errors, an older Node fails
// deep inside the Electron build.
const prerequisites = [
    { name: 'PHP', command: 'php', args: ['-v'], min: [8, 3], hint: 'https://php.net/downloads (8.3+). Laravel Herd bundles one.' },
    { name: 'Composer', command: 'composer', args: ['-V'], hint: 'https://getcomposer.org/download/' },
    { name: 'Node', command: 'node', args: ['-v'], min: [22, 0], hint: 'https://nodejs.org (22+)' },
    { name: 'npm', command: 'npm', args: ['-v'], hint: 'ships with Node' },
];

/** First "major.minor" in a version banner, e.g. "PHP 8.4.1 (cli)" -> [8, 4]. */
function parseVersion(text) {
    const match = text.match(/(\d+)\.(\d+)/);
    return match ? [Number(match[1]), Number(match[2])] : null;
}

function atLeast([major, minor], [minMajor, minMinor]) {
    return major > minMajor || (major === minMajor && minor >= minMinor);
}

for (const { name, command, args, min, hint } of prerequisites) {
    const found = version(command, args);
    if (!found) {
        log.fail(`${name} not found on PATH. ${hint}`);
        continue;
    }

    const parsed = min ? parseVersion(found) : null;
    if (min && parsed && !atLeast(parsed, min)) {
        log.fail(`${name} — ${found} is too old; ${min.join('.')}+ is required. ${hint}`);
        continue;
    }

    log.ok(`${name} — ${found}`);
}

if (failed) {
    console.log('\nInstall or upgrade the tools above, then run this again.\n');
    process.exit(1);
}

// ---------------------------------------------------------------------------

log.step('Environment file');

const env = join(ROOT, '.env');

if (existsSync(env)) {
    log.skip('.env already exists — leaving it alone');
} else {
    copyFileSync(join(ROOT, '.env.example'), env);
    log.ok('.env created from .env.example');
}

// ---------------------------------------------------------------------------

log.step('PHP dependencies');

run('composer', ['install']);

if (!readFileSync(env, 'utf8').match(/^APP_KEY=.+$/m)) {
    run('php', ['artisan', 'key:generate']);
} else {
    log.skip('APP_KEY already set');
}

// ---------------------------------------------------------------------------

log.step('JavaScript dependencies');

run('npm', ['install']);

// The repo's .npmrc sets ignore-scripts=true. That's a good default, but a few
// packages (esbuild in particular) install their platform binary from a
// postinstall hook, so flag it rather than let the first build fail obscurely.
if (existsSync(join(ROOT, 'node_modules', 'esbuild'))) {
    const esbuild = version(join(ROOT, 'node_modules', '.bin', 'esbuild'), ['--version']);
    esbuild
        ? log.ok(`esbuild — ${esbuild}`)
        : log.warn('esbuild binary missing (.npmrc sets ignore-scripts). Fix: npm rebuild esbuild --ignore-scripts=false');
}

// ---------------------------------------------------------------------------

log.step('Electron runtime');

// Electron's npm package is a thin wrapper; the actual runtime is downloaded
// by its postinstall script, which writes dist/ plus a path.txt naming the
// executable inside it. That script can be skipped (ignore-scripts, or npm
// not re-running it on an already-installed package) and the install still
// exits 0 — the failure only shows up when native:run can't find the binary.
// Electron's own install.js is the same download, so run it directly.
const electronPkg = join(ROOT, 'vendor', 'nativephp', 'desktop', 'resources', 'electron', 'node_modules', 'electron');

function electronBinaryPresent() {
    const pathFile = join(electronPkg, 'path.txt');
    if (!existsSync(pathFile)) return false;
    return existsSync(join(electronPkg, 'dist', readFileSync(pathFile, 'utf8').trim()));
}

if (!existsSync(electronPkg)) {
    log.skip('Electron dependencies not installed yet — `composer native:dev:deps` installs them on first run');
} else if (electronBinaryPresent()) {
    log.ok('Electron binary present');
} else {
    log.warn('Electron package is installed but its binary is missing — downloading it');
    // Relative to cwd on purpose: run() goes through a shell on Windows, and an
    // absolute path under a profile with a space in it would split apart.
    if (run('node', ['install.js'], { cwd: electronPkg }) && electronBinaryPresent()) {
        log.ok('Electron binary downloaded');
    } else {
        log.fail('Could not download the Electron binary. Retry with: node vendor/nativephp/desktop/resources/electron/node_modules/electron/install.js');
    }
}

// ---------------------------------------------------------------------------

log.step('Databases');

// Two of them, and the distinction matters. The app running inside Electron
// reads database/nativephp.sqlite, because NativeServiceProvider repoints
// database.default whenever NATIVEPHP_RUNNING is set — which is only true for
// the PHP process Electron spawns. Plain `artisan` from a shell uses
// database/database.sqlite. `native:migrate` does the repoint itself, so it is
// the one that actually migrates what the app reads.
mkdirSync(join(ROOT, 'database'), { recursive: true });

const cli = join(ROOT, 'database', 'database.sqlite');
if (!existsSync(cli)) {
    writeFileSync(cli, '');
    log.ok('database/database.sqlite created');
}

run('php', ['artisan', 'migrate', '--force']); // the shell-side one
run('php', ['artisan', 'native:migrate', '--force']); // the one the app reads

// ---------------------------------------------------------------------------

log.step('Frontend build');

run('npm', ['run', 'build']);

// ---------------------------------------------------------------------------

// setup-windows.mjs runs this script itself and then does the Windows steps.
if (WINDOWS && !process.env.NEXUS_SETUP_WINDOWS) {
    log.step('Windows');
    console.log(`
  Run  npm run setup:windows  to also add the Defender exclusions and find
  Git Bash for the log viewer (it asks before each change).
  See WINDOWS_SETUP.txt for what it does.`);
}

console.log(failed
    ? '\n\x1b[31mSetup finished with errors — see above.\x1b[0m\n'
    : '\n\x1b[32mReady.\x1b[0m Start the app with:  composer native:dev\n');

process.exit(failed ? 1 : 0);
