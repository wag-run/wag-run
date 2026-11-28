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
            'connected' => 'Credential connected',
            'created' => 'Credential created',
            'updated' => 'Credential updated',
            'deleted' => 'Credential deleted',
            'revealed' => 'Credential revealed',
        ],
        'data_store' => [
            'entry_created' => 'Data store entry created',
            'entry_updated' => 'Data store entry updated',
            'entry_deleted' => 'Data store entry deleted',
            'item_saved' => 'Data store item saved',
            'item_deleted' => 'Data store item deleted',
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
        'workflow' => [
            'created' => 'Workflow created',
            'imported' => 'Workflow imported',
            'published' => 'Workflow published',
            'draft_discarded' => 'Workflow draft discarded',
            'rolled_back' => 'Workflow rolled back',
            'settings_updated' => 'Workflow settings updated',
            'enabled' => 'Workflow enabled',
            'disabled' => 'Workflow disabled',
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
