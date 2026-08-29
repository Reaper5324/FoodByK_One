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
        // Checks both is_available AND status - a product marked
        // 'removed'/'inactive' shouldn't be addable even if is_available
        // was left true by mistake. Matches ProductService's own gate.
        if ($product === null || $product->status !== Product::STATUS_ACTIVE || !$product->is_available) {
            return $this->failure('This item is not available.');
        }

        $existing = array_values(array_filter(
            CartItem::findBy('customer_id', $customerId),
            fn(CartItem $item) => $item->product_id === $productId
        ));

        if ($existing) {
            $item = $existing[0];
            return $item->increaseQuantity($quantity)
                ? $this->success($item)
                : $this->failure('Unable to update cart.');
        }

        $item = new CartItem(customer_id: $customerId, product_id: $productId, quantity: $quantity);
        return $item->save() ? $this->success($item) : $this->failure('Unable to add item to cart.');
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
            $item->delete();
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