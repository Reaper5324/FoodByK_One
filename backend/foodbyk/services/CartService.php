<?php

class CartService {

    public function addItem(int $customerId, int $productId, int $quantity = 1): array {
        if ($customerId <= 0 || $productId <= 0) return $this->failure('Invalid request.');
        if ($quantity <= 0) return $this->failure('Quantity must be at least 1.');

        return $this->withCustomerLock($customerId, function (PDO $db) use ($customerId, $productId, $quantity): array {
            $productLock = $db->prepare(
                'SELECT id FROM products WHERE id = ? AND status = ? AND is_available = 1 FOR UPDATE'
            );
            $productLock->execute([$productId, Product::STATUS_ACTIVE]);
            if (!$productLock->fetchColumn()) return $this->failure('This item is not available.');

            $stmt = $db->prepare(
                'INSERT INTO cart_items (customer_id, product_id, quantity) VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)'
            );
            if (!$stmt->execute([$customerId, $productId, $quantity])) return $this->failure('Unable to add item to cart.');
            $item = array_values(array_filter(
                CartItem::findBy('customer_id', $customerId),
                fn(CartItem $cartItem) => $cartItem->product_id === $productId
            ))[0] ?? null;
            return $item ? $this->success($item) : $this->failure('Unable to load cart item.');
        });
    }

    public function updateQuantity(int $customerId, int $cartItemId, int $quantity): array {
        if ($cartItemId <= 0) return $this->failure('Cart item not found.');
        return $this->withCustomerLock($customerId, function (PDO $db) use ($customerId, $cartItemId, $quantity): array {
            $lock = $db->prepare('SELECT id FROM cart_items WHERE id = ? AND customer_id = ? FOR UPDATE');
            $lock->execute([$cartItemId, $customerId]);
            if (!$lock->fetchColumn()) return $this->failure('Cart item not found.');
            $item = CartItem::findById($cartItemId);
            if ($quantity <= 0) return $item->delete() ? $this->success(null) : $this->failure('Unable to remove item.');
            $item->quantity = $quantity;
            return $item->save() ? $this->success($item) : $this->failure('Unable to update cart.');
        });
    }

    public function removeItem(int $customerId, int $cartItemId): array {
        if ($cartItemId <= 0) return $this->failure('Cart item not found.');
        return $this->withCustomerLock($customerId, function (PDO $db) use ($customerId, $cartItemId): array {
            $lock = $db->prepare('SELECT id FROM cart_items WHERE id = ? AND customer_id = ? FOR UPDATE');
            $lock->execute([$cartItemId, $customerId]);
            if (!$lock->fetchColumn()) return $this->failure('Cart item not found.');
            $item = CartItem::findById($cartItemId);
            return $item->delete() ? $this->success(null) : $this->failure('Unable to remove item.');
        });
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
        return $this->withCustomerLock($customerId, function (PDO $db) use ($customerId): array {
            $stmt = $db->prepare('DELETE FROM cart_items WHERE customer_id = ?');
            return $stmt->execute([$customerId]) ? $this->success(null) : $this->failure('Unable to clear cart.');
        });
    }

    /** Serialize cart writes against checkout, which locks this same user row. */
    private function withCustomerLock(int $customerId, callable $work): array {
        if ($customerId <= 0) return $this->failure('Invalid customer account.');
        $db = Database::getConnection();
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            $customer = $db->prepare('SELECT is_active FROM users WHERE id = ? FOR UPDATE');
            $customer->execute([$customerId]);
            if ((int) $customer->fetchColumn() !== 1) {
                if ($ownsTransaction) $db->rollBack();
                return $this->failure('Customer account is not active.');
            }
            $result = $work($db);
            if (!$result['success']) {
                if ($ownsTransaction) $db->rollBack();
                return $result;
            }
            if ($ownsTransaction) $db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            error_log('Cart update failed: ' . $error->getMessage());
            return $this->failure('Unable to update the cart right now. Please try again.');
        }
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
