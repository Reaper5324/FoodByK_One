<?php

class ProductController extends Controller {

    public function index(Request $request): Response {
        return $this->respond((new ProductService())->listAvailable());
    }

    public function show(Request $request, array $params): Response {
        return $this->respond((new ProductService())->findAvailableById((int) $this->param($params, 'id')), 200, 404);
    }

    public function byCategory(Request $request, array $params): Response {
        return $this->respond((new ProductService())->listByCategory((int) $this->param($params, 'id')));
    }

    public function search(Request $request): Response {
        return $this->respond((new ProductService())->search($request->query['q'] ?? ''));
    }
}