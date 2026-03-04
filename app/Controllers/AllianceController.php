<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\DataParser;
use Devana\Helpers\InputSanitizer;
use Devana\Services\AllianceService;
use Devana\Services\AllianceWarService;
use Devana\Services\PreferenceService;

final class AllianceController extends Controller
{
    /**
     * Require that the current user is the founder of the given alliance.
     * Returns the alliance row on success, null (with redirect) on failure.
     */
    private function requireFounder(int $allianceId): ?array
    {
        $user = $this->requireAuth();
        if ($user === null) return null;

        $allianceService = new AllianceService($this->db);
        $alliance = $allianceService->findById($allianceId);

        if ($alliance === null || (int) $alliance['founder'] !== $user['id']) {
            $this->flashAndRedirect('Access denied.', '/towns');
            return null;
        }

        return $alliance;
    }

    public function view(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);
        $allianceService = new AllianceService($this->db);
        $alliance = $allianceService->findById($allianceId);

        if ($alliance === null) {
            $this->flashAndRedirect('Alliance not found.', '/towns');
            return;
        }

        $members = $allianceService->getMembers($allianceId);
        $pacts = $allianceService->getPacts($allianceId);
        $user = $this->requireAuth();
        if ($user === null) return;

        $isMember = $user['alliance'] === $allianceId;

        // Parse treasury
        $treasury = DataParser::parseResources($alliance['treasury'] ?? '0-0-0-0-0');

        // Get user's towns for donation form
        $userTowns = [];
        if ($isMember) {
            $userTowns = $this->db->exec('SELECT id, name FROM towns WHERE owner = ?', [$user['id']]);
        }

