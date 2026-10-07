<?php

class AdminController extends Controller {
    public function staff(Request $request): Response { return $this->respond((new AuthService())->listStaffAccounts()); }
    public function products(Request $request): Response { return $this->respond((new ProductService())->listForAdmin()); }
    public function promotions(Request $request): Response { return $this->respond((new AdminSettingsService())->listPromotions()); }
    public function settings(Request $request): Response { return $this->respond((new AdminSettingsService())->getSettings()); }
    public function customers(Request $request): Response { return $this->respond((new AuthService())->listCustomers()); }
    public function updateCustomer(Request $request, array $params): Response {
        return $this->respond((new AuthService())->setCustomerActive((int) $this->param($params, 'id'), $request->input('is_active')));
    }

    public function addStaff(Request $request): Response {
        $result = (new AuthService())->createStaffAccount($request->input('full_name'), $request->input('email'), $request->input('role'), $request->input('phone'));
        return $this->respond($result, 201);
    }

    public function updateStaff(Request $request, array $params): Response {
        return $this->respond((new AuthService())->updateStaffAccount((int) $this->param($params, 'id'), $request->body));
    }   

    public function removeStaff(Request $request, array $params): Response {
        return $this->respond((new AuthService())->deactivateStaffAccount((int) $this->param($params, 'id')));
    }

    public function addProduct(Request $request): Response {
        return $this->respond((new ProductService())->create($request->body), 201);
    }

    public function updateProduct(Request $request, array $params): Response {
        return $this->respond((new ProductService())->update((int) $this->param($params, 'id'), $request->body));
    }

    public function removeProduct(Request $request, array $params): Response {
        return $this->respond((new ProductService())->remove((int) $this->param($params, 'id')), 200, 404);
    }

    public function addPromotion(Request $request): Response {
        return $this->respond((new AdminSettingsService())->addPromotion($request->body), 201);
    }

    public function updatePromotion(Request $request, array $params): Response {
        return $this->respond((new AdminSettingsService())->updatePromotion((int) $this->param($params, 'id'), $request->body));
    }

    public function removePromotion(Request $request, array $params): Response {
        return $this->respond((new AdminSettingsService())->deactivatePromotion((int) $this->param($params, 'id')));
    }

    public function updateSettings(Request $request): Response {
        return $this->respond((new AdminSettingsService())->updateSettings($request->body));
    }

    public function categories(Request $request): Response {
        return $this->respond((new CategoryService())->listAll());
    }

    public function addCategory(Request $request): Response {
        return $this->respond((new CategoryService())->create($request->body), 201);
    }

    public function updateCategory(Request $request, array $params): Response {
        return $this->respond((new CategoryService())->update((int) $this->param($params, 'id'), $request->body));
    }

    public function removeCategory(Request $request, array $params): Response {
        return $this->respond((new CategoryService())->delete((int) $this->param($params, 'id')));
    }
    
}
