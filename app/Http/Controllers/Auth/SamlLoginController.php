<?php

namespace App\Http\Controllers\Auth;

use App\Models\Management\Team;
use App\Models\Management\TeamSamlProvider;
use App\Services\Auth\SamlLoginService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class SamlLoginController extends AuthController
{
    private const string RETURN_TO = 'auth.saml.return_to.';

    private const string REQUEST_ID = 'auth.saml.request_id.';

    public function __construct(private readonly SamlLoginService $samlLoginService) {}

    public function redirect(Request $request, Team $team): RedirectResponse
    {
        $provider = $this->enabledProvider($team);

        $returnTo = $this->safeReturnPath($request->query('return'));
        $request->session()->put(self::RETURN_TO.$provider->id, $returnTo);

        $auth = $this->samlLoginService->authFor($provider);
        $redirectUrl = $auth->login(null, [], false, false, true);
        $request->session()->put(self::REQUEST_ID.$provider->id, $auth->getLastRequestID());

        return redirect()->away($redirectUrl);
    }

    public function acs(Request $request, Team $team): RedirectResponse
    {
        $provider = $this->enabledProvider($team);
        $_POST['SAMLResponse'] = $request->input('SAMLResponse');
        $_POST['RelayState'] = $request->input('RelayState');

        $returnTo = $this->safeReturnPath($request->session()->pull(self::RETURN_TO.$provider->id));
        $requestId = $request->session()->pull(self::REQUEST_ID.$provider->id);

        try {
            $auth = $this->samlLoginService->authFor($provider);
            $user = $this->samlLoginService->completeLogin(
                $provider,
                $auth,
                is_string($requestId) ? $requestId : null,
            );

            Auth::guard('web')->login($user);
            $this->updateUserLogin($user);
            $request->session()->regenerate();

            return redirect()->to($returnTo);
        } catch (ValidationException $exception) {
            Log::info('SAML login validation failed', [
                'team_id' => $team->id,
                'errors' => $exception->errors(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('SAML login failed', [
                'team_id' => $team->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return redirect()->to($this->loginPath([
            'saml_error' => '1',
            'team_id' => $team->id,
            'return' => $returnTo,
        ]));
    }

    public function sls(Request $request, Team $team): RedirectResponse
    {
        $provider = $this->enabledProvider($team);

        $_GET['SAMLRequest'] = $request->query('SAMLRequest');
        $_GET['SAMLResponse'] = $request->query('SAMLResponse');
        $_GET['RelayState'] = $request->query('RelayState');
        $_GET['SigAlg'] = $request->query('SigAlg');
        $_GET['Signature'] = $request->query('Signature');

        try {
            $auth = $this->samlLoginService->authFor($provider);
            $redirectUrl = $auth->processSLO(false, null, true, function (): void {
                Auth::guard('web')->logout();
            }, true);

            if (is_string($redirectUrl) && $redirectUrl !== '') {
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->away($redirectUrl);
            }
        } catch (\Throwable $exception) {
            Log::warning('SAML logout failed', [
                'team_id' => $team->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to('/login');
    }

    public function metadata(Team $team): Response
    {
        $provider = $team->samlProvider()->firstOrFail();
        $metadata = $this->samlLoginService->metadataFor($provider);

        return response($metadata, 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    private function enabledProvider(Team $team): TeamSamlProvider
    {
        /** @var TeamSamlProvider $provider */
        $provider = $team->samlProvider()
            ->where('enabled', true)
            ->firstOrFail();

        return $provider;
    }

    private function safeReturnPath(mixed $returnPath): string
    {
        if (! is_string($returnPath) || $returnPath === '' || ! str_starts_with($returnPath, '/')) {
            return '/';
        }

        if (str_starts_with($returnPath, '//')) {
            return '/';
        }

        return $returnPath;
    }

    private function loginPath(array $query): string
    {
        return '/login?'.http_build_query(array_filter($query));
    }
}
