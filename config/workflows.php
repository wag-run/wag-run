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
    | Limits on the workspace data store: the size of one value or collection
    | item once saved as JSON, and how many values a workspace may hold,
    | collection items included.
    |
    */
    'store' => [
        'max_value_bytes' => 64 * 1024,
        'max_values' => 100000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Workflow variables
    |--------------------------------------------------------------------------
    |
    | Limits on the variables a workflow declares for its runs: the size of
    | one value once saved as JSON, and how many a workflow may declare.
    |
    */
    'variables' => [
        'max_value_bytes' => 64 * 1024,
        'max_count' => 50,
    ],
];
