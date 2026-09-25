<?php

namespace App\Exceptions;

use Dotenv\Exception\InvalidFileException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Sentry\Laravel\Integration;
use Throwable;
use WeakMap;

class Handler extends ExceptionHandler
{
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
     * Exceptions already sent to Sentry in this process.
     *
     * @var WeakMap<Throwable, true>|null
     */
    private static ?WeakMap $sentToSentry = null;

    /**
     * Sentry is wired here rather than in register(): the handler actually
     * bound is Webkul\Core\Exceptions\Handler, whose register() replaces this
     * class's without calling parent — which silently kept every report()ed
     * exception (worker-loop errors, uncaught request exceptions) out of Sentry.
     */
    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->reportable(function (Throwable $e): void {
            self::captureInSentry($e);
        });
    }

    /**
     * A failed job reaches Sentry both through the JobFailed event and through
     * the worker's report(); the same exception object is only sent once.
     */
    public static function captureInSentry(Throwable $e): void
    {
        self::$sentToSentry ??= new WeakMap();

        if (isset(self::$sentToSentry[$e])) {
            return;
        }

        self::$sentToSentry[$e] = true;

        Integration::captureUnhandledException($e);
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof PostTooLargeException) {
            if ($request->ajax()) {
                return response()->json([
                    'message'   => trans('admin::app.errors.413.title'),
                    'errorCode' => $exception->getStatusCode() ?? 413,
                ], $exception->getStatusCode() ?? 413);
            }

            return response()->view('admin::errors.index', ['errorCode' => $exception->getStatusCode() ?? 413]);
        }

        if ($exception instanceof InvalidFileException) {
            if ($request->ajax()) {
                return response()->json([
                    'message'   => $exception->getMessage(),
                ], 500);
            }

            exit($exception->getMessage());
        }

        return parent::render($request, $exception);
    }

    /**
     * MCP endpoints answer JSON (a 401 with the OAuth challenge instead of a
     * login redirect) whatever Accept header the client sends.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    protected function shouldReturnJson($request, Throwable $e): bool
    {
        return $request->is('mcp/*') && ! $request->is('mcp/login')
            || parent::shouldReturnJson($request, $e);
    }
}
