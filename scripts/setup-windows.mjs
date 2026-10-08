#!/usr/bin/env node
// Windows setup: everything in WINDOWS_SETUP.txt that a script can do, then
// the normal `npm run setup`.
//
//   npm run setup:windows              (asks before each change)
//   npm run setup:windows -- --yes     (applies everything without asking)
//   node scripts/setup-windows.mjs --dry-run   (prints what it would do; runs anywhere)
//
// Steps:
//   1. Tidy an older checkout: SESSION_DRIVER/CACHE_STORE=file, no bun leftovers
//   2. The normal setup (scripts/setup.mjs)
//   3. Git Bash for the log viewer: find it wherever Git was installed and
//      record it in .env, or offer to install Git with winget
//   4. Windows Defender exclusions for the project folder (one UAC prompt)
//
// Every change is asked about first. Nothing here needs to run as admin
// except step 4, which elevates only the single command that needs it.

import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { createInterface } from 'node:readline/promises';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const ENV = join(ROOT, '.env');
const args = process.argv.slice(2);
const YES = args.includes('--yes') || args.includes('-y');
const DRY = args.includes('--dry-run');

const log = {
    step: (msg) => console.log(`\n\x1b[1m→ ${msg}\x1b[0m`),
    ok: (msg) => console.log(`  \x1b[32m✓\x1b[0m ${msg}`),
    skip: (msg) => console.log(`  \x1b[90m·\x1b[0m ${msg}`),
    warn: (msg) => console.log(`  \x1b[33m!\x1b[0m ${msg}`),
    dry: (msg) => console.log(`  \x1b[36m(dry run)\x1b[0m ${msg}`),
};

if (process.platform !== 'win32' && !DRY) {
    console.log('This script is for Windows. On macOS and Linux, run: npm run setup');
    process.exit(1);
}

const rl = process.stdin.isTTY ? createInterface({ input: process.stdin, output: process.stdout }) : null;

/** y/N question; --yes answers yes, and a non-interactive run answers no. */
async function confirm(question) {
    if (YES) return true;
    if (DRY) return true;
    if (!rl) {
        log.skip(`${question} — skipped (not interactive; pass --yes to apply)`);
        return false;
    }
    const answer = (await rl.question(`  ? ${question} [y/N] `)).trim().toLowerCase();
    return answer === 'y' || answer === 'yes';
}

// --- .env helpers -----------------------------------------------------------

function readEnv() {
    return existsSync(ENV) ? readFileSync(ENV, 'utf8') : null;
}

