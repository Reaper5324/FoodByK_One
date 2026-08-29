<?php

/**
 * CategoryService - Manage product categories.
 * 
 * Handles:
 * - Listing categories (public and admin)
 * - Retrieving category details
 * - Creating, updating, and removing categories (admin-only via controller)
 * - Validating category data
 */
class CategoryService {

    private const MAX_NAME_LENGTH = 100;
    private const MAX_DESCRIPTION_LENGTH = 500;

    /**
     * List all active categories (public view).
     * Ordered by display priority, then name.
     * 
     * @return array ['success' => bool, 'data' => Category[], 'error' => ?string]
     */
    public function listActive(): array {
        $categories = Category::findAll();
        usort($categories, fn(Category $a, Category $b) =>
            ($a->display_order ?? 999) <=> ($b->display_order ?? 999)
            ?: $a->name <=> $b->name
        );
        return $this->success(array_values($categories));
    }

    /**
     * List all categories including inactive (admin view).
     * 
     * @return array ['success' => bool, 'data' => Category[], 'error' => ?string]
     */
    public function listAll(): array {
        $categories = Category::findAll();
        usort($categories, fn(Category $a, Category $b) =>
            ($a->display_order ?? 999) <=> ($b->display_order ?? 999)
            ?: $a->name <=> $b->name
        );
        return $this->success(array_values($categories));
    }

    /**
     * Get a single category by ID with product count.
     * 
     * @param int $categoryId
     * @return array ['success' => bool, 'data' => [...], 'error' => ?string]
     */
    public function getById(int $categoryId): array {
        if ($categoryId <= 0) {
            return $this->failure('Invalid category ID.');
        }

        $category = Category::findById($categoryId);
        if (!$category) {
            return $this->failure('Category not found.');
        }

        // Count active products in this category
        $products = Product::findByCategory($categoryId);
        $activeCount = count(array_filter(
            $products,
            fn(Product $p) => $p->status === Product::STATUS_ACTIVE && $p->is_available
        ));

        return $this->success([
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'display_order' => $category->display_order,
            'active_product_count' => $activeCount,
        ]);
    }

    /**
     * Create a new category (admin-only).
     * 
     * @param array $input ['name', 'description', 'display_order']
     * @return array ['success' => bool, 'data' => Category, 'error' => ?string]
     */
    public function create(array $input): array {
        $validated = $this->validateInput($input);
        if (!$validated['success']) {
            return $validated;
        }

        $data = $validated['data'];
        $category = new Category(
            name: $data['name'],
            description: $data['description'],
            display_order: $data['display_order']
        );

        return $category->save()
            ? $this->success($category)
            : $this->failure('Unable to create category.');
    }

    /**
     * Update an existing category (admin-only).
     * 
     * @param int $categoryId
     * @param array $input
     * @return array ['success' => bool, 'data' => Category, 'error' => ?string]
     */
    public function update(int $categoryId, array $input): array {
        $category = Category::findById($categoryId);
        if (!$category) {
            return $this->failure('Category not found.');
        }

        $validated = $this->validateInput($input, $category);
        if (!$validated['success']) {
            return $validated;
        }

        $data = $validated['data'];
        $category->name = $data['name'];
        $category->description = $data['description'];
        $category->display_order = $data['display_order'];

        return $category->save()
            ? $this->success($category)
            : $this->failure('Unable to update category.');
    }

    /**
     * Delete a category (hard delete - admin-only, use with caution).
     * Products in this category are NOT deleted, but will be orphaned.
     * 
     * @param int $categoryId
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function delete(int $categoryId): array {
        $category = Category::findById($categoryId);
        if (!$category) {
            return $this->failure('Category not found.');
        }

        return $category->delete()
            ? $this->success(null)
            : $this->failure('Unable to delete category.');
    }

    /**
     * Validate category input data.
     * 
     * @param array $input
     * @param ?Category $existing
     * @return array ['success' => bool, 'data' => validated_input, 'error' => ?string]
     */
    public function validateInput(array $input, ?Category $existing = null): array {
        $name = trim((string) ($input['name'] ?? $existing?->name ?? ''));
        $description = trim((string) ($input['description'] ?? $existing?->description ?? ''));
        $displayOrder = $input['display_order'] ?? $existing?->display_order ?? 999;

        if ($name === '' || $this->stringLength($name) > self::MAX_NAME_LENGTH) {
            return $this->failure('Category name must be between 1 and 100 characters.');
        }

        if ($this->stringLength($description) > self::MAX_DESCRIPTION_LENGTH) {
            return $this->failure('Category description cannot exceed 500 characters.');
        }

        if (!is_int($displayOrder) || $displayOrder < 0 || $displayOrder > 9999) {
            return $this->failure('Display order must be between 0 and 9999.');
        }

        return $this->success([
            'name' => $name,
            'description' => $description,
            'display_order' => (int) $displayOrder,
        ]);
    }

    private function stringLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
