<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme' => 'required|in:dark,light',
            'phpPath' => 'nullable|string',
        ]);

        Setting::current()->update([
            'theme' => $data['theme'],
            'php_path' => $data['phpPath'] ?: null,
        ]);

        return back();
    }
}
