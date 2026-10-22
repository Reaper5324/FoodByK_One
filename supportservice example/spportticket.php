<?php

class SupportTicket extends Model {

    protected static string $table = 'support_tickets';

    const STATUS_OPEN        = 'open';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_RESOLVED    = 'resolved';
    const STATUS_CLOSED      = 'closed';

    public function __construct(
        public int     $user_id        = 0,
        public string  $subject        = '',
        public string  $category       = '',
        public string  $message        = '',
        public string  $status         = self::STATUS_OPEN,
        public ?int    $resolved_by    = null,
        public ?string $resolved_at    = null,
        public ?string $created_at     = null,
        public ?string $updated_at     = null
    ) {}

    /** Fetch the user who submitted this ticket. */
    public function getUser(): ?User {
        return User::findById($this->user_id);
    }

    /** Fetch the admin who resolved this ticket. */
    public function getResolvedBy(): ?User {
        return $this->resolved_by ? User::findById($this->resolved_by) : null;
    }

    protected function toArray(): array {
        return [
            'user_id'     => $this->user_id,
            'subject'     => $this->subject,
            'category'    => $this->category,
            'message'     => $this->message,
            'status'      => $this->status,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at,
        ];
    }

    protected static function fromRow(array $row): static {
        $t               = new static();
        $t->id           = (int)    $row['id'];
        $t->user_id      = (int)    $row['user_id'];
        $t->subject      =          $row['subject'];
        $t->category     =          $row['category'];
        $t->message      =          $row['message'];
        $t->status       =          $row['status'];
        $t->resolved_by  =          isset($row['resolved_by']) ? (int) $row['resolved_by'] : null;
        $t->resolved_at  =          $row['resolved_at'] ?? null;
        $t->created_at   =          $row['created_at'] ?? null;
        $t->updated_at   =          $row['updated_at'] ?? null;
        return $t;
    }
}
