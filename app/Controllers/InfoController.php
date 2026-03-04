<?php declare(strict_types=1);

namespace Devana\Controllers;

final class InfoController extends Controller
{
    public function favicon(\Base $f3): void
    {
        $path = (string) ($f3->get('game.site_favicon_path') ?? '/default/1/logo.jpg');
        if ($path === '') {
            $path = '/default/1/logo.jpg';
        }
        $f3->reroute($path);
    }

    public function faq(\Base $f3): void
    {
        $f3->reroute('/help');
    }

    public function help(\Base $f3): void
    {
        $this->render('info/help.html', ['page_title' => $f3->get('lang.help') ?? 'Help']);
    }

    public function guide(\Base $f3): void
    {
        $f3->reroute('/help');
    }

    public function about(\Base $f3): void
    {
        $f3->reroute('/help');
    }

    public function credits(\Base $f3): void
    {
        $f3->reroute('/help');
    }
}
