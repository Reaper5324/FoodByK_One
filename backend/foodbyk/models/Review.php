<?php
class Review extends Model {

    protected static string $table = 'reviews';

    public function __construct(
        public int     $reviewer_id = 0,
        public int     $product_id  = 0,
        public int     $rating      = 5,    // 1–5
        public string  $comment     = '',
        public ?string $created_at  = null
    ) {}

    /** Fetch the User who wrote this review. */
    public function getReviewer(): ?User {
        return User::findById($this->reviewer_id);
    }

    /** Fetch the Product this review is for. */
    public function getProduct(): ?Product {
        return Product::findById($this->product_id);
    }

    protected function toArray(): array {
        return [
            'reviewer_id' => $this->reviewer_id,
            'product_id'  => $this->product_id,
            'rating'      => $this->rating,
            'comment'     => $this->comment,
        ];
    }

    protected static function fromRow(array $row): static {
        $r              = new static();
        $r->id          = (int) $row['id'];
        $r->reviewer_id = (int) $row['reviewer_id'];
        $r->product_id  = (int) $row['product_id'];
        $r->rating      = (int) $row['rating'];
        $r->comment     =       $row['comment'];
        $r->created_at  =       $row['created_at'] ?? null;
        return $r;
    }
}
?>