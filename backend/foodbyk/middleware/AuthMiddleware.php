<?php


class AuthMiddleware implements Middleware {

    public function handle(Request $request): ?Response {
        $authService = new AuthService();
        $baseUser = $authService->getCurrentUser();

        if(!$baseUser || !$baseUser->is_active){
            return Response::error('Authentication required', 401);

        }
        $role = Role::findbyId($baseUser->role_id);
         $request->attributes['user'] = match ($role?->role_name) {
            Role::CUSTOMER => Customer::findCustomerById($baseUser->id),
            Role::STAFF    => Staff::findStaffById($baseUser->id),
            Role::ADMIN    => Admin::findAdminById($baseUser->id),
            default        => null,
        };

        if ($request->attributes['user'] === null){
            return Response::error('authentication Required', 401);

        }
        return null;
    }

    

}