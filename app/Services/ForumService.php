<?php

declare(strict_types=1);

namespace Devana\Services;

final class ForumService
{
    private \DB\SQL $db;

    public function __construct(\DB\SQL $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getForumsByAlliance(int $allianceId): array
    {
        return $this->db->exec(
            'SELECT * FROM forums WHERE alliance = ? ORDER BY id',
            [$allianceId]
        );
    }

    public function createForum(int $allianceId, string $name, string $description): void
    {
        $this->db->exec(
            'INSERT INTO forums (alliance, parent, name, description) VALUES (?, 0, ?, ?)',
            [$allianceId, $name, $description]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getThreads(int $forumId): array
    {
        return $this->db->exec(
            'SELECT t.*, u.name AS authorName FROM threads t LEFT JOIN users u ON t.author = u.id WHERE t.forum = ? ORDER BY t.date DESC',
            [$forumId]
        );
    }

    public function createThread(
        int $forumId,
        int $authorId,
        string $name,
        string $description,
        string $content
    ): void {
        $this->db->exec(
            'INSERT INTO threads (forum, author, date, name, description, content) VALUES (?, ?, NOW(), ?, ?, ?)',
            [$forumId, $authorId, $name, $description, $content]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPosts(int $threadId): array
    {
        return $this->db->exec(
            'SELECT p.*, u.name AS authorName, u.email AS author_email, u.avatar_seed AS author_avatar_seed FROM posts p LEFT JOIN users u ON p.author = u.id WHERE p.thread = ? ORDER BY p.date ASC',
            [$threadId]
        );
    }

    public function createPost(
        int $threadId,
        int $authorId,
        string $description,
        string $content
    ): void {
        $this->db->exec(
            'INSERT INTO posts (thread, author, date, description, content) VALUES (?, ?, NOW(), ?, ?)',
            [$threadId, $authorId, $description, $content]
        );
    }

    public function deleteForum(int $forumId): void
    {
        $this->db->exec(
            'DELETE FROM posts WHERE thread IN (SELECT id FROM threads WHERE forum = ?)',
            [$forumId]
        );
        $this->db->exec('DELETE FROM threads WHERE forum = ?', [$forumId]);
        $this->db->exec('DELETE FROM forums WHERE id = ?', [$forumId]);
    }

    public function deleteThread(int $threadId): void
    {
        $this->db->exec('DELETE FROM posts WHERE thread = ?', [$threadId]);
        $this->db->exec('DELETE FROM threads WHERE id = ?', [$threadId]);
    }


}
