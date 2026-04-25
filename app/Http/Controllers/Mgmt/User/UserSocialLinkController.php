<?php

namespace App\Http\Controllers\Mgmt\User;

use App\Http\Controllers\Controller;
use App\Models\User\UserSocialLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
                    'link_url' => route('auth.social.link.redirect', ['provider' => $provider]),
                ];
            })
            ->values();

        return response()->json([
            'data' => $providers,
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
