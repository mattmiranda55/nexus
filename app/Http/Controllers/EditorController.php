<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\EditorUrlBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\Shell;

/**
 * Click-to-source: opens a `file:line` from a log stack frame in the user's
 * editor via its URL scheme (configured in Settings). The actual open goes
 * through NativePHP's Shell, so it only works inside the desktop runtime.
 */
class EditorController extends Controller
{
    public function open(Request $request, EditorUrlBuilder $urls): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|string',
            'line' => 'nullable|integer|min:1',
        ]);

        $editor = Setting::current()->editor ?: 'phpstorm';
        $url = $urls->build($editor, $data['file'], $data['line'] ?? 1);

        try {
            Shell::openExternal($url);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not open editor: '.$e->getMessage()], 422);
        }

        return response()->json(['status' => 'opened', 'url' => $url]);
    }
}
