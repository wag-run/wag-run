<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Workflow files
    |--------------------------------------------------------------------------
    |
    | Where files that runs make or receive are kept, and how large one may
    | be. They are deleted with their run's logs. text_max_bytes caps what the
    | text() and base64() placeholder helpers read into a run's data.
    |
    */
    'files' => [
        'disk' => env('WORKFLOW_FILES_DISK', 'workflow_files'),
        'max_bytes' => (int) env('WORKFLOW_FILES_MAX_MB', 50) * 1024 * 1024,
        'text_max_bytes' => 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data store
    |--------------------------------------------------------------------------
    |
    | Limits on the values workflows keep between runs: the size of one value
    | once saved as JSON, and how many values a workspace may hold. Values
    | that have expired are removed hourly.
    |
    */
    'store' => [
        'max_value_bytes' => 64 * 1024,
        'max_entries' => 10000,
    ],
];
