<?php

class CartService {

    public function addItem(int $customerId, int $productId, int $quantity = 1, array $optionIds = []): array {
        if ($customerId <= 0 || $productId <= 0) return $this->failure('Invalid request.');
        if ($quantity <= 0) return $this->failure('Quantity must be at least 1.');
        $settings = BusinessSettings::current();
        if ($quantity > $settings->max_item_quantity) return $this->failure('You can add up to ' . $settings->max_item_quantity . ' of the same menu item.');

        return $this->withCustomerLock($customerId, function (PDO $db) use ($customerId, $productId, $quantity, $optionIds): array {
            $settings = BusinessSettings::current();
            $productLock = $db->prepare(
                'SELECT id FROM products WHERE id = ? AND status = ? AND is_available = 1 FOR UPDATE'
            );
            $productLock->execute([$productId, Product::STATUS_ACTIVE]);
            if (!$productLock->fetchColumn()) return $this->failure('This item is not available.');

            $selectedOptions = (new ProductOptionService())->resolveSelection($productId, $optionIds);
            if (!$selectedOptions['success']) return $selectedOptions;
            $customizations = $selectedOptions['data'];
            $customizationJson = json_encode($customizations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $customizationHash = hash('sha256', json_encode(array_column($customizations, 'id')));

            $productQuantityQuery = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE customer_id = ? AND product_id = ?');
            $productQuantityQuery->execute([$customerId, $productId]);
            $productQuantity = (int) $productQuantityQuery->fetchColumn();
            if ($productQuantity + $quantity > $settings->max_item_quantity) {
                return $this->failure('You can add up to ' . $settings->max_item_quantity . ' of the same menu item across all customizations.');
            }
            $cartQuantityQuery = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE customer_id = ?');
            $cartQuantityQuery->execute([$customerId]);
            if ((int) $cartQuantityQuery->fetchColumn() + $quantity > $settings->max_cart_items) {
                return $this->failure('Your cart can contain up to ' . $settings->max_cart_items . ' items, including quantities.');
            }

            $existingQuery = $db->prepare('SELECT id FROM cart_items WHERE customer_id = ? AND product_id = ? AND customization_hash = ? FOR UPDATE');
            $existingQuery->execute([$customerId, $productId, $customizationHash]);
            $existingId = $existingQuery->fetchColumn();

            if ($existingId) {
                $stmt = $db->prepare('UPDATE cart_items SET quantity = quantity + ?, customization_json = ? WHERE id = ?');
                if (!$stmt->execute([$quantity, $customizationJson, (int) $existingId])) return $this->failure('Unable to add item to cart.');
                $item = CartItem::findById((int) $existingId);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO cart_items (customer_id, product_id, quantity, customization_json, customization_hash) VALUES (?, ?, ?, ?, ?)'
                );
                if (!$stmt->execute([$customerId, $productId, $quantity, $customizationJson, $customizationHash])) return $this->failure('Unable to add item to cart.');
                $item = CartItem::findById((int) $db->lastInsertId());
            }
            return $item ? $this->success($item) : $this->failure('Unable to load cart item.');
        });
    }

    public function updateQuantity(int $customerId, int $cartItemId, int $quantity): array {
        if ($cartItemId <= 0) return $this->failure('Cart item not found.');
        return $this->withCustomerLock($customerId, function (PDO $db) use ($customerId, $cartItemId, $quantity): array {
            $settings = BusinessSettings::current();
            $lock = $db->prepare('SELECT id, product_id FROM cart_items WHERE id = ? AND customer_id = ? FOR UPDATE');
            $lock->execute([$cartItemId, $customerId]);
            $lockedRow = $lock->fetch();
            if (!$lockedRow) return $this->failure('Cart item not found.');
            $item = CartItem::findById($cartItemId);
            if ($quantity <= 0) return $item->delete() ? $this->success(null) : $this->failure('Unable to remove item.');
            if ($quantity > $item->quantity && $quantity > $settings->max_item_quantity) {
                return $this->failure('You can add up to ' . $settings->max_item_quantity . ' of the same menu item.');
            }
            // Allow reductions even when an administrator has since lowered a
            // limit below the customer's current cart quantity.
            if ($quantity <= $item->quantity) {
                $item->quantity = $quantity;
                return $item->save() ? $this->success($item) : $this->failure('Unable to update cart.');
            }
            $otherItems = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE customer_id = ? AND product_id = ? AND id <> ?');
            $otherItems->execute([$customerId, (int) $lockedRow['product_id'], $cartItemId]);
            if ((int) $otherItems->fetchColumn() + $quantity > $settings->max_item_quantity) {
                return $this->failure('You can add up to ' . $settings->max_item_quantity . ' of the same menu item across all customizations.');
            }
            $otherCartItems = $db->prepare('SELECT COALESCE(SUM(quantity), 0) FROM cart_items WHERE customer_id = ? AND id <> ?');
            $otherCartItems->execute([$customerId, $cartItemId]);
            if ((int) $otherCartItems->fetchColumn() + $quantity > $settings->max_cart_items) {
                return $this->failure('Your cart can contain up to ' . $settings->max_cart_items . ' items, including quantities.');
            }
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
        $products = Product::findByIds(array_map(static fn(CartItem $item): int => $item->product_id, $items));
        $items = array_map(static function (CartItem $item) use ($products): array {
            $product = $products[$item->product_id] ?? null;
            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $product?->name ?? 'Unavailable menu item',
                'quantity' => $item->quantity,
                'unit_price' => $product ? $product->price + $item->getCustomizationTotal() : $item->unit_price,
                'line_total' => $item->getLineTotal(),
                'customizations' => $item->customizations,
            ];
        }, $items);
        return $this->success([
            'items' => $items,
            'total' => $this->getCartTotal($customerId),
            'item_count' => array_sum(array_map(static fn(array $item): int => $item['quantity'], $items)),
            'limits' => [
                'max_item_quantity' => BusinessSettings::current()->max_item_quantity,
                'max_cart_items' => BusinessSettings::current()->max_cart_items,
            ],
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
