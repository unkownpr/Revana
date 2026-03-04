<?php

declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\InputSanitizer;
use Devana\Services\MapEditorService;

final class MapEditorController extends Controller
{
    private function getBackgroundDir(): string
    {
        return $this->f3->get('ROOT') . '/default/1/';
    }

    private function getMapTileDir(): string
    {
        return $this->f3->get('ROOT') . '/default/map/';
    }

    private function listBackgrounds(): array
    {
        $dir = $this->getBackgroundDir();
        $patterns = ['back*.png', 'back*.jpg', 'back*.jpeg', 'back*.webp', 'back*.gif'];
        $files = [];
        foreach ($patterns as $pattern) {
            $matched = glob($dir . $pattern) ?: [];
            foreach ($matched as $m) {
                $files[] = $m;
            }
        }

        $names = array_values(array_unique(array_filter(array_map('basename', $files), static function (string $name): bool {
            return (bool) preg_match('/^back(\d*)\.(png|jpe?g|webp|gif)$/i', $name);
        })));
        sort($names);
        return $names;
    }

    private function getConfigValue(string $name, string $default = ''): string
    {
        $row = $this->db->exec('SELECT value FROM config WHERE name = ? ORDER BY ord ASC LIMIT 1', [$name]);
        return (string) ($row[0]['value'] ?? $default);
    }

    private function setConfigValue(string $name, string $value): void
    {
        $existing = $this->db->exec('SELECT ord FROM config WHERE name = ? ORDER BY ord ASC LIMIT 1', [$name]);
        if (!empty($existing)) {
            $this->db->exec('UPDATE config SET value = ? WHERE ord = ?', [$value, (int) $existing[0]['ord']]);
            return;
        }

        $maxOrdRow = $this->db->exec('SELECT MAX(ord) AS mx FROM config');
        $nextOrd = (int) (($maxOrdRow[0]['mx'] ?? 0) + 1);
        $this->db->exec('INSERT INTO config (name, value, ord) VALUES (?, ?, ?)', [$name, $value, $nextOrd]);
    }

    private function listTownTileImages(): array
    {
        $dir = $this->getMapTileDir();
        $patterns = [
            'env_3*.gif', 'env_3*.png', 'env_3*.jpg', 'env_3*.jpeg', 'env_3*.webp',
            'tile_3_*.gif', 'tile_3_*.png', 'tile_3_*.jpg', 'tile_3_*.jpeg', 'tile_3_*.webp'
        ];
        $files = [];
        foreach ($patterns as $pattern) {
            $matched = glob($dir . $pattern) ?: [];
            foreach ($matched as $m) {
                $files[] = $m;
            }
        }

        $names = array_values(array_unique(array_map('basename', $files)));
        sort($names);
        return $names;
    }

