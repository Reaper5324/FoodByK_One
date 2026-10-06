<?php

class CartService {

    public function addItem(int $customerId, int $productId, int $quantity = 1): array {
        if ($customerId <= 0 || $productId <= 0) {
            return $this->failure('Invalid request.');
        }
        if ($quantity <= 0) {
            return $this->failure('Quantity must be at least 1.');
        }

        $product = Product::findById($productId);
        // Require both flags so inactive or removed products cannot be added.
        if ($product === null || $product->status !== Product::STATUS_ACTIVE || !$product->is_available) {
            return $this->failure('This item is not available.');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO cart_items (customer_id, product_id, quantity) VALUES (?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
        );
        if (!$stmt->execute([$customerId, $productId, $quantity])) {
            return $this->failure('Unable to add item to cart.');
        }
        $item = array_values(array_filter(
            CartItem::findBy('customer_id', $customerId),
            fn(CartItem $cartItem) => $cartItem->product_id === $productId
        ))[0] ?? null;
        return $item ? $this->success($item) : $this->failure('Unable to load cart item.');
    }

    public function updateQuantity(int $customerId, int $cartItemId, int $quantity): array {
        $item = CartItem::findById($cartItemId);
        if ($item === null || $item->customer_id !== $customerId) {
            return $this->failure('Cart item not found.');
        }
        if ($quantity <= 0) {
            return $item->delete() ? $this->success(null) : $this->failure('Unable to remove item.');
        }

        $item->quantity = $quantity;
        return $item->save() ? $this->success($item) : $this->failure('Unable to update cart.');
    }

    public function removeItem(int $customerId, int $cartItemId): array {
        $item = CartItem::findById($cartItemId);
        if ($item === null || $item->customer_id !== $customerId) {
            return $this->failure('Cart item not found.');
        }
        return $item->delete() ? $this->success(null) : $this->failure('Unable to remove item.');
    }

    public function view(int $customerId): array {
        $items = CartItem::findBy('customer_id', $customerId);
        return $this->success([
            'items' => $items,
            'total' => $this->getCartTotal($customerId),
        ]);
    }

    public function getCartTotal(int $customerId): float {
        $items = CartItem::findBy('customer_id', $customerId);
        return round(array_sum(array_map(fn(CartItem $i) => $i->getLineTotal(), $items)), 2);
    }

    public function clear(int $customerId): array {
        foreach (CartItem::findBy('customer_id', $customerId) as $item) {
            if (!$item->delete()) return $this->failure('Unable to clear cart.');
        }
        return $this->success(null);
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
