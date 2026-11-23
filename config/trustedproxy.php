<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | When the app runs behind a reverse proxy that terminates TLS, the proxy's
    | X-Forwarded-* headers are only honored if the proxy is trusted. Set this
    | to a comma-separated list of IP addresses or CIDR ranges, or to "*" when
    | the app is reachable only through the proxy.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
