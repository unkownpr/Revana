<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Services\StatsService;
use Devana\Helpers\AvatarHelper;

final class StatsController extends Controller
{
    public function towns(\Base $f3): void
    {
        $page = max(1, (int) $this->get('page', 1));
        $perPage = 25;
        $statsService = new StatsService($this->db);
        $total = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM towns')[0]['cnt'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $towns = $statsService->getTopTowns($perPage, $offset);
        foreach ($towns as &$town) {
            $avatarSeed = (string) ($town['avatar_seed'] ?? $town['owner_name'] ?? 'player');
            $avatarStyle = (string) ($town['avatar_style'] ?? AvatarHelper::DEFAULT_STYLE);
            $avatarOptions = AvatarHelper::decodeAndNormalize($town['avatar_options'] ?? null, $avatarSeed);
            $town['owner_avatar_url'] = AvatarHelper::url($avatarStyle, $avatarOptions, 24);
        }
        unset($town);

        $this->render('stats/towns.html', [
            'page_title' => $f3->get('lang.statsTowns') ?? 'Top Towns',
            'ranked_towns' => $towns,
            'page' => $page,
            'offset' => $offset,
            'total_towns' => $total,
            'total_pages' => $totalPages,
        ]);
    }

    public function users(\Base $f3): void
    {
        $page = max(1, (int) $this->get('page', 1));
        $perPage = 25;
        $search = trim((string) $this->get('q', ''));
        $statsService = new StatsService($this->db);
        $total = $statsService->countPlayers($search);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $players = $statsService->getTopPlayers($perPage, $offset, $search);
        foreach ($players as &$player) {
            $avatarSeed = (string) ($player['avatar_seed'] ?? $player['name'] ?? 'player');
            $avatarStyle = (string) ($player['avatar_style'] ?? AvatarHelper::DEFAULT_STYLE);
            $avatarOptions = AvatarHelper::decodeAndNormalize($player['avatar_options'] ?? null, $avatarSeed);
            $player['avatar_url'] = AvatarHelper::url($avatarStyle, $avatarOptions, 24);
        }
        unset($player);

        $this->render('stats/users.html', [
            'page_title' => $f3->get('lang.statsUsers') ?? 'Top Players',
            'ranked_users' => $players,
            'search_query' => $search,
            'query_suffix' => $search !== '' ? '&q=' . rawurlencode($search) : '',
            'page' => $page,
            'offset' => $offset,
            'total_players' => $total,
            'total_pages' => $totalPages,
        ]);
    }

    public function alliances(\Base $f3): void
    {
        $page = max(1, (int) $this->get('page', 1));
        $perPage = 25;
        $statsService = new StatsService($this->db);
        $total = (int) ($this->db->exec('SELECT COUNT(*) AS cnt FROM alliances')[0]['cnt'] ?? 0);
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $alliances = $statsService->getTopAlliances($perPage, $offset);

        $this->render('stats/alliances.html', [
            'page_title' => $f3->get('lang.statsAlliances') ?? 'Top Alliances',
            'ranked_alliances' => $alliances,
            'page' => $page,
            'offset' => $offset,
            'total_alliances' => $total,
            'total_pages' => $totalPages,
        ]);
    }
}
