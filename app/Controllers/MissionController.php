<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Services\MissionService;
use Devana\Services\WeeklyMissionService;

final class MissionController extends Controller
{
    private function buildDailyMissions(array $rawMissions, array $lang, bool $isTr): array
    {
        $missionLabels = [
            MissionService::TYPE_BUILD  => $lang['missionBuild'] ?? 'Complete {n} constructions',
            MissionService::TYPE_TRAIN  => $lang['missionTrain'] ?? 'Train {n} units',
            MissionService::TYPE_RAID   => $lang['missionRaid']  ?? 'Send {n} raids',
            MissionService::TYPE_TRADE  => $lang['missionTrade'] ?? 'Make {n} trades',
            MissionService::TYPE_GOLD   => $lang['missionGold']  ?? 'Collect {n} gold',
        ];

        $missions = [];
        foreach ($rawMissions as $m) {
            $type      = (int) $m['type'];
            $target    = (int) $m['target'];
            $progress  = (int) $m['progress'];
            $pct       = $target > 0 ? min(100, (int) round($progress / $target * 100)) : 100;
            $baseLabel = $missionLabels[$type] ?? 'Mission {n}';
            $customTpl = $isTr
                ? (string) ($m['template_title_tr'] ?? '')
                : (string) ($m['template_title_en'] ?? '');
            if (trim($customTpl) !== '') {
                $baseLabel = $customTpl;
            }
            $label = str_replace('{n}', (string) $target, $baseLabel);

            $rewardXp = isset($m['template_reward_xp']) && $m['template_reward_xp'] !== null
                ? max(0, (int) $m['template_reward_xp'])
                : (MissionService::TYPE_RAID === $type ? 150 : 100);
            $rewardResIdx = isset($m['template_reward_resource_index']) && $m['template_reward_resource_index'] !== null
                ? max(0, min(4, (int) $m['template_reward_resource_index']))
                : (MissionService::TYPE_BUILD === $type ? 0 : (MissionService::TYPE_TRAIN === $type ? 1 : (MissionService::TYPE_TRADE === $type ? 2 : (MissionService::TYPE_RAID === $type ? 3 : 4))));
            $rewardResAmount = isset($m['template_reward_resource_amount']) && $m['template_reward_resource_amount'] !== null
                ? max(0, (int) $m['template_reward_resource_amount'])
                : 200;
            $resKeys = [0 => 'crop', 1 => 'lumber', 2 => 'stone', 3 => 'iron', 4 => 'gold'];
            $resKey = $resKeys[$rewardResIdx] ?? 'crop';
            $reward = $rewardXp . ' XP + ' . $rewardResAmount . ' ' . ($lang[$resKey] ?? ucfirst($resKey));

            $missions[] = [
                'id'       => (int) $m['id'],
                'type'     => $type,
                'label'    => $label,
                'reward'   => $reward,
                'progress' => $progress,
                'target'   => $target,
                'pct'      => $pct,
                'complete' => $progress >= $target,
                'claimed'  => (int) $m['claimed'] === 1,
            ];
        }

        return $missions;
    }

    private function buildWeeklyMissions(array $rawMissions, array $lang): array
    {
        $missionLabels = [
            WeeklyMissionService::TYPE_BUILD  => $lang['weeklyMissionBuild'] ?? 'Complete {n} constructions this week',
            WeeklyMissionService::TYPE_TRAIN  => $lang['weeklyMissionTrain'] ?? 'Train {n} units this week',
            WeeklyMissionService::TYPE_RAID   => $lang['weeklyMissionRaid']  ?? 'Win {n} raids this week',
            WeeklyMissionService::TYPE_TRADE  => $lang['weeklyMissionTrade'] ?? 'Make {n} trades this week',
            WeeklyMissionService::TYPE_GOLD   => $lang['weeklyMissionGold']  ?? 'Collect {n} gold this week',
        ];
        $xpRewards = [500, 500, 750, 500, 500];

        $missions = [];
        foreach ($rawMissions as $m) {
            $type     = (int) $m['type'];
            $target   = (int) $m['target'];
            $progress = (int) $m['progress'];
            $pct      = $target > 0 ? min(100, (int) round($progress / $target * 100)) : 100;
            $label    = str_replace('{n}', (string) $target, $missionLabels[$type] ?? 'Mission {n}');
            $xp       = $xpRewards[$type] ?? 500;

            $missions[] = [
                'id'       => (int) $m['id'],
                'type'     => $type,
                'label'    => $label,
                'reward'   => $xp . ' XP + 1000 ' . ($lang['resources'] ?? 'resources'),
                'progress' => $progress,
                'target'   => $target,
                'pct'      => $pct,
                'complete' => $progress >= $target,
                'claimed'  => (int) $m['claimed'] === 1,
            ];
        }

        return $missions;
    }

