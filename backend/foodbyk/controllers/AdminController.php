<?php

class AdminController extends Controller {

    public function addStaff(Request $request): Response {
        $result = (new AuthService())->createStaffAccount($request->input('full_name'), $request->input('email'), $request->input('role'), $request->input('phone'));
        return $this->respond($result, 201);
    }

    public function addProduct(Request $request): Response {
        return $this->respond((new ProductService())->create($request->body), 201);
    }

    public function removeProduct(Request $request, array $params): Response {
        return $this->respond((new ProductService())->remove((int) $this->param($params, 'id')), 200, 404);
    }

    public function addPromotion(Request $request): Response {
        return $this->respond((new AdminSettingsService())->addPromotion($request->body), 201);
    }

    public function updateSettings(Request $request): Response {
        return $this->respond((new AdminSettingsService())->updateSettings($request->body));
    }
}