<?php

class ProductOptionService {

    public function listForProducts(array $productIds, bool $includeUnavailable = false): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn(int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = "SELECT id, product_id, name, price_delta, is_available, display_order
                FROM product_options WHERE product_id IN ({$placeholders})";
        if (!$includeUnavailable) $sql .= ' AND is_available = 1';
        $sql .= ' ORDER BY product_id, display_order, id';
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($ids);
        $result = array_fill_keys($ids, []);
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['product_id']][] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'price_delta' => (float) $row['price_delta'],
                'is_available' => (bool) $row['is_available'],
                'display_order' => (int) $row['display_order'],
            ];
        }
        return $result;
    }

    public function validateList(mixed $options): array {
        if (!is_array($options) || count($options) > 30) {
            return $this->failure('Add up to 30 customizations to a menu item.');
        }
        $validated = [];
        $names = [];
        foreach ($options as $option) {
            if (!is_array($option) || !is_string($option['name'] ?? null)) {
                return $this->failure('Each customization needs a text name and price.');
            }
            $name = trim($option['name']);
            $price = $option['price_delta'] ?? null;
            $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
            if ($name === '' || $nameLength > 80 || !is_numeric($price) || (float) $price < 0 || (float) $price > 100000) {
                return $this->failure('Customization names must be 1–80 characters and prices must be non-negative.');
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
            if (isset($names[$key])) return $this->failure('Customization names must be unique for each menu item.');
            $names[$key] = true;
            $validated[] = ['name' => $name, 'price_delta' => round((float) $price, 2)];
        }
        return $this->success($validated);
    }

    public function replaceForProduct(int $productId, array $options): array {
        $validated = $this->validateList($options);
        if (!$validated['success']) return $validated;
        $db = Database::getConnection();
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction) $db->beginTransaction();
            $db->prepare('DELETE FROM product_options WHERE product_id = ?')->execute([$productId]);
            $insert = $db->prepare('INSERT INTO product_options (product_id, name, price_delta, display_order) VALUES (?, ?, ?, ?)');
            foreach ($validated['data'] as $index => $option) {
                $insert->execute([$productId, $option['name'], $option['price_delta'], $index]);
            }
            if ($ownsTransaction) $db->commit();
            return $this->success($this->listForProducts([$productId], true)[$productId] ?? []);
        } catch (Throwable $error) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            error_log('Unable to save product customizations: ' . $error->getMessage());
            return $this->failure('Unable to save customizations for this menu item.');
        }
    }

    public function resolveSelection(int $productId, array $optionIds): array {
        if (count($optionIds) > 30) return $this->failure('Invalid menu customizations.');
        $ids = [];
        foreach ($optionIds as $optionId) {
            $validatedId = filter_var($optionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validatedId === false) return $this->failure('Invalid menu customizations.');
            $ids[] = (int) $validatedId;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) > 30) {
            return $this->failure('Invalid menu customizations.');
        }
        sort($ids, SORT_NUMERIC);
        if ($ids === []) return $this->success([]);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = Database::getConnection()->prepare(
            "SELECT id, name, price_delta FROM product_options
             WHERE product_id = ? AND is_available = 1 AND id IN ({$placeholders}) ORDER BY id"
        );
        $stmt->execute([$productId, ...$ids]);
        $rows = array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'price_delta' => (float) $row['price_delta'],
        ], $stmt->fetchAll());
        if (count($rows) !== count($ids)) return $this->failure('One or more selected customizations are no longer available. Please review your cart.');
        return $this->success($rows);
    }

    private function success(mixed $data): array { return ['success' => true, 'data' => $data, 'error' => null]; }
    private function failure(string $error): array { return ['success' => false, 'data' => null, 'error' => $error]; }
}
