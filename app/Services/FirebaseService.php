<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * FirebaseService
 *
 * Provides a Google OAuth2 access token for authenticating with Firebase/Firestore
 * REST APIs. Uses a service account JSON key file.
 *
 * Setup:
 *  1. Download your service account JSON from Firebase Console → Project Settings → Service Accounts.
 *  2. Place it at storage/app/firebase/service-account.json (or set FIREBASE_CREDENTIALS_PATH in .env).
 *  3. Set FIREBASE_PROJECT_ID in .env.
 */
class FirebaseService
{
    /**
     * Returns a cached Google OAuth2 access token, refreshing if expired.
     */
    public static function getAccessToken(): ?string
    {
        // Cache the token for 55 minutes (Google tokens expire in 60 min)
        return Cache::remember('firebase_access_token', 55 * 60, function () {
            return self::fetchNewAccessToken();
        });
    }

    /**
     * Fetches a fresh access token from Google's OAuth2 endpoint using a service account.
     */
    private static function fetchNewAccessToken(): ?string
    {
        $credentialsPath = config('firebase.credentials_path');

        if (!$credentialsPath || !file_exists($credentialsPath)) {
            Log::error('FirebaseService: Service account credentials file not found.', [
                'path' => $credentialsPath,
            ]);
            return null;
        }

        $credentials = json_decode(file_get_contents($credentialsPath), true);

        if (!$credentials || !isset($credentials['private_key'], $credentials['client_email'])) {
            Log::error('FirebaseService: Invalid service account credentials JSON.');
            return null;
        }

        try {
            $now = time();
            $expiry = $now + 3600;

            // Build the JWT claim set
            $header = base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = base64url_encode(json_encode([
                'iss'   => $credentials['client_email'],
                'scope' => 'https://www.googleapis.com/auth/datastore https://www.googleapis.com/auth/firebase',
                'aud'   => 'https://oauth2.googleapis.com/token',
                'iat'   => $now,
                'exp'   => $expiry,
            ]));

            $signingInput = "{$header}.{$claim}";

            $privateKey = openssl_pkey_get_private($credentials['private_key']);
            if (!$privateKey) {
                Log::error('FirebaseService: Failed to parse private key from service account.');
                return null;
            }

            openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            $jwt = "{$signingInput}." . base64url_encode($signature);

            // Exchange the JWT for an access token
            $response = \Illuminate\Support\Facades\Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if ($response->successful()) {
                $tokenData = $response->json();
                $token = $tokenData['access_token'] ?? null;

                if ($token) {
                    Log::info('FirebaseService: Access token fetched successfully.');
                    return $token;
                }
            }

            Log::error('FirebaseService: Failed to obtain access token.', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::error('FirebaseService: Exception while fetching access token.', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

if (!function_exists('base64url_encode')) {
    function base64url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