function envValue(contents, key) {
    const match = contents?.match(new RegExp(`^${key}=(.*)$`, 'm'));
    return match ? match[1].trim().replace(/^["']|["']$/g, '') : null;
}

/**
 * Set KEY=value, keeping the file's own line endings. Values that need quoting
 * get single quotes: dotenv reads those literally, where double quotes would
 * treat the backslashes in a Windows path as escapes.
 */
function setEnv(key, value) {
    const contents = readEnv() ?? '';
    const eol = contents.includes('\r\n') ? '\r\n' : '\n';
    const line = `${key}=${/[\s#"'\\]/.test(value) ? `'${value}'` : value}`;
    const pattern = new RegExp(`^${key}=.*$`, 'm');

    const next = pattern.test(contents)
        ? contents.replace(pattern, () => line)
        : contents.replace(/(\r?\n)?$/, (end) => `${end || (contents ? eol : '')}${line}${eol}`);

    if (DRY) return log.dry(`set ${line} in .env`);
    writeFileSync(ENV, next);
}

// --- 1. An older checkout ---------------------------------------------------

async function tidyOldCheckout() {
    log.step('Older checkout leftovers');
    let found = false;

    const env = readEnv();
    for (const key of ['SESSION_DRIVER', 'CACHE_STORE']) {
        if (envValue(env, key) === 'database') {
            found = true;
            // Database sessions/cache meant SQLite round-trips on every
            // request, and the WAL churn also woke the Vite watcher.
            if (await confirm(`${key} is "database" in .env. Switch it to "file"?`)) {
                setEnv(key, 'file');
                log.ok(`${key}=file`);
            }
        }
    }

    if (existsSync(join(ROOT, 'bun.lock')) || existsSync(join(ROOT, 'bun.lockb'))) {
        found = true;
        if (await confirm('Found a bun lockfile from before the move to npm. Delete it and node_modules (setup reinstalls with npm)?')) {
            for (const path of ['bun.lock', 'bun.lockb', 'node_modules']) {
                if (!existsSync(join(ROOT, path))) continue;
                if (DRY) log.dry(`delete ${path}`);
                else rmSync(join(ROOT, path), { recursive: true, force: true });
            }
            log.ok('Removed bun leftovers');
        }
    }

    if (!found) log.skip('Nothing to tidy');
}

// --- 2. The normal setup ----------------------------------------------------

function runSetup() {
    log.step('Project setup (npm run setup)');
    if (DRY) return log.dry('node scripts/setup.mjs'), true;

    const result = spawnSync(process.execPath, [join(ROOT, 'scripts', 'setup.mjs')], {
        cwd: ROOT,
        stdio: 'inherit',
        env: { ...process.env, NEXUS_SETUP_WINDOWS: '1' },
    });
    return result.status === 0;
}

// --- 3. Git Bash for the log viewer ----------------------------------------

/** Places Git for Windows' bash.exe lands, by how Git was installed. */
function gitBashCandidates() {
    const env = process.env;
    const candidates = [
        env.ProgramFiles && join(env.ProgramFiles, 'Git', 'bin', 'bash.exe'),
        env['ProgramFiles(x86)'] && join(env['ProgramFiles(x86)'], 'Git', 'bin', 'bash.exe'),
        env.LOCALAPPDATA && join(env.LOCALAPPDATA, 'Programs', 'Git', 'bin', 'bash.exe'), // per-user / winget
        env.USERPROFILE && join(env.USERPROFILE, 'scoop', 'apps', 'git', 'current', 'bin', 'bash.exe'),
    ];

    // Wherever git.exe is on PATH, bash.exe sits in that install's bin\.
    try {
        const gits = execFileSync('where', ['git'], { stdio: ['ignore', 'pipe', 'ignore'] }).toString().split(/\r?\n/);
        for (const git of gits.filter(Boolean)) {
            // ...\Git\cmd\git.exe or ...\Git\mingw64\bin\git.exe → ...\Git\bin\bash.exe
            const root = git.replace(/\\(cmd|bin|mingw64\\bin)\\git\.exe$/i, '');
            candidates.push(join(root, 'bin', 'bash.exe'));
        }
    } catch {
        // git isn't on PATH
    }

    return [...new Set(candidates.filter(Boolean))];
}

async function setUpGitBash() {
    log.step('Git Bash (the log viewer needs its `tail`)');

    const configured = envValue(readEnv(), 'NEXUS_GIT_BASH_PATH');
    if (configured && existsSync(configured)) return log.ok(`Using ${configured} (NEXUS_GIT_BASH_PATH)`);

    const defaultPath = process.env.ProgramFiles && join(process.env.ProgramFiles, 'Git', 'bin', 'bash.exe');
    const found = gitBashCandidates().find((path) => DRY || existsSync(path));

    if (found && found === defaultPath && !DRY) return log.ok(`Found ${found}`);

    if (found && !DRY) {
        // Nexus only looks in %ProgramFiles%\Git on its own.
        if (await confirm(`Found Git Bash at ${found}. Save it as NEXUS_GIT_BASH_PATH in .env?`)) {
            setEnv('NEXUS_GIT_BASH_PATH', found);
            log.ok('Saved NEXUS_GIT_BASH_PATH');
        }
        return;
    }

    if (DRY) log.dry(`would look in: ${gitBashCandidates().join(', ') || '(nothing to check here)'}`);
    log.warn('Git for Windows not found.');

    if (!(await confirm('Install Git for Windows with winget?'))) {
        log.skip('Skipped. Install Git later, or choose WSL or PowerShell as the log shell in Settings.');
        return;
    }

    if (DRY) return log.dry('winget install --id Git.Git -e --source winget');

    const result = spawnSync('winget', ['install', '--id', 'Git.Git', '-e', '--source', 'winget', '--accept-package-agreements'], {
        stdio: 'inherit',
    });
    if (result.status === 0) log.ok('Git for Windows installed. Restart Nexus (and this terminal) so it is found.');
    else log.warn('winget could not install Git. Get it from https://git-scm.com/download/win');
}

// --- 4. Defender exclusions -------------------------------------------------

/** PowerShell single-quoted string literal. */
const psQuote = (value) => `'${value.replace(/'/g, "''")}'`;

async function addDefenderExclusions() {
    log.step('Windows Defender exclusions');

    // Dev mode has no config cache, so every request re-opens many small PHP
    // files and Defender scans each one — usually the biggest Windows slowdown.
    const paths = [ROOT, join(ROOT, 'vendor', 'nativephp', 'php-bin')].filter((p) => DRY || existsSync(p));
    console.log(paths.map((p) => `      ${p}`).join('\n'));

    if (!(await confirm('Exclude these folders from Defender scanning? (Windows will ask for admin permission)'))) {
        log.skip('Skipped. See WINDOWS_SETUP.txt to add them by hand.');
        return;
    }

    // Only this one command runs elevated. -EncodedCommand (UTF-16LE base64)
    // sidesteps quoting through two PowerShell layers and the UAC hand-off.
    const inner = `Add-MpPreference -ExclusionPath ${paths.map(psQuote).join(',')} -ErrorAction Stop`;
    const encoded = Buffer.from(inner, 'utf16le').toString('base64');
    const outer = `$p = Start-Process powershell -Verb RunAs -Wait -PassThru -WindowStyle Hidden `
        + `-ArgumentList '-NoProfile','-NonInteractive','-EncodedCommand','${encoded}'; exit $p.ExitCode`;

    if (DRY) return log.dry(`elevated: ${inner}`);

    const result = spawnSync('powershell', ['-NoProfile', '-NonInteractive', '-Command', outer], { stdio: 'inherit' });
    if (result.status === 0) {
        log.ok('Exclusions added');
    } else {
        // Declined UAC, or a third-party antivirus has replaced Defender.
        log.warn('Could not add the exclusions (permission declined, or Defender isn\'t the active antivirus). See WINDOWS_SETUP.txt to add them by hand.');
    }
}

// ----------------------------------------------------------------------------

try {
    await tidyOldCheckout();
    const ok = runSetup();
    await setUpGitBash();
    await addDefenderExclusions();

    console.log(ok
        ? '\n\x1b[32mWindows setup done.\x1b[0m Start the app with:  composer native:dev\n'
        : '\n\x1b[31mProject setup reported errors — see above.\x1b[0m The Windows steps still ran.\n');
    process.exitCode = ok ? 0 : 1;
} finally {
    rl?.close();
}
