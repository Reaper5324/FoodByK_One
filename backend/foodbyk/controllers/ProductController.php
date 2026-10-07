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

    public function staffIndex(Request $request): Response {
        return $this->respond((new ProductService())->listForAdmin());
    }

    public function setStaffAvailability(Request $request, array $params): Response {
        $availabilityInput = $request->input('is_available');
        $available = $availabilityInput === null
            ? null
            : filter_var($availabilityInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($available === null) {
            return Response::error('Product availability must be true or false.', 422);
        }

        return $this->respond((new ProductService())->setAvailability(
            (int) $this->param($params, 'id'),
            $available
        ), 200, 404);
    }
}
