<?php declare(strict_types=1);

namespace Devana\Middleware;

final class CsrfMiddleware
{
    public function beforeroute(\Base $f3): void
    {
        if ($f3->get('VERB') !== 'POST') {
            return;
        }

        $sessionToken = $f3->get('SESSION.csrf_token');
        $postToken = $f3->get('POST.csrf_token');
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $token = is_string($postToken) && $postToken !== '' ? $postToken : (is_string($headerToken) ? $headerToken : '');

        if (empty($sessionToken) || empty($token) || !hash_equals($sessionToken, $token)) {
            $f3->error(403, 'Invalid CSRF token');
        }
    }
}
