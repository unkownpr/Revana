<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Enums\UserRole;
use Devana\Services\MapService;

final class HomeController extends Controller
{
    public function index(\Base $f3): void
    {
        $data = [
            'page_title' => $f3->get('lang.home') ?? 'Home',
        ];

        // Announcements (public - visible to all)
        $data['announcements'] = $this->db->exec(
            'SELECT a.id, a.title, a.content, a.created_at, u.name AS author_name
             FROM announcements a
             LEFT JOIN users u ON a.author = u.id
             WHERE a.active = 1
             ORDER BY a.created_at DESC
             LIMIT 5'
        );

        // Logged-in user data
        $user = $this->currentUser();
        if ($user !== null) {
            $uid = (int) $user['id'];

            // User's towns with mini-map data
            $rawTowns = $this->db->exec(
                'SELECT id, name FROM towns WHERE owner = ? ORDER BY id ASC',
                [$uid]
            );
            $mapService = new MapService($this->db);
            $user_imgs  = '/' . ltrim((string) ($user['imgs'] ?? 'default/'), '/');
            foreach ($rawTowns as &$ut) {
                $loc = $mapService->getTownLocation((int) $ut['id']);
                if ($loc !== null) {
                    $ut['map_x']      = (int) $loc['x'];
                    $ut['map_y']      = (int) $loc['y'];
                    $ut['mini_tiles'] = $mapService->buildMiniMap((int) $loc['x'], (int) $loc['y'], $user_imgs);
                } else {
                    $ut['map_x']      = 0;
                    $ut['map_y']      = 0;
                    $ut['mini_tiles'] = [];
                }
            }
            unset($ut);
            $data['user_towns'] = $rawTowns;

            // Server stats
            $data['stats'] = [
                'players' => (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM users WHERE level > 0')[0]['cnt'] ?? 0),
                'towns' => (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM towns')[0]['cnt'] ?? 0),
                'alliances' => (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM alliances')[0]['cnt'] ?? 0),
                'server_time' => date('H:i:s'),
            ];

            $data['is_admin'] = UserRole::isAdmin((int) ($user['level'] ?? 0));
        }

        $this->render('home.html', $data);
    }
}
