<?php

class Category extends Model {

protected static string $table = 'categories';

public function __construct(
    public string  $name          = '',
    public ?string $description   = null,
    public int     $display_order = 0
) {}

public function getProducts(): array {
    return Product::findBy('category_id', $this->id);
}

protected function toArray(): array {
    return ['name' => $this->name, 'description' => $this->description, 'display_order' => $this->display_order];
}

protected static function fromRow(array $row): static {
    $c = new static();
    $c->id            = (int) $row['id'];
    $c->name          = $row['name'];
    $c->description   = $row['description'] ?? null;
    $c->display_order = (int) ($row['display_order'] ?? 0);
    return $c;
}

}