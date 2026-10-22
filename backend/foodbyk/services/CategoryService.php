<?php

/** Reads and updates menu categories. */
class CategoryService {

    private const MAX_NAME_LENGTH = 100;
    private const MAX_DESCRIPTION_LENGTH = 500;

    /** Lists active categories in menu order. */
    public function listActive(): array {
        $categories = Category::findAll();
        usort($categories, fn(Category $a, Category $b) =>
            ($a->display_order ?? 999) <=> ($b->display_order ?? 999)
            ?: $a->name <=> $b->name
        );
        return $this->success(array_values($categories));
    }

    /** Lists every category for the admin screen. */
    public function listAll(): array {
        $categories = Category::findAll();
        usort($categories, fn(Category $a, Category $b) =>
            ($a->display_order ?? 999) <=> ($b->display_order ?? 999)
            ?: $a->name <=> $b->name
        );
        return $this->success(array_values($categories));
    }

    /** Loads a category and counts its available products. */
    public function getById(int $categoryId): array {
        if ($categoryId <= 0) {
            return $this->failure('Invalid category ID.');
        }

        $category = Category::findById($categoryId);
        if (!$category) {
            return $this->failure('Category not found.');
        }

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

    /** Creates a category after checking the submitted fields. */
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

        return $this->saveCategory($category, 'Unable to create category.');
    }

    /** Updates a category name, description, or menu order. */
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

        return $this->saveCategory($category, 'Unable to update category.');
    }

    /** Deletes an empty category. */
    public function delete(int $categoryId): array {
        $category = Category::findById($categoryId);
        if (!$category) {
            return $this->failure('Category not found.');
        }

        if ($category->getProducts() !== []) {
            return $this->failure("Move or remove this category's products before deleting it.");
        }

        return $category->delete()
            ? $this->success(null)
            : $this->failure('Unable to delete category.');
    }

    /** Checks category fields before they are saved. */
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

        $sameName = Category::findOneBy('name', $name);
        if ($sameName && $sameName->id !== $existing?->id) {
            return $this->failure('A category with this name already exists. Choose a different name or edit the existing category.');
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

    public function getProductsInCategory(int $categoryId, ?string $search = null): array {
        $category = Category::findById($categoryId);
        if (!$category) {
            return $this->failure('Category not found.');
        }
        return $this->success(Product::findByCategory($categoryId, $search));
    }

    private function stringLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function saveCategory(Category $category, string $failureMessage): array {
        try {
            return $category->save()
                ? $this->success($category)
                : $this->failure($failureMessage);
        } catch (PDOException $error) {
            // The unique index is still the final guard if two requests race.
            if ($error->getCode() === '23000' && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                return $this->failure('A category with this name already exists. Choose a different name or edit the existing category.');
            }
            throw $error;
        }
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
