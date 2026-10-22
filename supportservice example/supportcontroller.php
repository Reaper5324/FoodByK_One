<?php

class SupportController extends Controller {

    private SupportService $support;

    public function __construct() {
        $this->support = new SupportService();
    }

    /**
     * Submit a support ticket
     */
    public function submit(): void {
        $user = AuthMiddleware::handle();

        if (!$user || !$user->id) {
            $this->json(['success' => false, 'error' => 'User authentication failed'], 401);
            return;
        }

        $body = $this->body();
        $result = $this->support->submitTicket(
            userId: (int) $user->id,
            subject: $body['subject'] ?? '',
            category: $body['category'] ?? '',
            message: $body['message'] ?? ''
        );

        $this->json($result, $result['success'] ? 201 : 422);
    }

    /**
     * Get support tickets submitted by current user
     */
    public function myTickets(): void {
        $user = AuthMiddleware::handle();

        $result = $this->support->getUserTickets($user->id);
        $this->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * Admin: Get all support tickets
     */
    public function all(): void {
        $user = AuthMiddleware::handle();
        RoleMiddleware::requireAdmin($user);

        $result = $this->support->getAllTickets();
        $this->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * Admin: Get support tickets by status
     */
    public function byStatus(string $status): void {
        $user = AuthMiddleware::handle();
        RoleMiddleware::requireAdmin($user);

        $result = $this->support->getTicketsByStatus($status);
        $this->json($result, $result['success'] ? 200 : 404);
    }

    /**
     * Admin: Resolve a support ticket
     */
    public function resolve(string $id): void {
        $user = AuthMiddleware::handle();
        RoleMiddleware::requireAdmin($user);

        $result = $this->support->resolveTicket($user->id, (int) $id);
        $this->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Admin: Add a reply to a support ticket
     */
    public function reply(string $id): void {
        $user = AuthMiddleware::handle();
        RoleMiddleware::requireAdmin($user);

        $body = $this->body();
        $result = $this->support->addReply(
            adminId: $user->id,
            ticketId: (int) $id,
            replyText: $body['reply'] ?? ''
        );

        $this->json($result, $result['success'] ? 201 : 422);
    }
}
