# Bundled Mailpit binaries

`MailpitManager` looks here **last** (after `NEXUS_MAILPIT_PATH` and `$PATH`) so
the app can manage its own Mailpit when the user's environment doesn't already
provide one.

Drop the static [Mailpit](https://github.com/axllent/mailpit) binary for each
target OS at:

```
resources/bin/mailpit/mac/mailpit          # macOS (universal or arch-specific)
resources/bin/mailpit/linux/mailpit        # Linux
resources/bin/mailpit/win/mailpit.exe      # Windows
```

These ship inside the packaged app (NativePHP bundles the Laravel tree), and
`MailpitManager::resolveBinary()` resolves them via `base_path()`. Make the
Unix binaries executable (`chmod +x`). A convenient place to fetch them at build
time is the `prebuild` hook in `config/nativephp.php`.

If none is present and nothing is already listening on the API port, the inbox
shows a friendly setup hint instead of a silent empty state.
