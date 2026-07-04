<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Services\SettingsService;

class SettingController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display the current application settings.
     */
    public function index(Request $request)
    {
        $service = new SettingsService();
        $settings = $service->getAll();
        return response()->json(['data' => $settings]);
    }

    /**
     * Update application settings.
     * This method updates the .env file. BE CAREFUL.
     * A database approach is generally safer and more robust.
     */
    public function update(Request $request)
    {
        $service = new SettingsService();
        $rules = $service->validationRules();
       // Log::info('Settings update request data:', $request->all());
        $validated = $request->validate($rules);

        // Authorization logic:
        $isUpdatingDollarRate = $request->has('usd_to_sdg_factor');
        $isUpdatingOtherSettings = count(array_diff(array_keys($validated), ['usd_to_sdg_factor'])) > 0;

        // If updating general settings (anything except/besides dollar rate)
      
        // If updating dollar rate
     

        $newSettings = $service->update($validated);
        return response()->json([
            'message' => 'Settings updated successfully.',
            'data' => $newSettings,
        ]);
    }

    /**
     * Upload and set company logo.
     */
    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
        ]);

        $path = $request->file('logo')->store('logos', 'public');

        $service = new SettingsService();
        $newSettings = $service->update(['company_logo_url' => $path]);

        return response()->json([
            'message' => 'Logo uploaded successfully.',
            'data' => $newSettings,
        ]);
    }

    /**
     * Upload and set company header image.
     */
    public function uploadHeader(Request $request)
    {
        $request->validate([
            'header' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:5120'],
        ]);

        $path = $request->file('header')->store('headers', 'public');

        $service = new SettingsService();
        $newSettings = $service->update(['company_header_url' => $path]);

        return response()->json([
            'message' => 'Header image uploaded successfully.',
            'data' => $newSettings,
        ]);
    }

    /**
     * Upload and set company stamp image.
     */
    public function uploadStamp(Request $request)
    {
        $request->validate([
            'stamp' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $path = $request->file('stamp')->store('stamps', 'public');

        $service = new SettingsService();
        $newSettings = $service->update(['company_stamp_url' => $path]);

        return response()->json([
            'message' => 'Stamp uploaded successfully.',
            'data' => $newSettings,
        ]);
    }

    /**
     * Upload and set company signature image.
     */
    public function uploadSignature(Request $request)
    {
        $request->validate([
            'signature' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $path = $request->file('signature')->store('signatures', 'public');

        $service = new SettingsService();
        $newSettings = $service->update(['company_signature_url' => $path]);

        return response()->json([
            'message' => 'Signature uploaded successfully.',
            'data' => $newSettings,
        ]);
    }

    /**
     * Helper to authorize based on permission string
     */
    private function checkAuthorization(string $permission): void
    {
        if (Auth::user() && !Auth::user()->can($permission)) {
            abort(403, 'This action is unauthorized.');
        } elseif (!Auth::user()) {
            abort(401, 'Unauthenticated.');
        }
    }
}
