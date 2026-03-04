<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Services\SeasonService;

final class SeasonController extends Controller
{
    public function index(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        $this->loadLanguage();
        $lang = (array) $this->f3->get('lang');

        $service = new SeasonService($this->db);
        $season  = $service->getActiveSeason();

        if ($season === null) {
            $this->flashAndRedirect($lang['noActiveSeason'] ?? 'No active season.', '/');
            return;
        }

        // Zaman hesaplamaları
        $now      = new \DateTime();
        $endDt    = new \DateTime($season['end_time']);
        $startDt  = new \DateTime($season['start_time']);

        $remaining = $now < $endDt ? $now->diff($endDt) : null;
        $totalSec  = max(1, $endDt->getTimestamp() - $startDt->getTimestamp());
        $elapsedSec = min($totalSec, max(0, $now->getTimestamp() - $startDt->getTimestamp()));
        $progressPct = (int) round($elapsedSec / $totalSec * 100);

        $daysRemaining  = $remaining ? (int) $remaining->days : 0;
        $hoursRemaining = $remaining ? (int) $remaining->h   : 0;
        $totalDays      = (int) round($totalSec / 86400);

        // Bitiş timestamp'i JS countdown için
        $endTimestamp = $endDt->getTimestamp();
        $startDateFmt = $startDt->format('d.m.Y');
        $endDateFmt   = $endDt->format('d.m.Y');

        $leaderboard = $service->getLeaderboard((int) $season['id'], 50);
        $myRank = null;
        foreach ($leaderboard as $i => &$row) {
            $row['rank'] = $i + 1;
            $row['is_me'] = (int) $row['user_id'] === (int) $user['id'];
            if ($row['is_me']) $myRank = $i + 1;
        }
        unset($row);

        $this->render('season/index.html', [
            'page_title'      => ($season['name'] ?? 'Season') . ' — ' . ($lang['leaderboard'] ?? 'Leaderboard'),
            'season'          => $season,
            'leaderboard'     => $leaderboard,
            'days_remaining'  => $daysRemaining,
            'hours_remaining' => $hoursRemaining,
            'total_days'      => $totalDays,
            'progress_pct'    => $progressPct,
            'end_timestamp'   => $endTimestamp,
            'my_rank'         => $myRank,
            'total_players'   => count($leaderboard),
            'start_date_fmt'  => $startDateFmt,
            'end_date_fmt'    => $endDateFmt,
        ]);
    }

    public function hallOfFame(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        $this->loadLanguage();
        $lang = (array) $this->f3->get('lang');

        $service = new SeasonService($this->db);
        $hallOfFame = $service->getHallOfFame();

        // Group by season
        $grouped = [];
        foreach ($hallOfFame as $entry) {
            $sId = (int) ($entry['season_id'] ?? 0);
            $grouped[$sId]['season_name'] = $entry['season_name'] ?? 'Season ' . $sId;
            $grouped[$sId]['entries'][] = $entry;
        }

        $this->render('season/hall-of-fame.html', [
            'page_title' => $lang['hallOfFame'] ?? 'Hall of Fame',
            'grouped' => $grouped,
        ]);
    }
}
