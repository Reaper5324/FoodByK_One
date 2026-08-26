<?php

class ProductService{
    public function listByCategory(int $category_Id): array{
        $product = array_filter(
            Product::findBy('category_id', $category_Id),
            fn($p) => $p->is_available
            );
        return ['success' => true, 'data' => array_values($product)];
    }


    





}