<?php

class CategoryController extends Controller {

    public function index(Request $request): Response {
        return $this->respond((new CategoryService())->listActive());
    }

    public function show(Request $request, array $params): Response {
        return $this->respond((new CategoryService())->getById((int) $this->param($params, 'id')), 200, 404);
    }

    public function products(Request $request, array $params): Response {
        $search = $request->query['q'] ?? null;
        return $this->respond((new CategoryService())->getProductsInCategory((int) $this->param($params, 'id'), $search));
    }
}