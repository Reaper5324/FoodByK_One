<?php

class Admin extends User {

    public function addProduct(int $categoryId, string $name, string $description, float $price): Product {
        $product = new Product(category_id: $categoryId, name: $name, description: $description, price: $price);
        $product->save();
        return $product;
    }

    public static function findAdminById(int $id): ?static {
        if ($id <= 0) {
            return null;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT u.*, r.role_name FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND r.role_name = ? LIMIT 1'
        );
        $stmt->execute([$id, Role::ADMIN]);
        $row = $stmt->fetch();

        return $row ? static::fromRow($row) : null;
    }
}