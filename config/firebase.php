<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Firebase Project ID
    |--------------------------------------------------------------------------
    |
    | The ID of the Firebase project used for Firestore and other services.
    |
    */
    'project_id' => env('FIREBASE_PROJECT_ID', 'one-care-628d0'),

    /*
    |--------------------------------------------------------------------------
    | Firebase Service Account Credentials Path
    |--------------------------------------------------------------------------
    |
    | The absolute path to the service account JSON file.
    |
    */
    'credentials_path' => env('FIREBASE_CREDENTIALS_PATH', storage_path('app/firebase/service-account.json')),
];
