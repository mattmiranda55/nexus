<?php

namespace App\Providers;

use App\Http\Controllers\MailController;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Native\Desktop\Events\Notifications\NotificationClicked;
use Native\Desktop\Facades\Window;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Clicking a new-mail notification brings Nexus forward; the window
        // itself hears the same (broadcast) event and opens the message.
        Event::listen(NotificationClicked::class, function (NotificationClicked $event) {
            if (str_starts_with($event->reference, MailController::NOTIFICATION_PREFIX)) {
                try {
                    Window::show('main');
                } catch (\Throwable) {
                    // No window to show (or no desktop runtime).
                }
            }
        });
    }
}
