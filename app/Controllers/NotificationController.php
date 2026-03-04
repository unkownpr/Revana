<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Services\NotificationService;

final class NotificationController extends Controller
{
    public function getUnread(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        $this->loadLanguage();
        $lang = (array) $this->f3->get('lang');

        $service = new NotificationService($this->db);
        $rawNotifs = $service->getUnread((int) $user['id'], 20);

        // Resolve message keys to display text
        $notifs = [];
        foreach ($rawNotifs as $n) {
            $key = (string) ($n['message_key'] ?? '');
            $text = $lang[$key] ?? $key;
            $notifs[] = [
                'id' => (int) $n['id'],
                'type' => (int) $n['type'],
                'text' => $text,
                'url' => (string) ($n['url'] ?? ''),
                'created_at' => (string) ($n['created_at'] ?? ''),
            ];
        }

        header('Content-Type: application/json');
        echo json_encode(['notifications' => $notifs, 'count' => count($notifs)]);
    }

    public function markRead(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        if (!$this->requireCsrf('/')) return;

        $id = (int) $this->post('id', 0);
        $service = new NotificationService($this->db);

        if ($id > 0) {
            $service->markOneRead((int) $user['id'], $id);
        } else {
            $service->markAllRead((int) $user['id']);
        }

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    }
}
