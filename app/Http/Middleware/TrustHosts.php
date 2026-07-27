<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * Absolute URLs in outgoing mail (password reset, invites, email
     * verification) are built from the request root, so an untrusted Host
     * header would let an attacker point those links at their own domain.
     *
     * Deployments that answer on hosts outside APP_URL's domain — a load
     * balancer health check hitting the instance IP, a vanity domain — must
     * list them in TRUSTED_HOSTS as a comma separated list of hostnames or
     * regular expressions.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        return [
            $this->allSubdomainsOfApplicationUrl(),
            ...$this->configuredHosts(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function configuredHosts(): array
    {
        $hosts = config('app.trusted_hosts');

        if (blank($hosts)) {
            return [];
        }

        return array_values(array_filter(array_map(
            trim(...),
            is_array($hosts) ? $hosts : explode(',', $hosts)
        )));
    }
}
