<?php

namespace App\Http\Middleware;

use App\Services\LemonSqueezy\LemonSqueezyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyLemonSqueezyWebhook
{
    public function __construct(private LemonSqueezyService $ls) {}

    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Signature');

        if (! $signature) {
            return response()->json(['message' => 'Missing webhook signature.'], 401);
        }

        $payload = $request->getContent();

        if (! $this->ls->verifyWebhookSignature($payload, $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
