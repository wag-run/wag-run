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
        'max_bytes' => (int) env('WORKFLOW_FILES_MAX_MB', 100) * 1024 * 1024,
        'text_max_bytes' => 1024 * 1024,
    ],
];
