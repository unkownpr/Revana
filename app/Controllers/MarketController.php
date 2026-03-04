<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\DataParser;
use Devana\Helpers\InputSanitizer;
use Devana\Services\MissionService;
use Devana\Services\SeasonService;
use Devana\Services\TradeService;
use Devana\Services\WeeklyMissionService;
use Devana\Services\MapService;

final class MarketController extends Controller
{
    public function overview(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        $townService = $this->townService();
        $tradeService = new TradeService($this->db);
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);
        $limits = $townService->parseLimits($town['limits']);

        // Market building type is typically 10 (marketplace)
        $marketLevel = $buildingLevels[10] ?? 0;
        $merchantsTotal = max(1, $marketLevel);
        $merchantsAvailable = $tradeService->getAvailableMerchants($townId, $marketLevel);

        $rawOffers = $tradeService->getOffersBySeller($townId);
        $myOffers = [];
        foreach ($rawOffers as $offer) {
            $sType = (int) ($offer['sType'] ?? 0);
            $sSubType = (int) ($offer['sSubType'] ?? 0);
            $bType = (int) ($offer['bType'] ?? 0);
            $bSubType = (int) ($offer['bSubType'] ?? 0);
            $sQ = (int) ($offer['sQ'] ?? 0);
            $bQ = (int) ($offer['bQ'] ?? 0);
            $myOffers[] = [
                'type' => (int) ($offer['type'] ?? 0),
                'sType' => $sType,
                'sSubType' => $sSubType,
                'bType' => $bType,
                'bSubType' => $bSubType,
                'sQ' => $sQ,
                'bQ' => $bQ,
                'sell_label' => $this->tradeLabel($sType, $sSubType),
                'buy_label' => $this->tradeLabel($bType, $bSubType),
            ];
        }