    /**
     * @return array<string, string>
     */
    private function getBiomeBackgroundSettings(): array
    {
        $rows = $this->db->exec("SELECT name, value FROM config WHERE name LIKE 'town_background_biome_%'");
        $out = [];
        foreach ($rows as $row) {
            $full = (string) ($row['name'] ?? '');
            $key = substr($full, strlen('town_background_biome_'));
            if (preg_match('/^\d+_\d+$/', $key)) {
                $out[$key] = (string) ($row['value'] ?? '');
            }
        }
        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function getTownTileBiomeSettings(): array
    {
        $rows = $this->db->exec("SELECT name, value FROM config WHERE name LIKE 'town_tile_biome_%'");
        $out = [];
        foreach ($rows as $row) {
            $full = (string) ($row['name'] ?? '');
            $key = substr($full, strlen('town_tile_biome_'));
            if (preg_match('/^\d+_\d+$/', $key)) {
                $out[$key] = (string) ($row['value'] ?? '');
            }
        }
        return $out;
    }

    /**
     * @return array{defaults: array<int, string>, biome: array<int, array<string, string>>}
     */
    private function getTownTileFactionSettings(): array
    {
        $rows = $this->db->exec("SELECT name, value FROM config WHERE name LIKE 'town_tile_faction_%'");
        $out = [
            'defaults' => [],
            'biome' => [],
        ];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $value = (string) ($row['value'] ?? '');

            if (preg_match('/^town_tile_faction_(\d+)_default$/', $name, $m)) {
                $factionId = (int) $m[1];
                $out['defaults'][$factionId] = $value;
                continue;
            }

            if (preg_match('/^town_tile_faction_(\d+)_biome_(\d+_\d+)$/', $name, $m)) {
                $factionId = (int) $m[1];
                $biomeKey = (string) $m[2];
                if (!isset($out['biome'][$factionId])) {
                    $out['biome'][$factionId] = [];
                }
                $out['biome'][$factionId][$biomeKey] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $brushes
     * @return array<int, array{key:string,label:string}>
     */
    private function buildBiomeOptions(array $brushes): array
    {
        $options = [];
        foreach ($brushes as $br) {
            $type = (int) ($br['type'] ?? 0);
            $subtype = (int) ($br['subtype'] ?? 0);
            $key = $type . '_' . $subtype;
            if (isset($options[$key])) {
                continue;
            }
            $label = trim((string) ($br['translated_name'] ?? $br['name'] ?? $key));
            $options[$key] = [
                'key' => $key,
                'label' => $label,
            ];
        }

        $values = array_values($options);
        usort($values, static function (array $a, array $b): int {
            [$at, $as] = array_map('intval', explode('_', $a['key']));
            [$bt, $bs] = array_map('intval', explode('_', $b['key']));
            return $at === $bt ? ($as <=> $bs) : ($at <=> $bt);
        });
        return $values;
    }

    /**
     * Normalize uploaded tile image into an 80x80 transparent canvas and anchor visual content to bottom-center.
     * Returns true when normalized output is written.
     */
    private function normalizeTileToIsometricCanvas(string $sourcePath, string $destPath): bool
    {
        $imgInfo = @getimagesize($sourcePath);
        if ($imgInfo === false) {
            return false;
        }

        $mime = (string) ($imgInfo['mime'] ?? '');
        $src = null;
        if ($mime === 'image/png') {
            $src = @imagecreatefrompng($sourcePath);
        } elseif ($mime === 'image/gif') {
            $src = @imagecreatefromgif($sourcePath);
        } elseif ($mime === 'image/jpeg') {
            $src = @imagecreatefromjpeg($sourcePath);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $src = @imagecreatefromwebp($sourcePath);
        }
        if (!$src) {
            return false;
        }

        $sw = imagesx($src);
        $sh = imagesy($src);
        if ($sw <= 0 || $sh <= 0) {
            imagedestroy($src);
            return false;
        }

        // Convert source into alpha-aware truecolor first.
        $srcTrue = imagecreatetruecolor($sw, $sh);
        imagealphablending($srcTrue, false);
        imagesavealpha($srcTrue, true);
        $transparent = imagecolorallocatealpha($srcTrue, 0, 0, 0, 127);
        imagefill($srcTrue, 0, 0, $transparent);
        imagecopy($srcTrue, $src, 0, 0, 0, 0, $sw, $sh);
        imagedestroy($src);

        // Find non-transparent bounding box.
        $minX = $sw;
        $minY = $sh;
        $maxX = -1;
        $maxY = -1;
        // Keep faint shadow pixels too; otherwise crop can be slightly too tight and look "floating".
        $alphaCutoff = 126;
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $rgba = imagecolorat($srcTrue, $x, $y);
                $a = ($rgba & 0x7F000000) >> 24;
                if ($a < $alphaCutoff) {
                    if ($x < $minX) $minX = $x;
                    if ($y < $minY) $minY = $y;
                    if ($x > $maxX) $maxX = $x;
                    if ($y > $maxY) $maxY = $y;
                }
            }
        }

        if ($maxX < $minX || $maxY < $minY) {
            $minX = 0;
            $minY = 0;
            $maxX = $sw - 1;
            $maxY = $sh - 1;
        }

        $cropW = max(1, $maxX - $minX + 1);
        $cropH = max(1, $maxY - $minY + 1);
        $targetW = 80;
        $targetH = 80;

        // Keep aspect ratio and downscale if too large.
        $scale = min($targetW / $cropW, $targetH / $cropH, 1.0);
        $dstW = max(1, (int) round($cropW * $scale));
        $dstH = max(1, (int) round($cropH * $scale));
        $dstX = (int) floor(($targetW - $dstW) / 2);
        $dstY = $targetH - $dstH;

        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $canvasTransparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $canvasTransparent);

        imagecopyresampled(
            $canvas,
            $srcTrue,
            $dstX,
            $dstY,
            $minX,
            $minY,
            $dstW,
            $dstH,
            $cropW,
            $cropH
        );

        $saved = imagepng($canvas, $destPath);
        imagedestroy($srcTrue);
        imagedestroy($canvas);
        return (bool) $saved;
    }

