<?php

namespace App\Enums;

enum WorkspacePermission: string
{
    case ViewWorkflows = 'view-workflows';
    case EditWorkflows = 'edit-workflows';
    case PublishWorkflows = 'publish-workflows';
    case RunWorkflows = 'run-workflows';
    case ViewExecutions = 'view-executions';
    case CancelExecutions = 'cancel-executions';
    case ViewVariables = 'view-variables';
    case ManageVariables = 'manage-variables';
    case ViewStore = 'view-store';
    case ManageStore = 'manage-store';
    case ViewSecrets = 'view-secrets';
    case ManageSecrets = 'manage-secrets';
    case RevealSecrets = 'reveal-secrets';
    case ViewCredentials = 'view-credentials';
    case ManageCredentials = 'manage-credentials';
    case ViewMembers = 'view-members';
    case ManageMembers = 'manage-members';
    case ManageSettings = 'manage-settings';
    case ViewAuditLog = 'view-audit-log';
    case ViewOwnAssignments = 'view-own-assignments';
}
