<?php declare(strict_types=1);

namespace Devana\Traits;

trait CsrfProtection
{
    protected function generateCsrfToken(): string
    {
        $f3 = \Base::instance();
        $token = bin2hex(random_bytes(32));
        $f3->set('SESSION.csrf_token', $token);

        return $token;
    }

    protected function validateCsrfToken(): bool
    {
        $f3 = \Base::instance();
        $sessionToken = $f3->get('SESSION.csrf_token');
        $postToken = $f3->get('POST.csrf_token');
        $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $token = is_string($postToken) && $postToken !== '' ? $postToken : (is_string($headerToken) ? $headerToken : '');

        if (empty($sessionToken) || empty($token)) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }
}
