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
    'credentials_path' => base_path(env('FIREBASE_CREDENTIALS_PATH', 'storage/app/firebase/service-account.json')),

    /*
    |--------------------------------------------------------------------------
    | Firebase Storage Bucket
    |--------------------------------------------------------------------------
    |
    | The Firebase Storage bucket name, e.g. "your-project-id.appspot.com".
    | Set FIREBASE_STORAGE_BUCKET in .env.
    |
    */
    'storage_bucket' => env('FIREBASE_STORAGE_BUCKET', env('FIREBASE_PROJECT_ID', 'one-care-628d0') . '.appspot.com'),
];
