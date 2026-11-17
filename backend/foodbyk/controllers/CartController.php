<?php

class CartController extends Controller {

// Add a product to the signed-in customer's cart.
    public function add(Request $request): Response {
        $customer = $request->user();
        $productId = InputValidator::positiveInteger($request->input('product_id'));
        $quantity = array_key_exists('quantity', $request->body)
            ? InputValidator::positiveInteger($request->body['quantity'])
            : 1;
        $optionIds = $request->input('option_ids', []);
        if ($productId === null) return Response::error('Invalid product.', 422);
        if ($quantity === null) return Response::error('Quantity must be a whole number of at least 1.', 422);
        if (!is_array($optionIds)) return Response::error('Selected customizations are invalid.', 422);
        $result = (new CartService())->addItem(
            $customer->id,
            $productId,
            $quantity,
            $optionIds
        );
        return $this->respond($result, 201);
    }

    // Return the signed-in customer's cart.

    public function view(Request $request): Response {
        return $this->respond((new CartService())->view($request->user()->id));
    }

    // Change the quantity of a cart item.

    public function updateQuantity(Request $request, array $params): Response {
        $cartItemId = InputValidator::positiveInteger($this->param($params, 'id'));
        $quantity = array_key_exists('quantity', $request->body)
            ? InputValidator::positiveInteger($request->body['quantity'])
            : null;
        if ($cartItemId === null) return Response::error('Cart item not found.', 404);
        if ($quantity === null) return Response::error('Quantity must be a whole number of at least 1.', 422);
        $result = (new CartService())->updateQuantity($request->user()->id, $cartItemId, $quantity);
        return $this->respond($result);
    }

    // Remove one item from the cart.
    public function removeItem(Request $request, array $params): Response {
        $cartItemId = InputValidator::positiveInteger($this->param($params, 'id'));
        if ($cartItemId === null) return Response::error('Cart item not found.', 404);
        return $this->respond((new CartService())->removeItem($request->user()->id, $cartItemId));
    }

    // Remove every item from the cart.
    public function clear(Request $request): Response {
        return $this->respond((new CartService())->clear($request->user()->id));
    }
}
