<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Native\Desktop\Facades\Shell;

/**
 * Opens one of a fixed set of external pages (config/nexus.php `links`) in the
 * OS browser. The renderer names a key, never a URL, so it can't be used to
 * make the desktop shell open something arbitrary.
 */
class LinkController extends Controller
{
    public function open(string $key): JsonResponse
    {
        $url = config("nexus.links.{$key}");

        if (! is_string($url)) {
            return response()->json(['error' => 'Unknown link'], 404);
        }

        try {
            Shell::openExternal($url);
        } catch (\Throwable) {
            return response()->json(['error' => 'Could not open the browser', 'url' => $url], 422);
        }

        return response()->json(['status' => 'opened']);
    }
}
