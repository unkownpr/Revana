<?php declare(strict_types=1);

namespace Devana\Services;

final class RecaptchaService
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    private string $secretKey;

    public function __construct(string $secretKey)
    {
        $this->secretKey = trim($secretKey);
    }

    public function verify(string $responseToken, ?string $remoteIp = null): bool
    {
        if ($this->secretKey === '') {
            return false;
        }

        $postData = [
            'secret' => $this->secretKey,
            'response' => trim($responseToken),
        ];
        if ($remoteIp !== null && $remoteIp !== '') {
            $postData['remoteip'] = $remoteIp;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($postData),
                'timeout' => 6,
            ],
        ]);

        $raw = @file_get_contents(self::VERIFY_URL, false, $context);
        if ($raw === false || $raw === '') {
            return false;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) && !empty($decoded['success']);
    }
}
