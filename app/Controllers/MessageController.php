<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\InputSanitizer;
use Devana\Services\MessageService;

final class MessageController extends Controller
{
    public function inbox(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;

        $messageService = new MessageService($this->db);
        $messages = $messageService->getInbox($user['id']);

        $this->render('message/inbox.html', [
            'page_title' => $f3->get('lang.messages') ?? 'Messages',
            'messages' => $messages,
            'box' => 'messages',
            'page' => 1,
            'total_pages' => 1,
        ]);
    }

    public function view(\Base $f3, array $params): void
    {
        $messageId = (int) ($params['id'] ?? 0);
        $user = $this->requireAuth();
        if ($user === null) return;

        $messageService = new MessageService($this->db);
        $message = $messageService->findById($messageId);

        if ($message === null || (int) $message['recipient'] !== $user['id']) {
            $this->flashAndRedirect('Message not found.', '/messages');
            return;
        }

        $this->render('message/view.html', [
            'page_title' => $message['subject'],
            'message' => $message,
        ]);
    }

    public function showCompose(\Base $f3): void
    {
        $this->render('message/compose.html', [
            'page_title' => $f3->get('lang.compose') ?? 'Compose',
            'prefill_to' => InputSanitizer::clean($this->get('to', '')),
            'prefill_subject' => InputSanitizer::clean($this->get('subject', '')),
            'prefill_content' => '',
        ]);
    }

    public function send(\Base $f3): void
    {
        if (!$this->requireCsrf('/messages/compose')) return;
        if (!$this->requireRecaptcha('/messages/compose')) return;

        $user = $this->requireAuth();
        if ($user === null) return;
        // Keep raw recipient text for reliable username matching.
        $recipientName = trim(strip_tags((string) $this->post('recipient', '')));
        $subject = InputSanitizer::clean($this->post('subject', ''));
        $contents = InputSanitizer::clean($this->post('contents', ''));

        if (empty($recipientName) || empty($subject) || empty($contents)) {
            $this->flashAndRedirect('Please fill in all fields.', '/messages/compose');
            return;
        }

        $messageService = new MessageService($this->db);
        $recipientId = $messageService->findRecipientIdByName($recipientName);
        if ($recipientId === null) {
            $this->flashAndRedirect('User not found.', '/messages/compose');
            return;
        }

        $sent = $messageService->send($user['id'], $recipientId, $subject, $contents);
        if (!$sent) {
            $this->flashAndRedirect('You are blocked by this user.', '/messages/compose');
            return;
        }

        $this->flashAndRedirect('Message sent.', '/messages');
    }

    public function delete(\Base $f3, array $params): void
    {
        $messageId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/messages')) return;

        $user = $this->requireAuth();
        if ($user === null) return;
        $messageService = new MessageService($this->db);
        $messageService->delete($messageId, $user['id']);

        $this->flashAndRedirect('Message deleted.', '/messages');
    }

    public function deleteAll(\Base $f3): void
    {
        if (!$this->requireCsrf('/messages')) return;

        $user = $this->requireAuth();
        if ($user === null) return;
        $messageService = new MessageService($this->db);
        $messageService->deleteAll($user['id']);

        $this->flashAndRedirect('All messages deleted.', '/messages');
    }

    public function reports(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;
        $messageService = new MessageService($this->db);
        $reports = $messageService->getReports($user['id']);

        $this->render('message/inbox.html', [
            'page_title' => $f3->get('lang.reports') ?? 'Reports',
            'messages' => $reports,
            'is_reports' => true,
            'box' => 'reports',
            'page' => 1,
            'total_pages' => 1,
        ]);
    }

    public function deleteReport(\Base $f3, array $params): void
    {
        $reportId = (int) ($params['id'] ?? 0);

        if (!$this->requireCsrf('/reports')) return;

        $user = $this->requireAuth();
        if ($user === null) return;
        $messageService = new MessageService($this->db);
        $messageService->deleteReport($reportId, $user['id']);

        $this->flashAndRedirect('Report deleted.', '/reports');
    }

    public function deleteAllReports(\Base $f3): void
    {
        if (!$this->requireCsrf('/reports')) return;

        $user = $this->requireAuth();
        if ($user === null) return;
        $messageService = new MessageService($this->db);
        $messageService->deleteAllReports($user['id']);

        $this->flashAndRedirect('All reports deleted.', '/reports');
    }
}
