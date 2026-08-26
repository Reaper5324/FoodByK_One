<?php

class Admin extends User {

    public function addProduct(int $categoryId, string $name, string $description, float $price): Product {
        $product = new Product(category_id: $categoryId, name: $name, description: $description, price: $price);
        $product->save();
        return $product;
    }

    public function removeProduct(int $productId): bool {
        $product = Product::findById($productId);
        return $product?->delete() ?? false;
    }

    public function addPromotion(string $code, string $discountType, float $discountValue, string $start, string $end): Promotion {
        $promo = new Promotion(code: $code, discount_type: $discountType, discount_value: $discountValue, start_date: $start, end_date: $end);
        $promo->save();
        return $promo;
    }

    public function updateBusinessSettings(array $changes): BusinessSettings {
        $settings = BusinessSettings::current();
        foreach ($changes as $key => $value) {
            if (property_exists($settings, $key)) { $settings->$key = $value; }
        }
        $settings->save();
        return $settings;
    }

    public static function findAdminById(int $id): ?static {
        $user = User::findById($id);
        if ($user && $user->isAdmin()) {
            return static::fromRow((array) $user);
        }
        return null;
    }
}