<?php

return [
    'actions' => [
        'api_app' => [
            'disconnected' => 'App disconnected from the API',
        ],
        'api_token' => [
            'created' => 'API token created',
            'revoked' => 'API token revoked',
        ],
        'auth' => [
            'oidc_linked' => 'Sign-in provider linked',
            'oidc_registered' => 'Account created by sign-in provider',
            'signed_in' => 'Signed in',
        ],
        'credential' => [
            'connected' => 'Authentication setting connected',
            'created' => 'Authentication setting created',
            'updated' => 'Authentication setting updated',
            'deleted' => 'Authentication setting deleted',
            'revealed' => 'Authentication setting revealed',
        ],
        'invitation' => [
            'sent' => 'Invitation sent',
            'accepted' => 'Invitation accepted',
            'revoked' => 'Invitation revoked',
        ],
        'member' => [
            'added' => 'Member added',
            'removed' => 'Member removed',
            'role_changed' => 'Member role changed',
        ],
        'oidc_provider' => [
            'created' => 'Sign-in provider added',
            'updated' => 'Sign-in provider updated',
            'deleted' => 'Sign-in provider deleted',
        ],
        'organization' => [
            'settings_updated' => 'Organization settings updated',
            'mail_updated' => 'Email settings updated',
        ],
        'secret' => [
            'created' => 'Secret created',
            'updated' => 'Secret updated',
            'deleted' => 'Secret deleted',
            'revealed' => 'Secret revealed',
        ],
        'user' => [
            'promoted' => 'User promoted',
            'demoted' => 'User demoted',
            'deactivated' => 'User deactivated',
            'reactivated' => 'User reactivated',
            'password_reset_link_generated' => 'Password reset link generated',
        ],
        'variable' => [
            'created' => 'Variable created',
            'updated' => 'Variable updated',
            'deleted' => 'Variable deleted',
        ],
        'workflow' => [
            'created' => 'Workflow created',
            'imported' => 'Workflow imported',
            'published' => 'Workflow published',
            'draft_discarded' => 'Workflow draft discarded',
            'rolled_back' => 'Workflow rolled back',
            'settings_updated' => 'Workflow settings updated',
            'deleted' => 'Workflow deleted',
        ],
        'workspace' => [
            'created' => 'Workspace created',
            'renamed' => 'Workspace renamed',
            'settings_updated' => 'Workspace settings updated',
            'deleted' => 'Workspace deleted',
        ],
    ],
];
