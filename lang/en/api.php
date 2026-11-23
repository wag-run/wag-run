<?php

return [
    'authorize' => [
        'authorize' => 'Authorize',
        'it_will_be_able_to' => 'It will be able to:',
        'signed_in_as' => 'Signed in as :email. The app acts as you, with your role in each workspace.',
        'title' => 'Connect :app to wag.run?',
        'you_will_be_sent_to' => 'You\'ll be sent back to :uri',
    ],
    'not_allowed_in_workspace' => 'Your role in this workspace doesn\'t allow this.',
    'not_found' => ':what not found, or you can\'t see it.',
    'token_missing_scope' => 'This token doesn\'t have the :scope scope.',
    'scopes' => [
        'mcp_use' => 'Use wag.run from an AI assistant: everything your account can do with workflows and runs',
        'runs_read' => 'See runs and their steps',
        'runs_write' => 'Start and cancel runs',
        'workflows_read' => 'See workspaces and workflows',
    ],
];
