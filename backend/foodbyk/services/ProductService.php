<?php

class ProductService {
    private const MAX_NAME_LENGTH = 150;
    private const MAX_DESCRIPTION_LENGTH = 2000;
    private ?object $imageStorageClient = null;

    public function listAvailable(): array {
        return $this->success($this->withImageUrls(array_values(array_filter(
            Product::findAvailable(),
            fn(Product $product) => $this->isPubliclyAvailable($product)
        ))));
    }

    public function listForAdmin(): array {
        $products = array_values(array_filter(
            Product::findAll(),
            fn(Product $product) => $product->status !== Product::STATUS_REMOVED
        ));
        foreach ($products as $product) {
            $product->image_key = $product->image_url;
        }
        return $this->success($this->withImageUrls($products));
    }

    public function listByCategory(int $categoryId): array {
        if ($categoryId <= 0) {
            return $this->failure('Invalid category.');
        }

        return $this->success($this->withImageUrls(array_values(array_filter(
            Product::findByCategory($categoryId),
            fn(Product $product) => $this->isPubliclyAvailable($product)
        ))));
    }

    public function findAvailableById(int $productId): array {
        if ($productId <= 0) {
            return $this->failure('Invalid product.');
        }

        $product = Product::findById($productId);
        return $product !== null && $this->isPubliclyAvailable($product)
            ? $this->success($this->withImageUrl($product))
            : $this->failure('Product not found.');
    }

    public function search(string $query): array {
        $query = trim($query);
        if ($query === '' || $this->stringLength($query) > 100) {
            return $this->failure('Enter a search term between 1 and 100 characters.');
        }

        return $this->success($this->withImageUrls(array_values(array_filter(
            Product::search($query),
            fn(Product $product) => $this->isPubliclyAvailable($product)
        ))));
    }

    public function create(array $input): array {
        $validated = $this->validateProductInput($input);
        if (!$validated['success']) {
            return $validated;
        }

        $data = $validated['data'];
        if (Category::findById($data['category_id']) === null) {
            return $this->failure('Category not found.');
        }

        $product = new Product(...$data);
        return $product->save() ? $this->success($product) : $this->failure('Unable to create product.');
    }

    public function update(int $productId, array $input): array {
        $product = Product::findById($productId);
        if ($product === null || $product->status === Product::STATUS_REMOVED) {
            return $this->failure('Product not found.');
        }

        $validated = $this->validateProductInput($input, $product);
        if (!$validated['success']) {
            return $validated;
        }

        $data = $validated['data'];
        if ($data['category_id'] !== $product->category_id && Category::findById($data['category_id']) === null) {
            return $this->failure('Category not found.');
        }
        foreach ($data as $field => $value) {
            $product->$field = $value;
        }

        return $product->save() ? $this->success($product) : $this->failure('Unable to update product.');
    }

    public function setAvailability(int $productId, bool $available): array {
        $product = Product::findById($productId);
        if ($product === null || $product->status === Product::STATUS_REMOVED) {
            return $this->failure('Product not found.');
        }

        $product->is_available = $available;
        return $product->save() ? $this->success($product) : $this->failure('Unable to update product availability.');
    }

    public function remove(int $productId): array {
        $product = Product::findById($productId);
        if ($product === null) {
            return $this->failure('Product not found.');
        }

        $product->status = Product::STATUS_REMOVED;
        $product->is_available = false;
        return $product->save() ? $this->success($product) : $this->failure('Unable to remove product.');
    }

    public function validateProductInput(array $input, ?Product $existing = null): array {
        $categoryId = $input['category_id'] ?? $existing?->category_id;
        $name = trim((string) ($input['name'] ?? $existing?->name ?? ''));
        $description = trim((string) ($input['description'] ?? $existing?->description ?? ''));
        $price = $input['price'] ?? $existing?->price;
        $imageUrl = $input['image_url'] ?? $existing?->image_url;
        $isAvailable = $input['is_available'] ?? $existing?->is_available ?? true;
        $status = $input['status'] ?? $existing?->status ?? Product::STATUS_ACTIVE;

        if (filter_var($categoryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return $this->failure('Invalid category.');
        }
        if ($name === '' || $this->stringLength($name) > self::MAX_NAME_LENGTH) {
            return $this->failure('Product name must be between 1 and 150 characters.');
        }
        if ($this->stringLength($description) > self::MAX_DESCRIPTION_LENGTH) {
            return $this->failure('Product description cannot exceed 2000 characters.');
        }
        if (!is_numeric($price) || (float) $price < 0.0 || (float) $price > 100000.0) {
            return $this->failure('Invalid product price.');
        }
        if ($imageUrl !== null && $imageUrl !== '' && filter_var($imageUrl, FILTER_VALIDATE_URL) === false
            && !$this->isValidBucketKey((string) $imageUrl)) {
            return $this->failure('Invalid product image URL.');
        }
        if (!is_bool($isAvailable) && !in_array($isAvailable, [0, 1, '0', '1'], true)) {
            return $this->failure('Invalid product availability.');
        }
        if (!in_array($status, [Product::STATUS_ACTIVE, Product::STATUS_INACTIVE], true)) {
            return $this->failure('Invalid product status.');
        }

        return $this->success([
            'category_id' => (int) $categoryId,
            'name' => $name,
            'description' => $description,
            'price' => round((float) $price, 2),
            'is_available' => (bool) $isAvailable,
            'status' => $status,
            'image_url' => $imageUrl === '' ? null : $imageUrl,
        ]);
    }

    private function isPubliclyAvailable(Product $product): bool {
        return $product->status === Product::STATUS_ACTIVE && $product->is_available;
    }

    private function withImageUrls(array $products): array {
        foreach ($products as $product) {
            if ($product instanceof Product) {
                $this->withImageUrl($product);
            }
        }
        return $products;
    }

    private function withImageUrl(Product $product): Product {
        $key = trim((string) $product->image_url);
        if ($key === '' || filter_var($key, FILTER_VALIDATE_URL) !== false) {
            return $product;
        }

        // Accept only simple object keys; never allow a database value to
        // address objects outside the bucket's normal key namespace.
        if (!$this->isValidBucketKey($key)) {
            $product->image_url = null;
            return $product;
        }

        if (AWS_ENDPOINT_URL === '' || AWS_S3_BUCKET_NAME === ''
            || AWS_ACCESS_KEY_ID === '' || AWS_SECRET_ACCESS_KEY === '') {
            $product->image_url = null;
            return $product;
        }

        try {
            $this->imageStorageClient ??= new \Aws\S3\S3Client([
                    'version' => 'latest',
                    'region' => AWS_DEFAULT_REGION,
                    'endpoint' => AWS_ENDPOINT_URL,
                    'use_path_style_endpoint' => false,
                    'credentials' => [
                        'key' => AWS_ACCESS_KEY_ID,
                        'secret' => AWS_SECRET_ACCESS_KEY,
                    ],
                ]);
            $command = $this->imageStorageClient->getCommand('GetObject', [
                'Bucket' => AWS_S3_BUCKET_NAME,
                'Key' => $key,
            ]);
            $request = $this->imageStorageClient->createPresignedRequest($command, '+1 hour');
            $product->image_url = (string) $request->getUri();
        } catch (Throwable $error) {
            error_log('Unable to create a signed menu image URL.');
            $product->image_url = null;
        }

        return $product;
    }

    private function isValidBucketKey(string $key): bool {
        return !str_starts_with($key, '/') && !str_contains($key, '..')
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,254}$/', $key) === 1;
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
