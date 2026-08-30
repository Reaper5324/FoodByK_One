<?php

class AuthController extends Controller {

    public function register(Request $request): Response {
        $result = (new AuthService())->register(
            $request->input('full_name'),
            $request->input('email'),
            $request->input('password'),
            $request->input('phone')
        );
        return $this->respond($result, 201);
    }

    public function login(Request $request): Response {
        return $this->respond((new AuthService())->login($request->input('email'), $request->input('password')), 200, 401);
    }

    public function logout(Request $request): Response {
        return $this->respond((new AuthService())->logout());
    }

    public function me(Request $request): Response {
        $user = (new AuthService())->getCurrentUser();
        if (!$user) {
            return Response::error('Not authenticated.', 401);
        }
        $role = Role::findById($user->role_id);
        return Response::success(['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'role' => $role?->role_name]);
    }

    public function requestPasswordReset(Request $request): Response {
        return $this->respond((new AuthService())->requestPasswordReset($request->input('email')));
    }

    public function resetPassword(Request $request): Response {
        return $this->respond((new AuthService())->resetPassword($request->input('token'), $request->input('password')));
    }

    public function changePassword(Request $request): Response {
        $result = (new AuthService())->changePassword($request->user(), $request->input('current_password'), $request->input('new_password'));
        return $this->respond($result);
    }
}