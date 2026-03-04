<?php declare(strict_types=1);

namespace Devana\Middleware;

use Devana\Enums\UserRole;

final class AdminMiddleware
{
    public function beforeroute(\Base $f3): void
    {
        if (!$f3->exists('SESSION.user')) {
            $f3->reroute('/login');
            return;
        }

        $user = $f3->get('SESSION.user');

        if (!UserRole::isAdmin((int) ($user['level'] ?? 0))) {
            $f3->error(403, 'Access denied');
        }
    }
}
