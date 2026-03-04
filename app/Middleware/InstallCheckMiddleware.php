<?php declare(strict_types=1);

namespace Devana\Middleware;

final class InstallCheckMiddleware
{
    public function beforeroute(\Base $f3): void
    {
        $isInstallRoute = str_starts_with($f3->get('PATH'), '/install');
        $isInstalled = file_exists($f3->get('ROOT') . '/install.lock');

        if (!$isInstalled && !$isInstallRoute) {
            $f3->reroute('/install');
            return;
        }

        if ($isInstalled && $isInstallRoute) {
            $f3->reroute('/');
            return;
        }
    }
}