    public function list(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;

        $service = new MapEditorService($this->db);

        $this->render('admin/maps/list.html', [
            'page_title' => 'Map Editor',
            'editor_maps' => $service->listMaps(),
        ]);
    }

    public function showCreate(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;

        $this->render('admin/maps/create.html', [
            'page_title' => 'Create Map',
        ]);
    }

    public function create(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin/maps')) return;

        $user = $this->currentUser();

        $name = InputSanitizer::clean($this->post('name', ''));
        $width = InputSanitizer::cleanInt($this->post('width', 50));
        $height = InputSanitizer::cleanInt($this->post('height', 50));
        $generateRandom = (bool) $this->post('generate_random', false);

        if (empty($name)) {
            $this->flashAndRedirect('Map name is required.', '/admin/maps/create');
            return;
        }

        $width = max(10, min(200, $width));
        $height = max(10, min(200, $height));

        $service = new MapEditorService($this->db);
        $mapId = $service->createMap($name, $width, $height, (int) $user['id']);

        if ($generateRandom) {
            $service->generateRandom($mapId);
        }

        $this->flashAndRedirect('Map created.', '/admin/maps/' . $mapId . '/edit');
    }

    public function editor(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;

        $mapId = (int) ($params['id'] ?? 0);
        $service = new MapEditorService($this->db);
        $map = $service->getMap($mapId);

        if (!$map) {
            $this->flashAndRedirect('Map not found.', '/admin/maps');
            return;
        }

        $service->ensureTileBrushesTable();
        $brushes = $service->getAllBrushes();
        $brushImageMap = $service->getBrushImageMap();

        $this->loadLanguage();
        $lang = $this->f3->get('lang');
        $langCode = strtolower($this->f3->get('current_lang_code'));
        $brushNameMap = $service->getTranslatedBrushNameMap($lang, $langCode);

        // Add translated_name to each brush for palette display
        foreach ($brushes as &$br) {
            $key = $br['type'] . '_' . $br['subtype'];
            $br['translated_name'] = $brushNameMap[$key] ?? $br['name'];
        }
        unset($br);

        $x = InputSanitizer::cleanInt($this->get('x', 0));
        $y = InputSanitizer::cleanInt($this->get('y', 0));

        // Clamp to map bounds
        $x = max(0, min((int) $map['width'] - 1, $x));
        $y = max(0, min((int) $map['height'] - 1, $y));

        $data = $this->buildEditorMapData($service, $mapId, $x, $y, (int) $map['width'], (int) $map['height'], $brushImageMap, $brushNameMap);
        $data['page_title'] = 'Edit: ' . htmlspecialchars($map['name']);
        $data['editor_map'] = $map;
        $data['brushes'] = $brushes;
        $data['brushImageMap'] = $brushImageMap;
        $data['brushNameMap'] = $brushNameMap;
        $data['backgrounds'] = $this->listBackgrounds();
        $data['bg_default_setting'] = $this->getConfigValue('town_background_default', 'back.png');
        $bgBiomeSettings = $this->getBiomeBackgroundSettings();
        $townTileOptions = $this->listTownTileImages();
        $townTileDefault = $this->getConfigValue('town_tile_default', 'env_31.gif');
        if (!in_array($townTileDefault, $townTileOptions, true)) {
            $townTileDefault = $townTileOptions[0] ?? 'env_31.gif';
        }
        $townTileBiomeSettings = $this->getTownTileBiomeSettings();
        $townTileFactionSettings = $this->getTownTileFactionSettings();
        $factions = $this->db->exec('SELECT id, name FROM factions ORDER BY id');
        $biomeOptions = $this->buildBiomeOptions($brushes);
        foreach ($biomeOptions as &$opt) {
            $opt['selected'] = (string) ($bgBiomeSettings[$opt['key']] ?? '');
            $opt['town_selected'] = (string) ($townTileBiomeSettings[$opt['key']] ?? '');
        }
        unset($opt);

        $factionTileRules = [];
        foreach ($factions as $f) {
            $fid = (int) ($f['id'] ?? 0);
            if ($fid <= 0) {
                continue;
            }
            $frBiomes = [];
            foreach ($biomeOptions as $bo) {
                $key = (string) ($bo['key'] ?? '');
                $frBiomes[] = [
                    'key' => $key,
                    'label' => (string) ($bo['label'] ?? $key),
                    'selected' => (string) ($townTileFactionSettings['biome'][$fid][$key] ?? ''),
                ];
            }

            $factionTileRules[] = [
                'id' => $fid,
                'name' => (string) ($f['name'] ?? ('Faction ' . $fid)),
                'default_selected' => (string) ($townTileFactionSettings['defaults'][$fid] ?? ''),
                'biomes' => $frBiomes,
            ];
        }

        $data['biome_options'] = $biomeOptions;
        $data['bg_biome_settings'] = $bgBiomeSettings;
        $data['town_tile_options'] = $townTileOptions;
        $data['town_tile_default_setting'] = $townTileDefault;
        $data['town_tile_biome_settings'] = $townTileBiomeSettings;
        $data['faction_tile_rules'] = $factionTileRules;

        $this->render('admin/maps/editor.html', $data);
    }