    public function index(\Base $f3, array $params): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        $this->loadLanguage();
        $lang = $this->f3->get('lang');

        $missionService = new MissionService($this->db);
        $rawMissions    = $missionService->getDailyMissions((int) $user['id']);
        $weeklyService  = new WeeklyMissionService($this->db);
        $rawWeekly      = $weeklyService->getWeeklyMissions((int) $user['id']);
        $langFile       = strtolower((string) ($user['language'] ?? ($user['lang'] ?? 'en.php')));
        $isTr           = str_starts_with($langFile, 'tr');
        $activeTab      = strtolower((string) $this->f3->get('GET.tab')) === 'weekly' ? 'weekly' : 'daily';

        // Fetch current XP
        $userRow    = $this->db->exec('SELECT xp FROM users WHERE id = ? LIMIT 1', [(int) $user['id']]);
        $xp         = (int) ($userRow[0]['xp'] ?? 0);
        $level      = MissionService::getXpLevel($xp);
        $curLevelXp = $level * $level * 100;
        $nxtLevelXp = ($level + 1) * ($level + 1) * 100;
        $xpInLevel  = $xp - $curLevelXp;
        $xpNeeded   = max(1, $nxtLevelXp - $curLevelXp);
        $xpPct      = min(100, (int) round($xpInLevel / $xpNeeded * 100));

        $dailyMissions = $this->buildDailyMissions($rawMissions, $lang, $isTr);
        $weeklyMissions = $this->buildWeeklyMissions($rawWeekly, $lang);

        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $weekEnd   = date('Y-m-d', strtotime('sunday this week'));

        $this->render('missions.html', [
            'page_title'    => $lang['dailyMissions'] ?? 'Daily Missions',
            'missions'      => $dailyMissions,
            'weekly_missions' => $weeklyMissions,
            'user_xp'       => $xp,
            'user_xp_level' => $level,
            'next_level_xp' => $nxtLevelXp,
            'xp_pct'        => $xpPct,
            'week_start'    => $weekStart,
            'week_end'      => $weekEnd,
            'active_tab'    => $activeTab,
        ]);
    }

    public function weeklyIndex(\Base $f3, array $params): void
    {
        $this->redirect('/missions?tab=weekly');
    }

    public function weeklyClaim(\Base $f3, array $params): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireCsrf('/missions?tab=weekly')) return;

        $this->loadLanguage();
        $lang = (array) $this->f3->get('lang');

        $missionId     = (int) ($params['id'] ?? 0);
        $weeklyService = new WeeklyMissionService($this->db);
        $result        = $weeklyService->claimReward((int) $user['id'], $missionId);

        if (isset($result['error_key'])) {
            $key = $result['error_key'];
            $msg = $lang[$key] ?? $key;
            $this->f3->set('SESSION.flash_type', 'error');
            $this->flashAndRedirect($msg, '/missions?tab=weekly');
            return;
        }

        $xp  = (int) ($result['xp'] ?? 0);
        $tpl = $lang['rewardClaimed'] ?? 'Reward claimed! +{xp} XP';
        $msg = str_replace('{xp}', (string) $xp, $tpl);
        $this->f3->set('SESSION.flash_type', 'success');
        $this->flashAndRedirect($msg, '/missions?tab=weekly');
    }

    public function claim(\Base $f3, array $params): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireCsrf('/missions')) return;

        $this->loadLanguage();
        $lang = (array) $this->f3->get('lang');

        $missionId      = (int) ($params['id'] ?? 0);
        $missionService = new MissionService($this->db);
        $result         = $missionService->claimReward((int) $user['id'], $missionId);

        if (isset($result['error_key'])) {
            $key = $result['error_key'];
            $msg = $lang[$key] ?? $key;
            $this->f3->set('SESSION.flash_type', 'error');
            $this->flashAndRedirect($msg, '/missions');
            return;
        }

        $xp  = (int) ($result['xp'] ?? 0);
        $tpl = $lang['rewardClaimed'] ?? 'Reward claimed! +{xp} XP';
        $msg = str_replace('{xp}', (string) $xp, $tpl);
        $this->f3->set('SESSION.flash_type', 'success');
        $this->flashAndRedirect($msg, '/missions');
    }
}