        $this->render('alliance/view.html', [
            'page_title' => $alliance['name'],
            'alliance' => $alliance,
            'members' => $members,
            'pacts' => $pacts,
            'is_member' => $isMember,
            'is_founder' => (int) $alliance['founder'] === $user['id'],
            'treasury' => $treasury,
            'user_towns' => $userTowns,
        ]);
    }

    public function showCreate(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        if ($user['alliance'] > 0) {
            $this->flashAndRedirect('You are already in an alliance.', '/alliance/' . $user['alliance']);
            return;
        }

        // Check embassy requirement
        $towns = $this->db->exec('SELECT buildings FROM towns WHERE owner = ?', [$user['id']]);
        $hasEmbassy = false;
        foreach ($towns as $town) {
            $levels = DataParser::toIntArray($town['buildings']);
            if (($levels[9] ?? 0) > 0) {
                $hasEmbassy = true;
                break;
            }
        }

        if (!$hasEmbassy) {
            $this->flashAndRedirect('You need an Embassy to create an alliance.', '/towns');
            return;
        }

        $this->render('alliance/create.html', [
            'page_title' => $f3->get('lang.createAlliance') ?? 'Create Alliance',
        ]);
    }

    public function create(\Base $f3): void
    {
        if (!$this->requireCsrf('/alliance/create')) return;

        $user = $this->requireAuth();
        if ($user === null) return;

        if ($user['alliance'] > 0) {
            $this->flashAndRedirect('You are already in an alliance.', '/towns');
            return;
        }

        $name = InputSanitizer::clean($this->post('name', ''));

        if (empty($name)) {
            $this->flashAndRedirect('Please enter a name.', '/alliance/create');
            return;
        }

        $allianceService = new AllianceService($this->db);

        if ($allianceService->isNameTaken($name)) {
            $this->flashAndRedirect('Name already taken.', '/alliance/create');
            return;
        }

        $allianceId = $allianceService->create($name, $user['id']);

        $this->updateSession('alliance', $allianceId);

        $this->flashAndRedirect('Alliance created.', '/alliance/' . $allianceId);
    }

    public function showEdit(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        $alliance = $this->requireFounder($allianceId);
        if ($alliance === null) return;

        $this->render('alliance/edit.html', [
            'page_title' => 'Edit ' . $alliance['name'],
            'alliance' => $alliance,
        ]);
    }

    public function edit(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/alliance/' . $allianceId . '/edit')) return;

        $alliance = $this->requireFounder($allianceId);
        if ($alliance === null) return;

        $description = InputSanitizer::clean($this->post('description', ''));
        $allianceService = new AllianceService($this->db);
        $allianceService->updateDescription($allianceId, $description);

        $this->flashAndRedirect('Alliance updated.', '/alliance/' . $allianceId);
    }

    public function join(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireCsrf('/alliance/' . $allianceId)) return;

        if ($user['alliance'] > 0) {
            $this->flashAndRedirect('You are already in an alliance.', '/towns');
            return;
        }

        $allianceService = new AllianceService($this->db);
        $allianceService->join($user['id'], $allianceId);

        $this->updateSession('alliance', $allianceId);

        $this->flashAndRedirect('Joined alliance.', '/alliance/' . $allianceId);
    }

    public function kick(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);
        $userId = InputSanitizer::cleanInt($this->post('user_id', 0));

        if (!$this->requireCsrf('/alliance/' . $allianceId)) return;

        $alliance = $this->requireFounder($allianceId);
        if ($alliance === null) return;

        $allianceService = new AllianceService($this->db);
        $allianceService->kick($userId);
        $this->flashAndRedirect('Member kicked.', '/alliance/' . $allianceId);
    }

    public function quit(\Base $f3): void
    {
        if (!$this->requireCsrf('/towns')) return;

        $user = $this->requireAuth();
        if ($user === null) return;
        $allianceService = new AllianceService($this->db);
        $allianceService->quit($user['id']);

        $this->updateSession('alliance', 0);

        $this->flashAndRedirect('You left the alliance.', '/towns');
    }

    public function pact(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/alliance/' . $allianceId)) return;

        $alliance = $this->requireFounder($allianceId);
        if ($alliance === null) return;

        $action = $this->post('pact_action', 'add');
        $type = InputSanitizer::cleanInt($this->post('pact_type', 0));
        $targetId = InputSanitizer::cleanInt($this->post('target_alliance', 0));

        $allianceService = new AllianceService($this->db);
        if ($action === 'add') {
            $allianceService->addPact($type, $allianceId, $targetId);
        } else {
            $allianceService->removePact($type, $allianceId, $targetId);
        }

        $this->flashAndRedirect('Pact updated.', '/alliance/' . $allianceId);
    }

    public function editRank(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/alliance/' . $allianceId)) return;

        $alliance = $this->requireFounder($allianceId);
        if ($alliance === null) return;

        $userId = InputSanitizer::cleanInt($this->post('user_id', 0));
        $rank = InputSanitizer::clean($this->post('rank', 'member'));

        $this->db->exec('UPDATE users SET `rank` = ? WHERE id = ? AND alliance = ?', [$rank, $userId, $allianceId]);

        $this->flashAndRedirect('Rank updated.', '/alliance/' . $allianceId);
    }

    public function invite(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/alliance/' . $allianceId)) return;
        if (!$this->requireRecaptcha('/alliance/' . $allianceId)) return;

        $targetName = InputSanitizer::clean($this->post('username', ''));
        $targetUser = $this->db->exec('SELECT id FROM users WHERE name = ?', [$targetName]);

        if (empty($targetUser)) {
            $this->flashAndRedirect('User not found.', '/alliance/' . $allianceId);
            return;
        }

        $targetUserId = (int) $targetUser[0]['id'];
        $prefs = new PreferenceService($this->db);
        if ($prefs->isEnabled($targetUserId, 'allianceReports')) {
            // Send invitation as report
            $this->db->exec(
                'INSERT INTO reports (recipient, subject, contents, sent) VALUES (?, ?, ?, NOW())',
                [$targetUserId, 'Invitation/' . $allianceId, 'You have been invited to join an alliance.']
            );
        }

        $this->flashAndRedirect('Invitation sent.', '/alliance/' . $allianceId);
    }

    public function accept(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/alliance/' . $allianceId . '/edit')) return;

        $alliance = $this->requireFounder($allianceId);
        if ($alliance === null) return;

        $userId = InputSanitizer::cleanInt($this->post('user_id', 0));
        $allianceService = new AllianceService($this->db);
        $allianceService->join($userId, $allianceId);
        $this->flashAndRedirect('Member accepted.', '/alliance/' . $allianceId . '/edit');
    }

    public function reject(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/alliance/' . $allianceId . '/edit')) return;

        $alliance = $this->requireFounder($allianceId);
        if ($alliance === null) return;

        $userId = InputSanitizer::cleanInt($this->post('user_id', 0));
        $this->db->exec('DELETE FROM reports WHERE recipient = ? AND subject LIKE ?', [$userId, 'Invitation/' . $allianceId]);
        $this->flashAndRedirect('Application rejected.', '/alliance/' . $allianceId . '/edit');
    }

    public function donate(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/alliance/' . $allianceId)) return;

        $user = $this->requireAuth();
        if ($user === null) return;

        if ($user['alliance'] !== $allianceId) {
            $this->flashAndRedirect('You are not a member of this alliance.', '/towns');
            return;
        }

        $townId = InputSanitizer::cleanInt($this->post('town_id', 0));

        if (!$this->townService()->isOwnedBy($townId, $user['id'])) {
            $this->flashAndRedirect('Town not found.', '/alliance/' . $allianceId);
            return;
        }

        $town = $this->townService()->findById($townId);
        $resources = $this->townService()->parseResources($town['resources']);

        $donate = [
            max(0, min((int) $resources['crop'], InputSanitizer::cleanInt($this->post('d_crop', 0)))),
            max(0, min((int) $resources['lumber'], InputSanitizer::cleanInt($this->post('d_lumber', 0)))),
            max(0, min((int) $resources['stone'], InputSanitizer::cleanInt($this->post('d_stone', 0)))),
            max(0, min((int) $resources['iron'], InputSanitizer::cleanInt($this->post('d_iron', 0)))),
            max(0, min((int) $resources['gold'], InputSanitizer::cleanInt($this->post('d_gold', 0)))),
        ];

        if (array_sum($donate) === 0) {
            $this->flashAndRedirect('Nothing to donate.', '/alliance/' . $allianceId);
            return;
        }

        // Deduct from town
        $newRes = [
            $resources['crop'] - $donate[0],
            $resources['lumber'] - $donate[1],
            $resources['stone'] - $donate[2],
            $resources['iron'] - $donate[3],
            $resources['gold'] - $donate[4],
        ];
        $this->db->exec(
            'UPDATE towns SET resources = ? WHERE id = ?',
            [DataParser::serializeRounded($newRes), $townId]
        );

        // Add to alliance treasury
        $alliance = $this->db->exec('SELECT treasury FROM alliances WHERE id = ?', [$allianceId]);
        if (!empty($alliance)) {
            $treasury = DataParser::toFloatArray($alliance[0]['treasury']);
            for ($i = 0; $i < 5; $i++) {
                $treasury[$i] = ($treasury[$i] ?? 0) + $donate[$i];
            }
            $this->db->exec(
                'UPDATE alliances SET treasury = ? WHERE id = ?',
                [DataParser::serializeRounded($treasury, 0), $allianceId]
            );
        }

        $this->flashAndRedirect('Donation successful.', '/alliance/' . $allianceId);
    }

    public function withdraw(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/alliance/' . $allianceId)) return;

        $user = $this->requireAuth();
        if ($user === null) return;
        $allianceService = new AllianceService($this->db);
        $alliance = $allianceService->findById($allianceId);

        if ($alliance === null || (int) $alliance['founder'] !== $user['id']) {
            $this->flashAndRedirect('Only the founder can withdraw from the treasury.', '/alliance/' . $allianceId);
            return;
        }

        $townId = InputSanitizer::cleanInt($this->post('town_id', 0));

        if (!$this->townService()->isOwnedBy($townId, $user['id'])) {
            $this->flashAndRedirect('Town not found.', '/alliance/' . $allianceId);
            return;
        }

        $treasury = DataParser::toFloatArray($alliance['treasury'] ?? '0-0-0-0-0');
        $town = $this->townService()->findById($townId);
        $resources = $this->townService()->parseResources($town['resources']);
        $limits = $this->townService()->parseLimits($town['limits']);

        $withdraw = [
            max(0, min((float) $treasury[0], (float) InputSanitizer::cleanInt($this->post('w_crop', 0)))),
            max(0, min((float) $treasury[1], (float) InputSanitizer::cleanInt($this->post('w_lumber', 0)))),
            max(0, min((float) $treasury[2], (float) InputSanitizer::cleanInt($this->post('w_stone', 0)))),
            max(0, min((float) $treasury[3], (float) InputSanitizer::cleanInt($this->post('w_iron', 0)))),
            max(0, min((float) $treasury[4], (float) InputSanitizer::cleanInt($this->post('w_gold', 0)))),
        ];

        if (array_sum($withdraw) === 0.0) {
            $this->flashAndRedirect('Nothing to withdraw.', '/alliance/' . $allianceId);
            return;
        }

        // Deduct from treasury
        for ($i = 0; $i < 5; $i++) {
            $treasury[$i] -= $withdraw[$i];
        }
        $this->db->exec(
            'UPDATE alliances SET treasury = ? WHERE id = ?',
            [DataParser::serializeRounded($treasury, 0), $allianceId]
        );

        // Add to town resources
        $newRes = [
            $resources['crop'] + $withdraw[0],
            $resources['lumber'] + $withdraw[1],
            $resources['stone'] + $withdraw[2],
            $resources['iron'] + $withdraw[3],
            $resources['gold'] + $withdraw[4],
        ];
        $newRes[0] = min((float) $newRes[0], (float) ($limits['crop'] ?? $newRes[0]));
        $resCap = (float) ($limits['resources'] ?? $newRes[1]);
        $newRes[1] = min((float) $newRes[1], $resCap);
        $newRes[2] = min((float) $newRes[2], $resCap);
        $newRes[3] = min((float) $newRes[3], $resCap);
        $newRes[4] = min((float) $newRes[4], (float) ($limits['gold'] ?? $newRes[4]));

        $this->db->exec(
            'UPDATE towns SET resources = ? WHERE id = ?',
            [DataParser::serializeRounded($newRes), $townId]
        );

        $this->flashAndRedirect('Withdrawal successful.', '/alliance/' . $allianceId);
    }

    public function declareWar(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireCsrf('/alliance/' . $allianceId)) return;

        if ((int) $user['alliance'] !== $allianceId) {
            $this->flashAndRedirect('Access denied.', '/towns');
            return;
        }

        // Must be founder
        $allianceService = new AllianceService($this->db);
        $alliance = $allianceService->findById($allianceId);
        if ($alliance === null || (int) $alliance['founder'] !== (int) $user['id']) {
            $this->flashAndRedirect('Only the alliance founder can declare war.', '/alliance/' . $allianceId);
            return;
        }

        $defenderId  = (int) $this->post('defender_id', 0);
        $durationDays = max(1, min(30, (int) $this->post('duration_days', 7)));

        if ($defenderId <= 0 || $defenderId === $allianceId) {
            $this->flashAndRedirect('Invalid target alliance.', '/alliance/' . $allianceId);
            return;
        }

        $warService = new AllianceWarService($this->db);
        $result = $warService->declare($allianceId, $defenderId, $durationDays);

        if (isset($result['error'])) {
            $this->flashAndRedirect($result['error'], '/alliance/' . $allianceId);
            return;
        }

        $this->flashAndRedirect('War declared!', '/alliance/' . $allianceId . '/war');
    }

    public function warStatus(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        $this->loadLanguage();
        $lang = (array) $this->f3->get('lang');

        $warService = new AllianceWarService($this->db);
        $war        = $warService->getActiveWar($allianceId);
        $scores     = $war !== null ? $warService->getWarScores((int) $war['id']) : [];

        $allianceService = new AllianceService($this->db);
        $alliance = $allianceService->findById($allianceId);

        $isFounder = $alliance !== null && (int) $alliance['founder'] === (int) $user['id'];

        $this->render('alliance/war.html', [
            'page_title' => $lang['allianceWar'] ?? 'Alliance War',
            'alliance'   => $alliance,
            'war'        => $war,
            'war_scores' => $scores,
            'alliance_id' => $allianceId,
            'is_founder'  => $isFounder,
        ]);
    }

    public function delete(\Base $f3, array $params): void
    {
        $allianceId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/alliance/' . $allianceId)) return;

        $alliance = $this->requireFounder($allianceId);
        if ($alliance === null) return;

        // Remove all members from alliance
        $this->db->exec('UPDATE users SET alliance = 0 WHERE alliance = ?', [$allianceId]);
        $this->db->exec('DELETE FROM alliances WHERE id = ?', [$allianceId]);

        $this->updateSession('alliance', 0);

        $this->flashAndRedirect('Alliance deleted.', '/towns');
    }
}
