<?php

namespace App\Http\Controllers\Web;

use App\Enums\InstallProfile;
use App\Http\Controllers\Controller;
use App\Services\Setup\InstallState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class SetupController extends Controller
{
    public function __invoke(Request $request, InstallState $installState)
    {
        if (! $installState->httpSetupEnabled()) {
            throw new NotFoundHttpException();
        }

        if ($installState->exists()) {
            return response($this->renderResult(
                title: 'b10cks is already installed',
                status: 'already installed',
                output: json_encode($installState->read(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: ''
            ));
        }

        $profile = InstallProfile::tryFrom((string) $request->query('profile', InstallProfile::STANDARD->value))
            ?? InstallProfile::STANDARD;

        $exitCode = Artisan::call('b10cks:setup', [
            '--profile' => $profile->value,
        ]);

        $output = trim(Artisan::output());

        if ($exitCode === 0) {
            if ($installState->httpEnabledMarkerExists()) {
                $installState->deleteHttpEnabledMarker();
            }

            return response($this->renderResult(
                title: 'b10cks setup completed',
                status: 'success',
                output: $output
            ));
        }

        return response($this->renderResult(
            title: 'b10cks setup failed',
            status: 'error',
            output: $output
        ), 500);
    }

    private function renderResult(string $title, string $status, string $output): string
    {
        $escapedTitle = e($title);
        $escapedStatus = e($status);
        $escapedOutput = e($output);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{$escapedTitle}</title>
    <style>
      body { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; margin: 2rem; line-height: 1.5; }
      pre { background: #111827; color: #f9fafb; padding: 1rem; border-radius: 0.75rem; overflow: auto; }
      .status { font-weight: 700; margin-bottom: 1rem; text-transform: uppercase; letter-spacing: 0.08em; }
    </style>
  </head>
  <body>
    <h1>{$escapedTitle}</h1>
    <p class="status">{$escapedStatus}</p>
    <pre>{$escapedOutput}</pre>
  </body>
</html>
HTML;
    }
}