        $this->render('market/overview.html', [
            'page_title' => $f3->get('lang.marketplace') ?? 'Marketplace',
            'town' => $town,
            'resources' => $townService->parseResources($town['resources']),
            'limits' => $limits,
            'weapons' => $townService->parseWeapons($town['weapons']),
            'my_offers' => $myOffers,
            'building_level' => $marketLevel,
            'merchants_available' => $merchantsAvailable,
            'merchants_total' => $merchantsTotal,
        ]);
    }

    public function trade(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/town/' . $townId . '/market')) return;
        if (!$this->requireRecaptcha('/town/' . $townId . '/market')) return;

        if (!$this->requireTownOwnership($townId)) return;

        // Merchant availability check
        $townService = $this->townService();
        $town = $townService->findById($townId);
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);
        $marketLevel = $buildingLevels[10] ?? 0;

        $tradeService = new TradeService($this->db);

        if ($tradeService->getAvailableMerchants($townId, $marketLevel) <= 0) {
            $this->flashAndRedirect('No merchants available.', '/town/' . $townId . '/market');
            return;
        }

        try {
            $tradeService->createOffer(
                $townId,
                InputSanitizer::cleanInt($this->post('sell_type', 0)),
                InputSanitizer::cleanInt($this->post('sell_subtype', 0)),
                InputSanitizer::cleanInt($this->post('sell_quantity', 0)),
                InputSanitizer::cleanInt($this->post('buy_type', 0)),
                InputSanitizer::cleanInt($this->post('buy_subtype', 0)),
                InputSanitizer::cleanInt($this->post('buy_quantity', 0))
            );
        } catch (\RuntimeException $e) {
            $this->flashAndRedirect($this->translateKey($e->getMessage()), '/town/' . $townId . '/market');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Trade operation failed.', '/town/' . $townId . '/market');
            return;
        }

        // Mission trigger: TRADE
        try {
            $uid = (int) ($this->currentUser()['id'] ?? 0);
            if ($uid > 0) {
                (new MissionService($this->db))->incrementProgress($uid, MissionService::TYPE_TRADE);
                (new WeeklyMissionService($this->db))->incrementProgress($uid, WeeklyMissionService::TYPE_TRADE);
                (new SeasonService($this->db))->addScore($uid, SeasonService::SCORE_TRADE, 'trade');
            }
        } catch (\Throwable $e) {
            // non-critical
        }

        $this->flashAndRedirect('Offer created.', '/town/' . $townId . '/market');
    }

    public function myOffers(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        $tradeService = new TradeService($this->db);
        $rawOffers = $tradeService->getOffersBySeller($townId);
        $offers = [];
        foreach ($rawOffers as $offer) {
            $sType = (int) ($offer['sType'] ?? 0);
            $sSubType = (int) ($offer['sSubType'] ?? 0);
            $bType = (int) ($offer['bType'] ?? 0);
            $bSubType = (int) ($offer['bSubType'] ?? 0);
            $sQ = (int) ($offer['sQ'] ?? 0);
            $bQ = (int) ($offer['bQ'] ?? 0);
            $offers[] = [
                'seller' => $townId,
                'seller_user_id' => (int) ($this->currentUser()['id'] ?? 0),
                'seller_name' => (string) ($this->currentUser()['name'] ?? 'You'),
                'sType' => $sType,
                'sSubType' => $sSubType,
                'bType' => $bType,
                'bSubType' => $bSubType,
                'sQ' => $sQ,
                'bQ' => $bQ,
                'sell_label' => $this->tradeLabel($sType, $sSubType),
                'buy_label' => $this->tradeLabel($bType, $bSubType),
                'ratio' => $sQ > 0 ? round($bQ / $sQ, 3) : 0,
            ];
        }

        $this->render('market/offers.html', [
            'page_title' => 'My Offers',
            'town' => $town,
            'offers' => $offers,
            'show_all' => false,
        ]);
    }

    public function allOffers(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        $townService = $this->townService();
        $tradeService = new TradeService($this->db);

        // Apply offer filters if provided
        $fSType = $this->get('sType');
        $fSSubType = $this->get('sSubType');
        $fBType = $this->get('bType');
        $fBSubType = $this->get('bSubType');
        $page = max(1, (int) ($this->get('page') ?? 1));
        $result = $tradeService->getOpenOffers(
            $fSType !== null ? (int) $fSType : null,
            $fSSubType !== null ? (int) $fSSubType : null,
            $fBType !== null ? (int) $fBType : null,
            $fBSubType !== null ? (int) $fBSubType : null,
            $page,
            10,
            $townId
        );

        $offers = [];
        foreach ($result['offers'] as $offer) {
            $sType = (int) ($offer['sType'] ?? 0);
            $sSubType = (int) ($offer['sSubType'] ?? 0);
            $bType = (int) ($offer['bType'] ?? 0);
            $bSubType = (int) ($offer['bSubType'] ?? 0);
            $sQ = (int) ($offer['sQ'] ?? 0);
            $bQ = (int) ($offer['bQ'] ?? 0);
            $offers[] = [
                'seller' => (int) ($offer['seller'] ?? 0),
                'seller_user_id' => (int) ($offer['seller_user_id'] ?? 0),
                'seller_name' => (string) ($offer['seller_name'] ?? ('Town ' . ($offer['seller'] ?? 0))),
                'sType' => $sType,
                'sSubType' => $sSubType,
                'bType' => $bType,
                'bSubType' => $bSubType,
                'sQ' => $sQ,
                'bQ' => $bQ,
                'sell_label' => $this->tradeLabel($sType, $sSubType),
                'buy_label' => $this->tradeLabel($bType, $bSubType),
                'ratio' => $sQ > 0 ? round($bQ / $sQ, 3) : 0,
            ];
        }

        $this->render('market/offers.html', [
            'page_title' => 'All Offers',
            'town' => $town,
            'resources' => $townService->parseResources($town['resources']),
            'limits' => $townService->parseLimits($town['limits']),
            'offers' => $offers,
            'show_all' => true,
            'filter_sType' => $fSType,
            'filter_sSubType' => $fSSubType,
            'filter_bType' => $fBType,
            'filter_bSubType' => $fBSubType,
            'pagination' => [
                'page' => $result['page'],
                'total_pages' => $result['total_pages'],
                'total' => $result['total'],
            ],
        ]);
    }

    public function acceptOffer(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/town/' . $townId . '/offers/all')) return;
        $town = $this->requireOwnedTown($townId, '/towns');
        if ($town === null) return;

        // Merchant availability check
        $townService = $this->townService();
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);
        $marketLevel = $buildingLevels[10] ?? 0;

        $tradeService = new TradeService($this->db);

        if ($tradeService->getAvailableMerchants($townId, $marketLevel) <= 0) {
            $this->flashAndRedirect('No merchants available.', '/town/' . $townId . '/offers/all');
            return;
        }

        $sellerId = InputSanitizer::cleanInt($this->post('seller', 0));
        $sType = InputSanitizer::cleanInt($this->post('sType', 0));
        $sSubType = InputSanitizer::cleanInt($this->post('sSubType', 0));
        $bType = InputSanitizer::cleanInt($this->post('bType', 0));
        $bSubType = InputSanitizer::cleanInt($this->post('bSubType', 0));

        if ($sellerId <= 0 || $sellerId === $townId) {
            $this->flashAndRedirect('Invalid offer.', '/town/' . $townId . '/offers/all');
            return;
        }

        // Calculate travel time from distance
        $mapService = new MapService($this->db);
        $fromCoords = $mapService->getTownLocation($townId);
        $toCoords = $mapService->getTownLocation($sellerId);

        $travelSeconds = 600; // fallback
        if ($fromCoords !== null && $toCoords !== null) {
            $distance = $mapService->calculateDistance(
                (int) $fromCoords['x'], (int) $fromCoords['y'],
                (int) $toCoords['x'], (int) $toCoords['y']
            );
            // Merchant speed: 10 tiles/hour base; water routes 4x faster
            $merchantSpeed = 10;
            $isWater = $this->isWaterRoute($fromCoords, $toCoords, $mapService);
            if ($isWater) {
                $merchantSpeed *= 4;
            }
            $travelSeconds = max(60, $mapService->calculateTravelTime($distance, $merchantSpeed));
        }

        try {
            $tradeService->acceptOffer(
                $sellerId,
                $townId,
                $sType,
                $sSubType,
                $bType,
                $bSubType,
                $travelSeconds,
                $isWater ?? false
            );
        } catch (\RuntimeException $e) {
            $this->flashAndRedirect($this->translateKey($e->getMessage()), '/town/' . $townId . '/offers/all');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Trade operation failed.', '/town/' . $townId . '/offers/all');
            return;
        }

        $this->flashAndRedirect('Offer accepted.', '/town/' . $townId . '/market');
    }

    public function showTrade(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        $tradeService = new TradeService($this->db);
        $townService = $this->townService();
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);
        $marketLevel = $buildingLevels[10] ?? 0;

        $activeRows = $tradeService->getActiveTransfersBySeller($townId);
        $activeTransfers = [];
        foreach ($activeRows as $row) {
            $activeTransfers[] = [
                'target_name' => $row['target_name'] ?? 'Unknown',
                'resources' => ($row['sQ'] ?? 0) . ' ' . $this->tradeLabel(0, (int) ($row['sSubType'] ?? 0)),
                'timeLeft' => $row['timeLeft'] ?? '00:00:00',
            ];
        }

        $this->render('market/trade.html', [
            'page_title' => $f3->get('lang.sendResources') ?? 'Send Resources',
            'town' => $town,
            'merchants_total' => max(1, $marketLevel),
            'merchants_available' => $tradeService->getAvailableMerchants($townId, $marketLevel),
            'active_trades' => $activeTransfers,
        ]);
    }

    public function sendResources(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);
        if (!$this->requireCsrf('/town/' . $townId . '/market/trade')) return;

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        $targetTownId = $this->resolveTargetTownId(
            trim((string) $this->post('target', '')),
            InputSanitizer::cleanInt($this->post('target_x', 0)),
            InputSanitizer::cleanInt($this->post('target_y', 0))
        );

        if ($targetTownId <= 0 || $targetTownId === $townId) {
            $this->flashAndRedirect('Invalid target town.', '/town/' . $townId . '/market/trade');
            return;
        }

        $quantities = [
            0 => max(0, InputSanitizer::cleanInt($this->post('crop', 0))),
            1 => max(0, InputSanitizer::cleanInt($this->post('lumber', 0))),
            2 => max(0, InputSanitizer::cleanInt($this->post('stone', 0))),
            3 => max(0, InputSanitizer::cleanInt($this->post('iron', 0))),
        ];

        $sendCount = 0;
        foreach ($quantities as $qty) {
            if ($qty > 0) $sendCount++;
        }
        if ($sendCount === 0) {
            $this->flashAndRedirect('Select at least one resource amount.', '/town/' . $townId . '/market/trade');
            return;
        }

        $tradeService = new TradeService($this->db);
        $townService = $this->townService();
        $buildingLevels = $townService->parseBuildingLevels($town['buildings']);
        $marketLevel = $buildingLevels[10] ?? 0;
        $availableMerchants = $tradeService->getAvailableMerchants($townId, $marketLevel);
        if ($availableMerchants < $sendCount) {
            $this->flashAndRedirect('Not enough merchants available.', '/town/' . $townId . '/market/trade');
            return;
        }

        $mapService = new MapService($this->db);
        $fromCoords = $mapService->getTownLocation($townId);
        $toCoords = $mapService->getTownLocation($targetTownId);
        $travelSeconds = 600;
        $isWater = false;
        if ($fromCoords !== null && $toCoords !== null) {
            $distance = $mapService->calculateDistance(
                (int) $fromCoords['x'],
                (int) $fromCoords['y'],
                (int) $toCoords['x'],
                (int) $toCoords['y']
            );
            $merchantSpeed = 10;
            $isWater = $this->isWaterRoute($fromCoords, $toCoords, $mapService);
            if ($isWater) {
                $merchantSpeed *= 4;
            }
            $travelSeconds = max(60, $mapService->calculateTravelTime($distance, $merchantSpeed));
        }

        try {
            foreach ($quantities as $resourceType => $qty) {
                if ($qty <= 0) {
                    continue;
                }

                $tradeService->createDirectTransfer(
                    $townId,
                    $targetTownId,
                    $resourceType,
                    $qty,
                    $travelSeconds,
                    $isWater
                );
            }
        } catch (\RuntimeException $e) {
            $this->flashAndRedirect($this->translateKey($e->getMessage()), '/town/' . $townId . '/market/trade');
            return;
        } catch (\Throwable $e) {
            $this->flashAndRedirect('Trade operation failed.', '/town/' . $townId . '/market/trade');
            return;
        }

        $this->flashAndRedirect('Resources sent.', '/town/' . $townId . '/market/trade');
    }

    public function npcTrade(\Base $f3, array $params): void
    {
        $townId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/town/' . $townId . '/market')) return;

        $town = $this->requireOwnedTown($townId);
        if ($town === null) return;

        // NPC trade: redistribute resources evenly
        $res = DataParser::toFloatArray($town['resources']);
        $total = array_sum(array_slice($res, 0, 4)); // Only crop, lumber, stone, iron
        $each = $total / 4;

        for ($i = 0; $i < 4; $i++) {
            $res[$i] = round($each, 2);
        }

        $this->db->exec(
            'UPDATE towns SET resources = ? WHERE id = ?',
            [DataParser::serialize($res), $townId]
        );

        $this->flashAndRedirect('NPC trade completed.', '/town/' . $townId . '/market');
    }

    /**
     * Check if the route between two towns crosses water (simplified: any tile between them is water).
     */
    private function isWaterRoute(array $from, array $to, MapService $mapService): bool
    {
        $midX = (int) round(((int) $from['x'] + (int) $to['x']) / 2);
        $midY = (int) round(((int) $from['y'] + (int) $to['y']) / 2);
        $midTile = $mapService->getTile($midX, $midY);

        return $midTile !== null && (int) $midTile['type'] === 2;
    }

    private function tradeLabel(int $type, int $subType): string
    {
        if ($type === 0) {
            $resourceKeys = [0 => 'crop', 1 => 'lumber', 2 => 'stone', 3 => 'iron'];
            $key = $resourceKeys[$subType] ?? null;
            if ($key !== null) {
                return (string) ($this->f3->get('lang.' . $key) ?? ucfirst($key));
            }
            return 'Resource ' . $subType;
        }

        return ($this->f3->get('lang.weapon') ?? 'Weapon') . ' ' . $subType;
    }

    private function resolveTargetTownId(string $targetName, int $x, int $y): int
    {
        if ($targetName !== '') {
            $row = $this->db->exec('SELECT id FROM towns WHERE name = ? LIMIT 1', [$targetName]);
            return (int) ($row[0]['id'] ?? 0);
        }

        if ($x > 0 || $y > 0) {
            $row = $this->db->exec(
                'SELECT subtype FROM map WHERE x = ? AND y = ? AND type = 3 LIMIT 1',
                [$x, $y]
            );
            return (int) ($row[0]['subtype'] ?? 0);
        }

        return 0;
    }
}
