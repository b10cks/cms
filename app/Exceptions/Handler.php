<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontRender = [
        'Illuminate\Database\QueryException'
    ];

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, \Throwable $e)
    {
        if ($this->shouldNotRender($e)) {
            $e = new \Exception('A general error occurred.');
        }

        return parent::render($request, $e);
    }

    protected function shouldNotRender(\Throwable $e)
    {
        foreach ($this->dontRender as $type) {
            if ($e instanceof $type) {
                return true;
            }
        }

        return false;
    }
}
