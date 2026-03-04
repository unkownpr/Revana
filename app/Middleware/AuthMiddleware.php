<?php declare(strict_types=1);

namespace Devana\Middleware;

final class AuthMiddleware
{
    public function beforeroute(\Base $f3): void
    {
        if (!$f3->exists('SESSION.user')) {
            $f3->reroute('/login');
        }
    }
}
