<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Http\Controllers\Controller;
use App\Models\User\UserSocialLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class UserSocialLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $links = $request->user()
            ->socialLinks()
            ->whereIn('service', UserSocialLink::SOCIAL_SERVICES)
            ->get()
            ->keyBy('service');

        $providers = collect(UserSocialLink::SOCIAL_SERVICES)
            ->filter(fn (string $provider) => $this->isConfigured($provider))
            ->map(function (string $provider) use ($links) {
                $link = $links->get($provider);

                return [
                    'provider' => $provider,
                    'label' => Str::headline($provider),
                    'linked' => $link !== null,
                    'linked_at' => $link?->created_at?->toIso8601String(),
                ];
            })
            ->values();

        return response()->json([
            'data' => $providers,
        ]);
    }

    /**
     * Sign a five-minute link start URL for the current user. The route sits
     * behind the password or TOTP step-up.
     */
    public function start(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, UserSocialLink::SOCIAL_SERVICES, true) && $this->isConfigured($provider), 404);
        $return = $request->validate(['return' => 'nullable|string|max:255'])['return'] ?? null;

        return response()->json([
            'data' => [
                'url' => URL::temporarySignedRoute('auth.social.link.redirect', now()->addMinutes(5), array_filter([
                    'provider' => $provider,
                    'user' => $request->user()->id,
                    'return' => $return,
                ])),
            ],
        ]);
    }

    public function destroy(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, UserSocialLink::SOCIAL_SERVICES, true), 404);

        $link = $request->user()
            ->socialLinks()
            ->where('service', $provider)
            ->firstOrFail();

        $link->delete();

        return response()->json(status: 204);
    }

    private function isConfigured(string $provider): bool
    {
        return filled(config("services.{$provider}.client_id"))
            && filled(config("services.{$provider}.client_secret"));
    }
}
