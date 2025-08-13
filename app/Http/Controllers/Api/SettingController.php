<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config; // To access config values
use Illuminate\Support\Facades\File;    // To write to .env file (use with caution)
use Illuminate\Support\Facades\Artisan; // To clear config cache
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Log;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display the current application settings.
     */
    public function index(Request $request)
    {
        // $this->checkAuthorization('view-settings'); // Policy or Gate check
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
        $this->checkAuthorization('update-settings');
        $service = new SettingsService();
        $rules = $service->validationRules();
        Log::info('Settings update request data:', $request->all());
        $validated = $request->validate($rules);
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
        $this->checkAuthorization('update-settings');

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $path = $file->store('logos', 'public');
        // Build absolute URL that respects subdirectory deployments (e.g., /sales-api/public)
        $publicUrl = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . Storage::url($path);

        $service = new SettingsService();
        $newSettings = $service->update(['company_logo_url' => $publicUrl]);

        return response()->json([
            'message' => 'Logo uploaded successfully.',
            'url' => $publicUrl,
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