<?php

return [

    // standard | shared — see App\Enums\InstallProfile. Resolution order:
    // explicit --profile option > this value > persisted install state.
    'profile' => env('B10CKS_INSTALL_PROFILE'),

    'default_profile' => 'standard',

    // Space database driver for the shared profile: "mysql" reuses the main
    // database connection with a per-space table prefix (works without admin
    // DB privileges), "sqlite" keeps one file per space under storage.
    'space_db_driver' => env('B10CKS_SPACE_DB_DRIVER', 'mysql'),

    'state_path' => storage_path('app/setup/install-state.json'),

    // GET /setup is enabled by env or by this marker file, which the endpoint
    // deletes after a successful install (self-disarming for release packages
    // that ship it).
    'http_enabled_path' => storage_path('app/setup/http-enabled'),
    'http_enabled' => env('B10CKS_HTTP_SETUP_ENABLED', false),

    // Written the first time an account is observed on a self-hosted install.
    // Closing self-registration must not depend on a live query that can fail
    // (a database blip would reopen the instance) or be undone (soft-deleting
    // the last account would too). Delete this file, or set
    // B10CKS_ALLOW_REGISTRATION=true, to deliberately reopen registration.
    'registration_closed_path' => storage_path('app/setup/registration-closed'),

];
