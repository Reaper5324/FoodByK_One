<?php

class CategoryController extends Controller {

    public function index(Request $request): Response {
        return Response::success(Category::findAll());
    }

    public function products(Request $request, array $params): Response {
        return $this->respond((new ProductService())->listByCategory((int) $this->param($params, 'id')));
    }
}