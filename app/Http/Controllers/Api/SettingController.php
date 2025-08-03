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

class SettingController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display the current application settings.
     */
    public function index(Request $request)
    {
        // $this->checkAuthorization('view-settings'); // Policy or Gate check

        // Fetch settings directly from the config file
        // We use 'app_settings' as the config file name (config/app_settings.php)
        $settings = config('app_settings');

        // Filter out any null values if desired, or return all
        // $settings = array_filter($settings, fn($value) => !is_null($value));

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

        // Get current settings to know which keys are valid
        $currentSettings = config('app_settings');
        $validKeys = array_keys($currentSettings);

        // Define validation rules based on expected types from config
        $rules = [];
        foreach ($currentSettings as $key => $value) {
            $rule = ['nullable', 'string', 'max:255']; // Default
            if (is_int($value)) {
                $rule = ['nullable', 'integer', 'min:0'];
            } elseif (is_bool($value)) {
                $rule = ['nullable', 'boolean'];
            } elseif ($key === 'company_email') {
                $rule = ['nullable', 'email', 'max:255'];
            } elseif ($key === 'currency_symbol') {
                $rule = ['nullable', 'string', 'max:5']; // Currency symbol is now optional
            }
            $rules[$key] = $rule;
        }
        
        // Ensure whatsapp_enabled is properly validated as boolean
        if (isset($rules['whatsapp_enabled'])) {
            $rules['whatsapp_enabled'] = ['nullable', 'boolean'];
        }

        // Debug: Log the incoming data
        Log::info('Settings update request data:', $request->all());
        Log::info('Validation rules:', $rules);
        
        $validatedData = $request->validate($rules);

        // --- Updating .env file ---
        // This is a sensitive operation. Ensure proper server permissions and backups.
        $envFilePath = base_path('.env');
        $envFileContent = File::get($envFilePath);

        foreach ($validatedData as $key => $value) {
            if (!in_array($key, $validKeys)) continue; // Skip if key not in our defined settings

            $envKey = 'APP_SETTINGS_' . strtoupper($key); // Match .env variable naming convention
            
            // Handle boolean values properly for .env file
            if ($key === 'whatsapp_enabled') {
                $escapedValue = $value ? 'true' : 'false';
            } else {
                $escapedValue = is_string($value) && (str_contains($value, ' ') || str_contains($value, '#')) ? "\"{$value}\"" : $value;
                $escapedValue = is_null($value) ? '' : $escapedValue; // Handle null to empty string for .env
            }

            // Replace or add the line in .env
            if (str_contains($envFileContent, "{$envKey}=")) {
                // Update existing line
                $envFileContent = preg_replace("/^{$envKey}=.*/m", "{$envKey}={$escapedValue}", $envFileContent);
            } else {
                // Add new line if it doesn't exist
                $envFileContent .= "\n{$envKey}={$escapedValue}";
            }
        }

        try {
            File::put($envFilePath, $envFileContent);

            // Clear config cache so Laravel reloads the .env values
            Artisan::call('config:clear'); // Important for changes to take effect
            Artisan::call('config:cache');  // Recache for production (optional here, but good practice)

            // Fetch the newly updated settings from the reloaded config
            $newSettings = config('app_settings');

            return response()->json([
                'message' => 'Settings updated successfully. Config cache cleared.',
                'data' => $newSettings
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to update .env file for settings: " . $e->getMessage());
            return response()->json(['message' => 'Failed to update settings file on server.'], 500);
        }
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