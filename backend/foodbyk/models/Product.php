<?php

class Product extends Model {

protected static string $table = 'products';

const STATUS_ACTIVE   = 'active';
    const STATUS_INACTIVE = 'inactive';
    const STATUS_REMOVED  = 'removed';  // Set by Admin and cannot be reversed.

public function __construct(
    public int     $category_id  = 0,
    public string  $name         = '',
    public string  $description  = '',
    public float   $price        = 0.0,
    public bool    $is_available = true,
    public string  $status       = self::STATUS_ACTIVE,
    public ?string $image_url    = null,
    public ?string $created_at   = null,
    public ?string $updated_at   = null
) {}

public static function findAvailable(): array {
    $db = Database::getConnection();
    $stmt = $db->query("SELECT * FROM products WHERE is_available = 1 ORDER BY name ASC");
    return array_map(fn($row) => static::fromRow($row), $stmt->fetchAll());
}

public function getCategory(): ?Category {
    return Category::findById($this->category_id);
}

public static function search(string $keyword): array {
        $db   = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT * FROM products
             WHERE status = 'active'
               AND (name LIKE ? OR description LIKE ?)
             ORDER BY created_at DESC"
        );
        $term = '%' . $keyword . '%';
        $stmt->execute([$term, $term]);
        return array_map(fn($row) => static::fromRow($row), $stmt->fetchAll());
    }

        // Soft-delete: preserves the row so historical orders referencing this
    // product still resolve. Matches STATUS_REMOVED's own "cannot be
    // reversed" comment - a hard DELETE would break past order history.
    public function markRemoved(): bool {
        $this->status = self::STATUS_REMOVED;
        $this->is_available = false;
        return $this->save();
    }

    // Extended to accept an optional search term so CategoryService can
    // do "products in category X matching keyword Y" as one query.
    public static function findByCategory(int $categoryId, ?string $search = null): array {
        $db = Database::getConnection();
        $search = trim((string) $search);

        if ($search !== '') {
            $stmt = $db->prepare(
                "SELECT * FROM products
                 WHERE category_id = ? AND status = 'active'
                   AND (name LIKE ? OR description LIKE ?)
                 ORDER BY created_at DESC"
            );
            $term = '%' . $search . '%';
            $stmt->execute([$categoryId, $term, $term]);
        } else {
            $stmt = $db->prepare(
                "SELECT * FROM products WHERE category_id = ? AND status = 'active' ORDER BY created_at DESC"
            );
            $stmt->execute([$categoryId]);
        }

        return array_map(fn($row) => static::fromRow($row), $stmt->fetchAll());
    }

protected function toArray(): array {
    return [
        'category_id'  => $this->category_id,
        'name'         => $this->name,
        'description'  => $this->description,
        'price'        => $this->price,
        'is_available' => (int) $this->is_available,
        'image_url'    => $this->image_url,
        'status'       => $this->status,
    ];
}

protected static function fromRow(array $row): static {
    $p = new static();
    $p->id           = (int)   $row['id'];
    $p->category_id  = (int)   $row['category_id'];
    $p->name         =         $row['name'];
    $p->description  =         $row['description'];
    $p->price        = (float) $row['price'];
    $p->is_available = (bool)  $row['is_available'];
    $p->status      =          $row['status'];
    $p->image_url    =         $row['image_url'] ?? null;
    $p->created_at   =         $row['created_at'] ?? null;
    $p->updated_at   =         $row['updated_at'] ?? null;
    return $p;
}

}
