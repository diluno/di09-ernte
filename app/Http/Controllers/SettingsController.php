<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function updateTweaks(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme'   => 'sometimes|in:paper,dark',
            'density' => 'sometimes|in:comfortable,compact',
            'accent'  => 'sometimes|regex:/^#[0-9a-fA-F]{6}$/',
        ]);

        $user = $request->user();
        $user->settings = array_merge($user->settings ?? [], $data);
        $user->save();

        return back();
    }
}
