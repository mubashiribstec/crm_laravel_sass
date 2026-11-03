<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\IPAddress;
use App\Http\Middleware\CheckPermission;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        api: __DIR__ . '/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register middleware alias
        // $middleware->alias([
        //     'restrict.ip' => IPAddress::class,
        // ]);

        // // Optionally apply to specific routes or groups
        // $middleware->web(append: [
        //     IPAddress::class, // Apply to all web routes
        // ]);

        $middleware->alias([
            'permission' => CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Xplogent API: errors in its own JSON shape, never a stack trace.
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/xplogent/*')) {
                return \App\Support\Xplogent::error(422, 'invalid', $e->validator->errors()->first());
            }
        });
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, $request) {
            if ($request->is('api/xplogent/*')) {
                return \App\Support\Xplogent::error(429, 'rate_limited', 'too many requests');
            }
        });
        $exceptions->render(function (\Throwable $e, $request) {
            if ($request->is('api/xplogent/*') && !$e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                report($e);
                return \App\Support\Xplogent::error(500, 'server_error', 'the CRM could not complete this request');
            }
        });
    })->create();