    public function updateBackgroundSettings(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin/maps')) return;

        $mapId = (int) ($params['id'] ?? 0);
        $available = $this->listBackgrounds();
        $defaultBg = basename((string) $this->post('default_background', 'back.png'));
        if (!in_array($defaultBg, $available, true)) {
            $defaultBg = 'back.png';
        }
        $this->setConfigValue('town_background_default', $defaultBg);

        $postedMap = $this->post('biome_map', []);
        if (!is_array($postedMap)) {
            $postedMap = [];
        }
        $existingMap = $this->getBiomeBackgroundSettings();
        $allKeys = array_unique(array_merge(array_keys($existingMap), array_keys($postedMap)));

        foreach ($allKeys as $biomeKeyRaw) {
            $biomeKey = (string) $biomeKeyRaw;
            if (!preg_match('/^\d+_\d+$/', $biomeKey)) {
                continue;
            }
            $raw = (string) ($postedMap[$biomeKey] ?? '');
            $val = basename($raw);
            if ($val !== '' && in_array($val, $available, true)) {
                $this->setConfigValue('town_background_biome_' . $biomeKey, $val);
            } else {
                $this->setConfigValue('town_background_biome_' . $biomeKey, '');
            }
        }

        // Save map town icon defaults and biome-specific town icon rules.
        $townTileOptions = $this->listTownTileImages();
        $townDefault = basename((string) $this->post('town_tile_default', 'env_31.gif'));
        if (!in_array($townDefault, $townTileOptions, true)) {
            $townDefault = $townTileOptions[0] ?? 'env_31.gif';
        }
        $this->setConfigValue('town_tile_default', $townDefault);

        $postedTownMap = $this->post('town_tile_map', []);
        if (!is_array($postedTownMap)) {
            $postedTownMap = [];
        }
        $existingTownMap = $this->getTownTileBiomeSettings();
        $allTownKeys = array_unique(array_merge(array_keys($existingTownMap), array_keys($postedTownMap), $allKeys));

        foreach ($allTownKeys as $biomeKeyRaw) {
            $biomeKey = (string) $biomeKeyRaw;
            if (!preg_match('/^\d+_\d+$/', $biomeKey)) {
                continue;
            }
            $raw = (string) ($postedTownMap[$biomeKey] ?? '');
            $val = basename($raw);
            if ($val !== '' && in_array($val, $townTileOptions, true)) {
                $this->setConfigValue('town_tile_biome_' . $biomeKey, $val);
            } else {
                $this->setConfigValue('town_tile_biome_' . $biomeKey, '');
            }
        }

        // Save faction-specific town icon rules.
        $factions = $this->db->exec('SELECT id FROM factions ORDER BY id');
        $postedFactionDefaults = $this->post('town_tile_faction_default', []);
        if (!is_array($postedFactionDefaults)) {
            $postedFactionDefaults = [];
        }
        $postedFactionMap = $this->post('town_tile_faction_map', []);
        if (!is_array($postedFactionMap)) {
            $postedFactionMap = [];
        }
        $existingFactionSettings = $this->getTownTileFactionSettings();

        foreach ($factions as $f) {
            $fid = (int) ($f['id'] ?? 0);
            if ($fid <= 0) {
                continue;
            }

            $fDefRaw = (string) ($postedFactionDefaults[$fid] ?? '');
            $fDefVal = basename($fDefRaw);
            if ($fDefVal !== '' && in_array($fDefVal, $townTileOptions, true)) {
                $this->setConfigValue('town_tile_faction_' . $fid . '_default', $fDefVal);
            } else {
                $this->setConfigValue('town_tile_faction_' . $fid . '_default', '');
            }

            $postedForFaction = $postedFactionMap[$fid] ?? [];
            if (!is_array($postedForFaction)) {
                $postedForFaction = [];
            }

            $existingForFaction = $existingFactionSettings['biome'][$fid] ?? [];
            $factionKeys = array_unique(array_merge(array_keys($existingForFaction), array_keys($postedForFaction), $allTownKeys));
            foreach ($factionKeys as $biomeKeyRaw) {
                $biomeKey = (string) $biomeKeyRaw;
                if (!preg_match('/^\d+_\d+$/', $biomeKey)) {
                    continue;
                }
                $raw = (string) ($postedForFaction[$biomeKey] ?? '');
                $val = basename($raw);
                if ($val !== '' && in_array($val, $townTileOptions, true)) {
                    $this->setConfigValue('town_tile_faction_' . $fid . '_biome_' . $biomeKey, $val);
                } else {
                    $this->setConfigValue('town_tile_faction_' . $fid . '_biome_' . $biomeKey, '');
                }
            }
        }

        $this->flashAndRedirect('Biome visual rules updated.', '/admin/maps/' . $mapId . '/edit');
    }

