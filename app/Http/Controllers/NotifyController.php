<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Notification;

/**
 * Fires an OS notification for a log event (error/critical). Gated on the
 * global `notify_errors` setting; the renderer decides *when* to call this
 * (throttled), we just own the native handoff.
 */
class NotifyController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:120',
            'body' => 'nullable|string|max:500',
        ]);

        if (! Setting::current()->notify_errors) {
            return response()->json(['status' => 'disabled']);
        }

        try {
            Notification::title($data['title'])
                ->message($data['body'] ?? '')
                ->show();
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['status' => 'sent']);
    }
}
