<?php

namespace App\Http\Controllers;

use App\Models\PushDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class PushDeviceController extends Controller
{
    /**
     * The session key holding the token this browser registered, so logging out can
     * forget the device (see ForgetPushDeviceOnLogout).
     */
    public const SESSION_KEY = 'push_device_token';

    /**
     * Register the Android app's push token for the signed-in user. Called by the web
     * page inside the app (resources/js/push.js) on every app start.
     */
    public function store(Request $request): Response
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['required', Rule::in([PushDevice::PLATFORM_ANDROID])],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        // Looked up by token across users on purpose: a token belongs to one app install,
        // and only that install knows it. When another account signs in on the phone, the
        // device moves to that account so the previous one stops getting its notifications.
        $device = PushDevice::firstOrNew(['token' => $validated['token']]);
        $device->user()->associate($request->user());
        $device->fill([
            'platform' => $validated['platform'],
            'name' => $validated['name'] ?? null,
            'last_seen_at' => now(),
        ]);
        $device->save();

        $request->session()->put(self::SESSION_KEY, $device->token);

        return response()->noContent();
    }
}