    public function editorAjax(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;

        $mapId = (int) ($params['id'] ?? 0);
        $service = new MapEditorService($this->db);
        $map = $service->getMap($mapId);

        if (!$map) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Map not found']);
            return;
        }

        $service->ensureTileBrushesTable();
        $brushImageMap = $service->getBrushImageMap();

        $this->loadLanguage();
        $lang = $this->f3->get('lang');
        $langCode = strtolower($this->f3->get('current_lang_code'));
        $brushNameMap = $service->getTranslatedBrushNameMap($lang, $langCode);

        $x = InputSanitizer::cleanInt($this->get('x', 0));
        $y = InputSanitizer::cleanInt($this->get('y', 0));

        $x = max(0, min((int) $map['width'] - 1, $x));
        $y = max(0, min((int) $map['height'] - 1, $y));

        $data = $this->buildEditorMapData($service, $mapId, $x, $y, (int) $map['width'], (int) $map['height'], $brushImageMap, $brushNameMap);
        $data['brushImageMap'] = $brushImageMap;
        $data['brushNameMap'] = $brushNameMap;

        header('Content-Type: application/json');
        echo json_encode($data);
    }

    public function paintTile(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;

        $mapId = (int) ($params['id'] ?? 0);

        // Read JSON body
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        $x = (int) ($input['x'] ?? 0);
        $y = (int) ($input['y'] ?? 0);
        $type = (int) ($input['type'] ?? 0);
        $subtype = (int) ($input['subtype'] ?? 0);

        $service = new MapEditorService($this->db);
        $service->setTile($mapId, $x, $y, $type, $subtype);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    public function activate(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin/maps')) return;

        $mapId = (int) ($params['id'] ?? 0);
        $service = new MapEditorService($this->db);
        $error = $service->activateMap($mapId);

        if ($error !== '') {
            $this->flashAndRedirect($error, '/admin/maps');
            return;
        }

        $this->flashAndRedirect('Map activated and deployed to game.', '/admin/maps');
    }

    public function delete(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin/maps')) return;

        $mapId = (int) ($params['id'] ?? 0);
        $service = new MapEditorService($this->db);
        $service->deleteMap($mapId);

        $this->flashAndRedirect('Map deleted.', '/admin/maps');
    }

    public function generate(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin/maps')) return;

        $mapId = (int) ($params['id'] ?? 0);
        $service = new MapEditorService($this->db);
        $service->generateRandom($mapId);

        $this->flashAndRedirect('Random terrain generated.', '/admin/maps/' . $mapId . '/edit');
    }

    /**
     * Build isometric map data for the editor viewport.
     *
     * @return array<string, mixed>
     */
    public function uploadTileImage(\Base $f3): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin/maps')) return;

        $mapId = InputSanitizer::cleanInt($this->post('map_id', 0));
        $name = InputSanitizer::clean($this->post('name', ''));
        $type = InputSanitizer::cleanInt($this->post('type', 1));
        $subtypeRaw = $this->post('subtype', '');

        if (empty($name)) {
            $this->flashAndRedirect('Brush name is required.', '/admin/maps/' . $mapId . '/edit');
            return;
        }

        $service = new MapEditorService($this->db);
        $service->ensureTileBrushesTable();

        $subtype = ($subtypeRaw === '' || $subtypeRaw === null)
            ? $service->getNextSubtype($type)
            : InputSanitizer::cleanInt($subtypeRaw);

        // Validate uploaded file
        $file = $f3->get('FILES.tile_image');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $this->flashAndRedirect('File upload failed.', '/admin/maps/' . $mapId . '/edit');
            return;
        }

        // Validate MIME type
        $allowedMimes = [
            'image/gif' => 'gif',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$mime])) {
            $this->flashAndRedirect('Only GIF, PNG, JPG and WEBP images are allowed.', '/admin/maps/' . $mapId . '/edit');
            return;
        }
        $ext = $allowedMimes[$mime];

        // Validate dimensions
        $imgInfo = getimagesize($file['tmp_name']);
        if ($imgInfo === false) {
            $this->flashAndRedirect('Invalid image file.', '/admin/maps/' . $mapId . '/edit');
            return;
        }

        // Safe filename
        $safeFile = 'tile_' . $type . '_' . $subtype;
        $destDir = $f3->get('ROOT') . '/default/map/';
        $destExt = 'png';
        $destPath = $destDir . $safeFile . '.' . $destExt;

        $tmpUploadPath = $file['tmp_name'];
        if (!is_uploaded_file($tmpUploadPath)) {
            $this->flashAndRedirect('Failed to read uploaded file.', '/admin/maps/' . $mapId . '/edit');
            return;
        }

        if (!$this->normalizeTileToIsometricCanvas($tmpUploadPath, $destPath)) {
            // Fallback: keep original if normalization fails.
            $destExt = $ext;
            $destPath = $destDir . $safeFile . '.' . $destExt;
            if (!move_uploaded_file($tmpUploadPath, $destPath)) {
                $this->flashAndRedirect('Failed to save file.', '/admin/maps/' . $mapId . '/edit');
                return;
            }
        }

        // Build translations JSON from form inputs
        $translations = [];
        foreach (['tr', 'de', 'fr', 'it', 'nl', 'ro'] as $code) {
            $val = InputSanitizer::clean($this->post('name_' . $code, ''));
            if ($val !== '') {
                $translations[$code] = $val;
            }
        }
        $translationsJson = !empty($translations) ? json_encode($translations, JSON_UNESCAPED_UNICODE) : '{}';

        $service->addBrush($type, $subtype, $name, $safeFile . '.' . $destExt, $translationsJson);

        $this->flashAndRedirect('Custom tile brush added.', '/admin/maps/' . $mapId . '/edit');
    }

    public function deleteTileImage(\Base $f3, array $params = []): void
    {
        if (!$this->requireAdmin('/towns')) return;
        if (!$this->requireCsrf('/admin/maps')) return;

        $brushId = (int) ($params['id'] ?? 0);
        $mapId = InputSanitizer::cleanInt($this->post('map_id', 0));

        $service = new MapEditorService($this->db);
        $deleted = $service->deleteBrush($brushId);

        if ($deleted) {
            // Remove custom file from disk
            $filePath = $f3->get('ROOT') . '/default/map/' . $deleted['img_file'];
            if (file_exists($filePath) && strpos($deleted['img_file'], 'tile_') === 0) {
                unlink($filePath);
            }
        }

        $this->flashAndRedirect('Brush deleted.', '/admin/maps/' . $mapId . '/edit');
    }

    /**
     * @param array<string, string> $imageMap
     * @param array<string, string> $nameMap
     * @return array<string, mixed>
     */
    private function buildEditorMapData(MapEditorService $service, int $mapId, int $x, int $y, int $mapWidth, int $mapHeight, array $imageMap = [], array $nameMap = []): array
    {
        $tiles = $service->getTilesInRange($mapId, $x, $y, 3);

        $tileMap = [];
        foreach ($tiles as $tile) {
            $key = $tile['x'] . ',' . $tile['y'];
            $tileMap[$key] = $tile;
        }

        $townTileOptions = $this->listTownTileImages();
        $townTileDefault = $this->getConfigValue('town_tile_default', 'env_31.gif');
        if (!in_array($townTileDefault, $townTileOptions, true)) {
            $townTileDefault = $townTileOptions[0] ?? 'env_31.gif';
        }
        $townTileBiomeSettings = $this->getTownTileBiomeSettings();

        $user = $this->currentUser();
        $imgs = '/default/';
        if ($user) {
            $rawImgs = $user['imgs'] ?? 'default/';
            $imgs = '/' . ltrim($rawImgs, '/');
        }

        $mapTiles = [];
        for ($k = 3; $k >= -3; $k--) {
            for ($j = -3; $j <= 3; $j++) {
                $tileX = $x + $j;
                $tileY = $y + $k;
                $st_x = ($k + 3) * 40 + ($j + 3) * 40;
                $st_y = (3 - $k) * 20 + ($j + 3) * 20;

                $key = $tileX . ',' . $tileY;
                $tile = $tileMap[$key] ?? null;

                $imgFile = 'env_x.gif';
                $descName = 'void';
                $tileType = -1;
                $tileSubtype = 0;

                if ($tile) {
                    $type = (int) $tile['type'];
                    $subtype = (int) $tile['subtype'];
                    $tileType = $type;
                    $tileSubtype = $subtype;

                    if ($type === 3) {
                        $imgKey = $type . '_' . $subtype;
                        $imgFromBrush = (string) ($imageMap[$imgKey] ?? '');
                        if ($imgFromBrush !== '') {
                            $imgFile = $imgFromBrush;
                        } else {
                            $biomeKey = $this->detectBiomeKeyFromTileMap($tileMap, $tileX, $tileY);
                            $biomeTown = (string) ($townTileBiomeSettings[$biomeKey] ?? '');
                            if ($biomeTown !== '' && in_array($biomeTown, $townTileOptions, true)) {
                                $imgFile = $biomeTown;
                            } else {
                                $imgFile = $townTileDefault;
                            }
                        }
                        $descName = 'Town';
                    } else {
                        $imgKey = $type . '_' . $subtype;
                        $imgFile = $imageMap[$imgKey] ?? 'env_11.gif';
                        $descName = $nameMap[$imgKey] ?? 'Land';
                    }
                }

                $coords = ($st_x + 40) . ',' . $st_y . ','
                    . ($st_x + 80) . ',' . ($st_y + 20) . ','
                    . ($st_x + 40) . ',' . ($st_y + 40) . ','
                    . $st_x . ',' . ($st_y + 20);

                $mapTiles[] = [
                    'st_x' => $st_x,
                    'st_y' => $st_y,
                    'img' => $imgs . 'map/' . $imgFile,
                    'coords' => $coords,
                    'tile_x' => $tileX,
                    'tile_y' => $tileY,
                    'tile_type' => $tileType,
                    'tile_subtype' => $tileSubtype,
                    'desc_name' => $descName,
                ];
            }
        }

        $yLabels = [];
        for ($k = 3; $k >= -3; $k--) {
            $yLabels[] = [
                'label' => $y + $k,
                'st_x' => ($k + 3) * 40,
                'st_y' => (3 - $k) * 20,
            ];
        }

        $xLabels = [];
        for ($j = -3; $j <= 3; $j++) {
            $xLabels[] = [
                'label' => $x + $j,
                'st_x' => ($j + 3) * 40,
                'st_y' => 160 + ($j + 3) * 20,
            ];
        }

        $nav = [
            'north_x' => $x,
            'north_y' => min($mapHeight - 1, $y + 1),
            'south_x' => $x,
            'south_y' => max(0, $y - 1),
            'east_x' => min($mapWidth - 1, $x + 1),
            'east_y' => $y,
            'west_x' => max(0, $x - 1),
            'west_y' => $y,
        ];

        return [
            'center_x' => $x,
            'center_y' => $y,
            'mapTiles' => $mapTiles,
            'yLabels' => $yLabels,
            'xLabels' => $xLabels,
            'nav' => $nav,
            'imgs' => $imgs,
            'map_id' => $mapId,
            'map_width' => $mapWidth,
            'map_height' => $mapHeight,
        ];
    }

    /**
     * Detect biome key around a town tile using nearby non-town tiles.
     *
     * @param array<string, array<string, mixed>> $tileMap
     */
    private function detectBiomeKeyFromTileMap(array $tileMap, int $x, int $y): string
    {
        $counts = [];
        for ($dy = -1; $dy <= 1; $dy++) {
            for ($dx = -1; $dx <= 1; $dx++) {
                if ($dx === 0 && $dy === 0) {
                    continue;
                }
                $key = ($x + $dx) . ',' . ($y + $dy);
                if (!isset($tileMap[$key])) {
                    continue;
                }
                $t = (int) ($tileMap[$key]['type'] ?? -1);
                if ($t === 3 || $t < 0) {
                    continue;
                }
                $s = (int) ($tileMap[$key]['subtype'] ?? 0);
                $bs = $t . '_' . $s;
                $counts[$bs] = ($counts[$bs] ?? 0) + 1;
            }
        }

        if (!empty($counts)) {
            arsort($counts);
            return (string) array_key_first($counts);
        }

        return '1_0';
    }
}
