<?php

class CartController extends Controller {

//add an item to cart
    public function add(Request $request): Response {
        $customer = $request->user();
        $result = (new CartService())->addItem($customer->id, (int) $request->input('product_id'), (int) ($request->input('quantity') ?? 1));
        return $this->respond($result, 201);
    }

    //view what is in the cart

    public function view(Request $request): Response {
        return $this->respond((new CartService())->view($request->user()->id));
    }

    //+1 item

    public function updateQuantity(Request $request, array $params): Response {
        $result = (new CartService())->updateQuantity($request->user()->id, (int) $this->param($params, 'id'), (int) $request->input('quantity'));
        return $this->respond($result);
    }

    //rem item
    public function removeItem(Request $request, array $params): Response {
        return $this->respond((new CartService())->removeItem($request->user()->id, (int) $this->param($params, 'id')));
    }

    //clear the cart
    public function clear(Request $request): Response {
        return $this->respond((new CartService())->clear($request->user()->id));
    }
}