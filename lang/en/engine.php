<?php

return [
    'child_workflow_failed' => 'The child workflow failed.',
    'chain_depth_limit_reached' => 'Stopped: this run would be more than :max levels deep in a chain of workflows starting workflows. Check for workflows that start each other.',
    'child_workflow_was_cancelled' => 'The child workflow was cancelled.',
    'child_workflow_was_skipped_because' => 'The child workflow was skipped because a run was already in progress.',
    'could_not_resolve_inputs_message' => 'Could not resolve inputs: :message',
    'ended_by_a_terminate_node' => 'Ended by a Terminate node.',
    'run_limit_reached' => 'Stopped: this workflow already started :max runs in the last minute. Check for a loop, such as an action that fires this workflow\'s own trigger, or raise the limit in the workflow\'s settings.',
    'expression_error_message' => 'Expression error: :message',
    'node_type_type_is_not' => 'Node type [:type] is not installed.',
    'template_error_message' => 'Template error: :message',
    'unclosed_placeholder' => 'a {{ placeholder is not closed with }}.',
    'this_step_is_not_waiting' => 'This step is not waiting for a response.',
    'worker_running_this_step_stopped' => 'The worker running this step stopped responding.',
    'workflow_name_has_no_published' => 'Workflow ":name" has no published version.',
    'workflow_name_has_no_trigger' => 'Workflow ":name" has no trigger to start from.',
];
