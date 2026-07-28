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

const prerequisites = [
    { name: 'PHP', command: 'php', args: ['-v'], hint: 'https://php.net/downloads (8.2+). Laravel Herd bundles one.' },
    { name: 'Composer', command: 'composer', args: ['-V'], hint: 'https://getcomposer.org/download/' },
    { name: 'Node', command: 'node', args: ['-v'], hint: 'https://nodejs.org (20+)' },
    { name: 'npm', command: 'npm', args: ['-v'], hint: 'ships with Node' },
];

for (const { name, command, args, hint } of prerequisites) {
    const found = version(command, args);
    found ? log.ok(`${name} — ${found}`) : log.fail(`${name} not found on PATH. ${hint}`);
}

if (failed) {
    console.log('\nInstall the missing tools above, then run this again.\n');
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

log.step('Mailpit binary');

run('node', [join('scripts', 'fetch-mailpit.mjs')], { optional: true });

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

if (WINDOWS) {
    log.step('Windows notes');
    console.log(`
  Two things this script can't do for you:

  1. Defender exclusions. Add the project folder and
     vendor\\nativephp\\php-bin under
     Virus & threat protection > Exclusions > Folder.
     Every PHP include is a file open, and dev mode has no config cache.

  2. A real 'tail' for the log viewer. Install Git for Windows (its bash
     ships GNU tail), or set NEXUS_GIT_BASH_PATH in .env, or pick a
     different log shell in Settings.

  See WINDOWS_SETUP.txt for the rest.`);
}

console.log(failed
    ? '\n\x1b[31mSetup finished with errors — see above.\x1b[0m\n'
    : '\n\x1b[32mReady.\x1b[0m Start the app with:  composer native:dev\n');

process.exit(failed ? 1 : 0);
