<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\InputSanitizer;
use Devana\Helpers\AvatarHelper;
use Devana\Services\ChatService;

final class ChatController extends Controller
{
    public function room(\Base $f3, array $params): void
    {
        $roomId = (int) ($params['id'] ?? 1);
        $chatService = new ChatService($this->db);
        $rooms = $chatService->getRooms();
        $messages = $chatService->getMessages($roomId);

        $user = $this->requireAuth();
        if ($user === null) return;

        $currentRoomName = '';
        foreach ($rooms as $r) {
            if ((int) $r['id'] === $roomId) {
                $currentRoomName = $r['name'] ?? '';
                break;
            }
        }

        foreach ($messages as &$msg) {
            $avatarSeed = (string) ($msg['senderAvatarSeed'] ?? $msg['senderName'] ?? 'player');
            $avatarStyle = (string) ($msg['senderAvatarStyle'] ?? AvatarHelper::DEFAULT_STYLE);
            $avatarOptions = AvatarHelper::decodeAndNormalize(
                is_string($msg['senderAvatarOptions'] ?? null) ? $msg['senderAvatarOptions'] : null,
                $avatarSeed
            );
            $msg['sender_avatar'] = AvatarHelper::url($avatarStyle, $avatarOptions, 24);
        }
        unset($msg);

        $this->render('chat/room.html', [
            'page_title' => $f3->get('lang.chat') ?? 'Chat',
            'rooms' => $rooms,
            'chat_messages' => array_reverse($messages),
            'current_room' => $roomId,
            'room' => $roomId,
            'room_name' => $currentRoomName,
            'user_alliance_id' => $user['alliance'] ?? 0,
            'admin_mode' => false,
        ]);
    }

    public function send(\Base $f3, array $params): void
    {
        $roomId = (int) ($params['id'] ?? 1);

        if (!$this->validateCsrfToken()) {
            $this->redirect('/chat/' . $roomId);
            return;
        }

        $user = $this->requireAuth();
        if ($user === null) return;
        $message = InputSanitizer::clean($this->post('message', ''));

        if (empty($message)) {
            $this->redirect('/chat/' . $roomId);
            return;
        }

        // Check if user is muted
        $dbUser = $this->db->exec('SELECT mute FROM users WHERE id = ?', [$user['id']]);
        if (!empty($dbUser) && (int) ($dbUser[0]['mute'] ?? 0) === 1) {
            $this->flashAndRedirect('You are muted.', '/chat/' . $roomId);
            return;
        }

        $chatService = new ChatService($this->db);
        $chatService->sendMessage($roomId, $user['id'], 0, $message);

        $this->redirect('/chat/' . $roomId);
    }

    public function ajaxMessages(\Base $f3): void
    {
        $user = $this->currentUser();
        if ($user === null) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        try {
            $roomId = (int) ($this->get('room', 1));
            $chatService = new ChatService($this->db);
            $messages = $chatService->getMessages($roomId);
        } catch (\Throwable $e) {
            $this->jsonResponse([]);
            return;
        }

        $result = [];
        foreach (array_reverse($messages) as $msg) {
            $result[] = [
                'id' => (int) $msg['id'],
                'sender_id' => (int) ($msg['sender'] ?? 0),
                'sender' => $msg['senderName'] ?? 'Unknown',
                'avatar' => AvatarHelper::url(
                    (string) ($msg['senderAvatarStyle'] ?? AvatarHelper::DEFAULT_STYLE),
                    AvatarHelper::decodeAndNormalize(
                        is_string($msg['senderAvatarOptions'] ?? null) ? $msg['senderAvatarOptions'] : null,
                        (string) ($msg['senderAvatarSeed'] ?? $msg['senderName'] ?? 'player')
                    ),
                    24
                ),
                'message' => $msg['message'] ?? '',
                'time' => date('H:i', strtotime($msg['timeStamp'] ?? 'now')),
            ];
        }

        $this->jsonResponse($result);
    }

