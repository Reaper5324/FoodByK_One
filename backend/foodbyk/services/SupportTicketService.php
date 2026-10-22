<?php

class SupportTicketService {
    private const CATEGORIES = ['general', 'order', 'menu_item', 'cart', 'checkout', 'payment', 'delivery', 'account', 'other'];
    private const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    public function customerTickets(int $userId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id, subject, category, message, related_order_id, related_product_id, status, created_at, updated_at FROM support_tickets WHERE user_id = ? ORDER BY updated_at DESC, id DESC');
        $stmt->execute([$userId]);
        $tickets = $stmt->fetchAll();
        foreach ($tickets as &$ticket) $ticket['replies'] = $this->replies((int) $ticket['id']);
        return $this->success($tickets);
    }

    public function create(int $userId, mixed $subject, mixed $category, mixed $message, mixed $orderId = null, mixed $productId = null): array {
        $subject = is_string($subject) ? trim($subject) : '';
        $message = is_string($message) ? trim($message) : '';
        $category = is_string($category) ? trim($category) : '';
        if ($subject === '' || $this->textLength($subject) > 150) return $this->failure('Subject must be between 1 and 150 characters.');
        if (!in_array($category, self::CATEGORIES, true)) return $this->failure('Choose a valid support category.');
        if ($message === '' || $this->textLength($message) > 5000) return $this->failure('Message must be between 1 and 5,000 characters.');

        $orderId = ($orderId === null || $orderId === '') ? null : filter_var($orderId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $productId = ($productId === null || $productId === '') ? null : filter_var($productId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($orderId === false || $productId === false) return $this->failure('The support request has an invalid order or menu item reference.');

        $db = Database::getConnection();
        if ($orderId !== null) {
            $ownsOrder = $db->prepare('SELECT id FROM orders WHERE id = ? AND customer_id = ?');
            $ownsOrder->execute([(int) $orderId, $userId]);
            if (!$ownsOrder->fetch()) return $this->failure('That order could not be linked to your account.');
        }
        if ($productId !== null) {
            $productExists = $db->prepare('SELECT id FROM products WHERE id = ?');
            $productExists->execute([(int) $productId]);
            if (!$productExists->fetch()) return $this->failure('That menu item could not be found.');
        }
        $stmt = $db->prepare('INSERT INTO support_tickets (user_id, subject, category, message, related_order_id, related_product_id) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $subject, $category, $message, $orderId, $productId]);
        return $this->ticket((int) $db->lastInsertId(), $userId);
    }

    public function adminTickets(?string $status = null): array {
        if ($status !== null && $status !== 'all' && !in_array($status, self::STATUSES, true)) return $this->failure('Choose a valid ticket status.');
        $db = Database::getConnection();
        $where = ($status && $status !== 'all') ? 'WHERE t.status = ?' : '';
        $stmt = $db->prepare("SELECT t.id, t.user_id, u.name AS full_name, u.email, t.related_order_id, t.related_product_id, t.subject, t.category, t.message, t.status, t.created_at, t.updated_at FROM support_tickets t JOIN users u ON u.id = t.user_id {$where} ORDER BY FIELD(t.status, 'open', 'in_progress', 'resolved', 'closed'), t.updated_at DESC, t.id DESC");
        $stmt->execute(($status && $status !== 'all') ? [$status] : []);
        return $this->success($stmt->fetchAll());
    }

    public function adminTicket(int $ticketId): array {
        return $this->ticket($ticketId);
    }

    public function reply(int $ticketId, int $adminId, mixed $body): array {
        $body = is_string($body) ? trim($body) : '';
        if ($body === '' || $this->textLength($body) > 5000) return $this->failure('Reply must be between 1 and 5,000 characters.');
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $lock = $db->prepare('SELECT status FROM support_tickets WHERE id = ? FOR UPDATE');
            $lock->execute([$ticketId]);
            $ticket = $lock->fetch();
            if (!$ticket) { $db->rollBack(); return $this->failure('Support ticket not found.'); }
            $stmt = $db->prepare('INSERT INTO support_replies (ticket_id, admin_id, reply_message) VALUES (?, ?, ?)');
            $stmt->execute([$ticketId, $adminId, $body]);
            if ($ticket['status'] === 'open') {
                $update = $db->prepare("UPDATE support_tickets SET status = 'in_progress' WHERE id = ?");
                $update->execute([$ticketId]);
            } else {
                $touch = $db->prepare('UPDATE support_tickets SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
                $touch->execute([$ticketId]);
            }
            $db->commit();
            return $this->ticket($ticketId);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Support ticket reply failed: ' . $error->getMessage());
            return $this->failure('The reply could not be saved. Please try again.');
        }
    }

    public function setStatus(int $ticketId, int $adminId, mixed $status): array {
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) return $this->failure('Choose a valid ticket status.');
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE support_tickets SET status = ?, resolved_by = ?, resolved_at = ? WHERE id = ?');
        $resolved = in_array($status, ['resolved', 'closed'], true);
        $stmt->execute([$status, $resolved ? $adminId : null, $resolved ? date('Y-m-d H:i:s') : null, $ticketId]);
        if ($stmt->rowCount() === 0) {
            $exists = $db->prepare('SELECT id FROM support_tickets WHERE id = ?');
            $exists->execute([$ticketId]);
            if (!$exists->fetch()) return $this->failure('Support ticket not found.');
        }
        return $this->ticket($ticketId);
    }

    private function ticket(int $ticketId, ?int $ownerId = null): array {
        $db = Database::getConnection();
        $sql = 'SELECT t.id, t.user_id, u.name AS full_name, u.email, t.related_order_id, t.related_product_id, t.subject, t.category, t.message, t.status, t.created_at, t.updated_at FROM support_tickets t JOIN users u ON u.id = t.user_id WHERE t.id = ?';
        $params = [$ticketId];
        if ($ownerId !== null) { $sql .= ' AND t.user_id = ?'; $params[] = $ownerId; }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $ticket = $stmt->fetch();
        if (!$ticket) return $this->failure('Support ticket not found.');
        $ticket['replies'] = $this->replies($ticketId);
        return $this->success($ticket);
    }

    private function replies(int $ticketId): array {
        $stmt = Database::getConnection()->prepare('SELECT r.id, r.reply_message, r.created_at, u.name AS responder_name FROM support_replies r JOIN users u ON u.id = r.admin_id WHERE r.ticket_id = ? ORDER BY r.id ASC');
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    private function success(mixed $data): array { return ['success' => true, 'data' => $data, 'error' => null]; }
    private function failure(string $message): array { return ['success' => false, 'data' => null, 'error' => $message]; }

    private function textLength(string $value): int {
        if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
        $count = preg_match_all('/./us', $value);
        return $count === false ? strlen($value) : $count;
    }
}
