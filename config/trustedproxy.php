<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The proxies whose X-Forwarded-* headers may be believed. This decides
    | what $request->ip() returns, which every IP based rate limiter and the
    | audit log depend on.
    |
    | Leaving this empty means no proxy is trusted: behind a load balancer
    | every request then looks like it came from the balancer, collapsing all
    | per-IP throttles into one shared bucket that a single client can exhaust
    | for everybody. Trusting "*" has the opposite failure mode — anyone who
    | can reach the application directly can forge X-Forwarded-For and evade
    | those throttles entirely.
    |
    | Set TRUSTED_PROXIES to the balancer's CIDR range (for AWS, the VPC or
    | subnet range the ELB lives in). Use "*" only when the application is
    | genuinely unreachable except through the balancer.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
