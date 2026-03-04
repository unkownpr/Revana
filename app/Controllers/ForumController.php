<?php declare(strict_types=1);

namespace Devana\Controllers;

use Devana\Helpers\InputSanitizer;
use Devana\Helpers\GravatarHelper;
use Devana\Services\ForumService;

final class ForumController extends Controller
{
    public function list(\Base $f3): void
    {
        $user = $this->requireAuth();
        if ($user === null) return;
        $this->loadLanguage();

        if ($user['alliance'] < 1) {
            $this->flashAndRedirect($f3->get('lang.forumAllianceRequired') ?? 'You need an alliance to access forums.', '/towns');
            return;
        }

        $forumService = new ForumService($this->db);
        $forums = $forumService->getForumsByAlliance($user['alliance']);

        $this->render('forum/list.html', [
            'page_title' => $f3->get('lang.forums') ?? 'Forums',
            'forums' => $forums,
        ]);
    }

    public function manage(\Base $f3): void
    {
        if (!$this->validateCsrfToken()) {
            $this->flashAndRedirect('Invalid request.', '/forum');
            return;
        }

        $user = $this->requireAuth();
        if ($user === null) return;
        $action = $this->post('action', '');

        $forumService = new ForumService($this->db);

        if ($action === 'create') {
            $name = InputSanitizer::clean($this->post('name', ''));
            $description = InputSanitizer::clean($this->post('description', ''));

            if (!empty($name)) {
                $forumService->createForum($user['alliance'], $name, $description);
            }
        } elseif ($action === 'delete') {
            $forumId = InputSanitizer::cleanInt($this->post('forum_id', 0));
            $forumService->deleteForum($forumId);
        }

        $this->redirect('/forum');
    }

    public function threads(\Base $f3, array $params): void
    {
        $forumId = (int) ($params['id'] ?? 0);
        $forumService = new ForumService($this->db);
        $threads = $forumService->getThreads($forumId);

        $forum = $this->db->exec('SELECT * FROM forums WHERE id = ?', [$forumId]);

        $this->render('forum/threads.html', [
            'page_title' => $forum[0]['name'] ?? 'Forum',
            'forum' => $forum[0] ?? null,
            'threads' => $threads,
            'forum_id' => $forumId,
        ]);
    }

    public function manageThread(\Base $f3, array $params): void
    {
        $forumId = (int) ($params['id'] ?? 0);

        if (!$this->validateCsrfToken()) {
            $this->flashAndRedirect('Invalid request.', '/forum/' . $forumId);
            return;
        }

        $user = $this->requireAuth();
        if ($user === null) return;
        $action = $this->post('action', '');
        $forumService = new ForumService($this->db);

        if ($action === 'create') {
            $name = InputSanitizer::clean($this->post('name', ''));
            $description = InputSanitizer::clean($this->post('description', ''));
            $content = InputSanitizer::clean($this->post('content', ''));

            if (!empty($name) && !empty($content)) {
                $forumService->createThread($forumId, $user['id'], $name, $description, $content);
            }
        } elseif ($action === 'delete') {
            $threadId = InputSanitizer::cleanInt($this->post('thread_id', 0));
            $forumService->deleteThread($threadId);
        }

        $this->redirect('/forum/' . $forumId);
    }

    public function posts(\Base $f3, array $params): void
    {
        $forumId = (int) ($params['id'] ?? 0);
        $threadId = (int) ($params['tid'] ?? 0);

        $forumService = new ForumService($this->db);
        $posts = $forumService->getPosts($threadId);
        $thread = $this->db->exec('SELECT * FROM threads WHERE id = ?', [$threadId]);

        foreach ($posts as &$post) {
            $post['author_avatar'] = GravatarHelper::url($post['author_avatar_seed'] ?? $post['authorName'] ?? '', 40);
        }
        unset($post);

        $this->render('forum/posts.html', [
            'page_title' => $thread[0]['name'] ?? 'Thread',
            'thread' => $thread[0] ?? null,
            'posts' => $posts,
            'forum_id' => $forumId,
            'thread_id' => $threadId,
        ]);
    }

    public function createPost(\Base $f3, array $params): void
    {
        $forumId = (int) ($params['id'] ?? 0);
        $threadId = (int) ($params['tid'] ?? 0);

        if (!$this->validateCsrfToken()) {
            $this->flashAndRedirect('Invalid request.', '/forum/' . $forumId . '/thread/' . $threadId);
            return;
        }

        $user = $this->requireAuth();
        if ($user === null) return;
        $description = InputSanitizer::clean($this->post('description', ''));
        $content = InputSanitizer::clean($this->post('content', ''));

        if (!empty($content)) {
            $forumService = new ForumService($this->db);
            $forumService->createPost($threadId, $user['id'], $description, $content);
        }

        $this->redirect('/forum/' . $forumId . '/thread/' . $threadId);
    }
}
