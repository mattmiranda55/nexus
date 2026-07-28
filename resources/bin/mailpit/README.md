# Bundled Mailpit binaries

`MailpitManager` looks here **last** (after `NEXUS_MAILPIT_PATH` and `$PATH`) so
the app can manage its own Mailpit when the user's environment doesn't already
provide one.

Nothing here is committed — `scripts/fetch-mailpit.mjs` populates it:

```
npm run fetch:mailpit             # this machine's platform
node scripts/fetch-mailpit.mjs --all      # all three desktop targets
node scripts/fetch-mailpit.mjs --force    # re-download over what's there
```

which lands the static [Mailpit](https://github.com/axllent/mailpit) binaries at:

```
resources/bin/mailpit/mac/mailpit          # macOS
resources/bin/mailpit/linux/mailpit        # Linux
resources/bin/mailpit/win/mailpit.exe      # Windows
```

`prebuild` in `config/nativephp.php` runs the fetch for the platform being
built, so packaged apps always carry one. It's a no-op when the binary is
already present. These ship inside the packaged app (NativePHP bundles the
Laravel tree) and `MailpitManager::resolveBinary()` resolves them via
`base_path()`.

If none is present and nothing is already listening on the API port, the inbox
shows a friendly setup hint instead of a silent empty state.

> The predecessor to this was `download.sh`, POSIX `sh` using curl/unzip. It
> could not run on Windows, which is precisely where the bundled binary was
> needed — so Windows builds shipped without one and the Mail tab never worked
> there. Keep the replacement runnable on all three platforms.
