<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\DataParser;
use Devana\Helpers\InputSanitizer;
use Devana\Services\BarbarianService;
use Devana\Services\MapService;

final class BarbarianController extends Controller
{
    /**
     * GET /camps — List all barbarian camps.
     */
    public function index(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        $this->loadLanguage();
        $lang = $this->f3->get('lang');

        $barbarianService = new BarbarianService($this->db);
        $camps = $barbarianService->getAllCamps();

        $myTownId = $this->townService()->getFirstTownId($user['id']) ?? 0;

        // Check if user has any army in their first town
        $hasArmy = false;
        if ($myTownId > 0) {
            $townRow = $this->db->exec('SELECT army FROM towns WHERE id = ? LIMIT 1', [$myTownId]);
            if (!empty($townRow)) {
                $armyArr = DataParser::toIntArray($townRow[0]['army'] ?? '0-0-0-0-0-0-0-0-0-0-0-0-0');
                $hasArmy = array_sum($armyArr) > 0;
            }
        }

        // Build mini-map tile data (3×3 tiles) for each camp
        $mapService = new MapService($this->db);
        $user_imgs = '/' . ltrim((string) ($user['imgs'] ?? 'default/'), '/');
        foreach ($camps as &$camp) {
            $camp['mini_tiles'] = $mapService->buildMiniMap(
                (int) $camp['x'], (int) $camp['y'], $user_imgs
            );
        }
        unset($camp);

        $this->render('camps.html', [
            'page_title'  => $lang['barbarianCamps'] ?? 'Barbarian Camps',
            'camps'       => $camps,
            'my_town_id'  => $myTownId,
            'has_army'    => $hasArmy,
            'camp_offset' => BarbarianService::CAMP_OFFSET,
        ]);
    }

    /**
     * POST /admin/camps/spawn — Admin spawns a batch of barbarian camps.
     */
    public function spawnCamps(\Base $f3): void
    {
        if (!$this->requireAdmin('/admin')) return;
        if (!$this->requireCsrf('/admin')) return;

        $count = max(1, min(50, InputSanitizer::cleanInt($this->post('count', 5))));

        $barbarianService = new BarbarianService($this->db);
        $spawned = $barbarianService->spawnCamps($count);

        $this->loadLanguage();
        $lang = (array) $this->f3->get('lang');
        $tpl  = $lang['campSpawned'] ?? 'Spawned {n} camp(s).';
        $msg  = str_replace('{n}', (string) $spawned, $tpl);

        $this->f3->set('SESSION.flash_type', 'success');
        $this->flashAndRedirect($msg, '/admin');
    }
}
