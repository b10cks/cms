<?php

namespace App\Http\Controllers\Web;

use App\Enums\InstallProfile;
use App\Http\Controllers\Controller;
use App\Services\Setup\InstallState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class SetupController extends Controller
{
    public function __invoke(Request $request, InstallState $installState)
    {
        if (! $installState->httpSetupEnabled()) {
            throw new NotFoundHttpException();
        }

        $profile = InstallProfile::tryFrom((string) $request->query('profile', InstallProfile::STANDARD->value))
            ?? InstallProfile::STANDARD;

        // flock, not Cache::lock — the cache store may live in the database
        // this very request is about to create. Serializes concurrent /setup
        // hits so the installer never runs twice at once.
        $lockPath = dirname($installState->path()).'/.setup.lock';
        @mkdir(dirname($lockPath), 0755, true);
        $lock = fopen($lockPath, 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return response($this->renderResult(
                title: 'b10cks setup is already running',
                status: 'busy',
                output: 'Another setup request is currently in progress. Retry in a minute.'
            ), 409);
        }

        try {
            // Poor man's rate limit: throttle middleware needs a cache store,
            // which may live in the database this request is about to create.
            // One attempt per 30s is plenty for a one-shot installer and stops
            // scanners from driving repeated migration runs.
            $attemptPath = dirname($installState->path()).'/.setup.last-attempt';
            $lastAttempt = @filemtime($attemptPath);
            if ($lastAttempt !== false && time() - $lastAttempt < 30) {
                return response($this->renderResult(
                    title: 'b10cks setup is rate limited',
                    status: 'too many requests',
                    output: 'A setup attempt ran moments ago. Retry in 30 seconds.'
                ), 429);
            }
            @touch($attemptPath);

            if ($installState->exists()) {
                return response($this->renderResult(
                    title: 'b10cks is already installed',
                    status: 'already installed',
                    output: json_encode($installState->read(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: ''
                ));
            }

            // b10cks:setup throws on sub-command failure instead of returning
            // a non-zero exit code — catch it so a failed install renders the
            // diagnostic page below rather than a bare 500.
            try {
                $exitCode = Artisan::call('b10cks:setup', [
                    '--profile' => $profile->value,
                ]);

                $output = trim(Artisan::output());
            } catch (Throwable $exception) {
                $exitCode = 1;
                $output = trim(Artisan::output()."\n".$exception->getMessage());
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

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

        // The command output can carry connection details (a PDOException
        // message includes host, database and username) — that belongs in the
        // log, not in an unauthenticated HTTP response.
        Log::error('HTTP setup failed', ['output' => $output]);

        return response($this->renderResult(
            title: 'b10cks setup failed',
            status: 'error',
            output: 'Setup failed. The full output has been written to storage/logs/laravel.log.'
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
