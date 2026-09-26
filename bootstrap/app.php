<?php

use App\Http\Middleware\AuditPatientAccess;
use App\Http\Middleware\RequireDoctorTwoFactor;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Env;

// Apache/XAMPP trên Windows chạy nhiều site Laravel trong cùng một tiến trình và dùng chung putenv():
// biến môi trường của site khác (DB_CONNECTION, APP_ENV…) lọt sang ứng dụng này. Tắt putenv để chỉ đọc .env của mình.
Env::disablePutenv();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'audit.patient' => AuditPatientAccess::class,
            'doctor.2fa' => RequireDoctorTwoFactor::class,
            'tenant' => SetTenantContext::class,
        ]);
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            SetTenantContext::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
