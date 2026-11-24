<?php

return [
    'assignment_status' => [
        'approved' => 'Approved',
        'expired' => 'Expired',
        'rejected' => 'Rejected',
        'submitted' => 'Submitted',
        'withdrawn' => 'Withdrawn',
    ],
    'concurrency_policy' => [
        'allow_concurrent_runs' => 'Allow concurrent runs',
        'one_at_a_time_new' => 'One at a time, new run cancels the previous',
        'one_at_a_time_skip' => 'One at a time, skip new runs',
    ],
    'execution_status' => [
        'skipped' => 'Skipped',
    ],
    'org_role' => [
        'member' => 'Member',
        'organization_admin' => 'Organization admin',
    ],
    'step_status' => [
        'pending' => 'Pending',
    ],
    'workspace_role' => [
        'admin' => 'Admin',
        'builds_publishes_and_runs_workflows' => 'Builds, publishes and runs workflows.',
        'editor' => 'Editor',
        'guest' => 'Guest',
        'manages_members_secrets_credentials_and' => 'Manages members, secrets, credentials and workspace settings.',
        'sees_only_the_approvals_and' => 'Sees only the approvals and tasks assigned to them.',
        'sees_workflows_and_execution_logs' => 'Sees workflows and execution logs.',
        'viewer' => 'Viewer',
    ],
];
