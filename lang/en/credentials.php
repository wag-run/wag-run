<?php

return [
    'ai_provider' => [
        'api_key' => 'API key',
        'api_key_for_an_llm' => 'An API key for an LLM provider, used by the LLM call node.',
        'base_url' => 'Base URL',
        'not_needed_for_a_local' => 'Not needed for a local Ollama server.',
        'optional_and_required_for_an' => 'Optional, and required for an OpenAI-compatible endpoint. Overrides the provider endpoint, for example a self-hosted Ollama server.',
    ],
    'api_token' => [
        'api_token' => 'API token',
        'header_name' => 'Header name',
        'sent_before_the_token_separated' => 'Sent before the token, separated by a space. Leave empty to send the token alone.',
        'token' => 'Token',
        'token_sent_in_a_request' => 'A token sent in a request header, such as a bearer token.',
        'value_prefix' => 'Value prefix',
    ],
    'http_basic' => [
        'http_basic' => 'HTTP Basic',
        'username' => 'Username',
        'username_and_password_sent_with' => 'A username and password sent with HTTP Basic authentication.',
    ],
    'smtp' => [
        'an_smtp_server_send_email' => 'An SMTP server that Send email nodes can send through instead of the organization\'s mail settings.',
        'encryption' => 'Encryption',
        'from_address' => 'From address',
        'from_name' => 'From name',
        'host' => 'Host',
        'none' => 'None',
        'port' => 'Port',
        'smtp_server' => 'SMTP server',
        'ssl_tls' => 'SSL/TLS (usually port 465)',
        'starttls' => 'STARTTLS (usually port 587)',
        'username' => 'Username',
    ],
];
