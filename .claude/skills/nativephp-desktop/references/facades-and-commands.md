# Facades & Artisan Commands Quick Reference

## Namespace

All facades live under `Native\Desktop\Facades\` (v2). v1 used `Native\Laravel\`.

```php
use Native\Desktop\Facades\Window;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\MenuBar;
use Native\Desktop\Facades\Notification;
use Native\Desktop\Facades\Dialog;
use Native\Desktop\Facades\Alert;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\System;
use Native\Desktop\Facades\Clipboard;
use Native\Desktop\Facades\GlobalShortcut;
use Native\Desktop\Facades\ChildProcess;
use Native\Desktop\Facades\QueueWorker;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\Screen;
use Native\Desktop\Facades\Shell;
use Native\Desktop\Facades\PowerMonitor;
```

Non-facade classes:

```php
use Native\Desktop\Dialog;           // Dialog builder (not a facade)
use Native\Desktop\Facades\Menu;       // Menu builder via Menu::make(), Menu::create()
```

## Artisan Commands

| Command | Purpose |
|---|---|
| `php artisan native:install` | Install/configure NativePHP (run on new machines & CI) |
| `php artisan native:install --publish` | Publish Electron project to `nativephp/electron` |
| `php artisan native:run` | Start dev build (replaces deprecated `native:serve`) |
| `php artisan native:build` | Production build for current platform |
| `php artisan native:build win` | Cross-compile for Windows (`mac`, `win`, `linux`) |
| `php artisan native:publish` | Build + upload to updater provider |
| `php artisan native:publish win` | Cross-compile publish |
| `php artisan native:migrate` | Migrate dev `nativephp.sqlite` database |
| `php artisan native:migrate:fresh` | Destructive refresh of dev database |
| `php artisan native:seed` | Seed dev database |

## Composer Scripts

```json
"scripts": {
    "native:dev": "concurrently \"php artisan native:run\" \"npm run dev\""
}
```

## Key Config Keys (`config/nativephp.php`)

| Key | Purpose |
|---|---|
| `version` | App version — bump every release; triggers migrations |
| `app_id` | Reverse-domain ID (e.g. `com.myapp.desktop`) |
| `deeplink_scheme` | Deep link scheme (e.g. `myapp://path`) |
| `provider` | `NativeAppServiceProvider` class |
| `cleanup_env_keys` | Wildcard keys stripped from `.env` at build |
| `cleanup_exclude_files` | Files/globs removed before bundling |
| `prebuild` / `postbuild` | Shell commands run before/after build |
| `queue_workers` | Named queue worker child process configs |
| `updater` | Auto-update provider config (github, s3, spaces) |

## Storage Disks (runtime)

```php
Storage::disk('user_home');
Storage::disk('desktop');
Storage::disk('documents');
Storage::disk('downloads');
Storage::disk('music');
Storage::disk('pictures');
Storage::disk('videos');
Storage::disk('recent');
Storage::disk('extras');
```

Default `local` disk points to `appdata/storage/app`.

## Events (common)

| Event | When |
|---|---|
| `Native\Desktop\Events\App\ApplicationBooted` | App fully booted |
| `Native\Desktop\Events\Windows\WindowShown` | Window shown |
| `Native\Desktop\Events\Windows\WindowClosed` | Window closed |
| `Native\Desktop\Events\Windows\WindowFocused` | Window focused |
| `Native\Desktop\Events\Windows\WindowBlurred` | Window blurred |
| `Native\Desktop\Events\Notifications\NotificationClicked` | Notification clicked |
| `Native\Desktop\Events\MenuBar\MenuBarShown` | Menu bar window opened |

All native events broadcast on `nativephp` channel. Listen in JS via `Native.on()` or Livewire via `#[On('native:'.EventClass::class)]`.