    public function ajaxSend(\Base $f3): void
    {
        if (!$this->validateCsrfToken()) {
            $this->jsonResponse(['error' => 'Invalid request.'], 403);
            return;
        }

        $user = $this->currentUser();
        if ($user === null) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $roomId = (int) ($f3->get('POST.room') ?? 1);
        $message = InputSanitizer::clean($f3->get('POST.message') ?? '');

        if (empty($message)) {
            $this->jsonResponse(['error' => 'Empty message'], 400);
            return;
        }

        // Check mute
        $dbUser = $this->db->exec('SELECT mute FROM users WHERE id = ?', [$user['id']]);
        if (!empty($dbUser) && (int) ($dbUser[0]['mute'] ?? 0) === 1) {
            $this->jsonResponse(['error' => 'You are muted.'], 403);
            return;
        }

        $chatService = new ChatService($this->db);
        $chatService->sendMessage($roomId, $user['id'], 0, $message);

        $this->jsonResponse(['ok' => true]);
    }

    public function ajaxUnread(\Base $f3): void
    {
        $user = $this->currentUser();
        if ($user === null) {
            $this->jsonResponse(['count' => 0]);
            return;
        }

        $uid = (int) $user['id'];
        try {
            $row = $this->db->exec('SELECT chat_last_seen FROM users WHERE id = ?', [$uid]);
            $lastSeen = $row[0]['chat_last_seen'] ?? null;

            if ($lastSeen === null) {
                $count = (int) ($this->db->exec(
                    'SELECT COUNT(*) AS cnt FROM chat WHERE sender <> ?',
                    [$uid]
                )[0]['cnt'] ?? 0);
            } else {
                $count = (int) ($this->db->exec(
                    'SELECT COUNT(*) AS cnt FROM chat WHERE timeStamp > ? AND sender <> ?',
                    [$lastSeen, $uid]
                )[0]['cnt'] ?? 0);
            }
        } catch (\Throwable $e) {
            $count = 0;
        }

        $this->jsonResponse(['count' => $count]);
    }

    public function ajaxMarkRead(\Base $f3): void
    {
        if (!$this->validateCsrfToken()) {
            $this->jsonResponse(['error' => 'Invalid request.'], 403);
            return;
        }

        $user = $this->currentUser();
        if ($user === null) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        $uid = (int) $user['id'];
        try {
            $this->db->exec('UPDATE users SET chat_last_seen = NOW() WHERE id = ?', [$uid]);
        } catch (\Throwable $e) {
            $this->jsonResponse(['ok' => false]);
            return;
        }

        $this->jsonResponse(['ok' => true]);
    }

    public function ajaxRooms(\Base $f3): void
    {
        $user = $this->currentUser();
        if ($user === null) {
            $this->jsonResponse(['error' => 'Unauthorized'], 401);
            return;
        }

        try {
            $chatService = new ChatService($this->db);
            $rooms = $chatService->getRooms();
        } catch (\Throwable $e) {
            $rooms = [];
        }

        $this->jsonResponse($rooms);
    }

    private function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public function rooms(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        if ((int) $user['level'] < 4) {
            $this->flashAndRedirect('Access denied.', '/towns');
            return;
        }

        $chatService = new ChatService($this->db);

        $action = $this->post('action', '');

        if ($action !== '') {
            if (!$this->validateCsrfToken()) {
                $this->flashAndRedirect('Invalid request.', '/chat/admin');
                return;
            }

            if ($action === 'create') {
                $name = InputSanitizer::clean($this->post('name', ''));
                if (!empty($name)) {
                    $chatService->createRoom($name);
                }
            } elseif ($action === 'delete') {
                $roomId = InputSanitizer::cleanInt($this->post('room_id', 0));
                $chatService->deleteRoom($roomId);
            }
        }

        $rooms = $chatService->getRooms();

        $this->render('chat/room.html', [
            'page_title' => 'Chat Rooms',
            'rooms' => $rooms,
            'chat_messages' => [],
            'current_room' => 0,
            'room' => 0,
            'room_name' => 'Admin',
            'user_alliance_id' => $user['alliance'] ?? 0,
            'admin_mode' => true,
        ]);
    }
}
