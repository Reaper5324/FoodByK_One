<?php

class AuthController extends Controller {

    public function register(Request $request): Response {
        $result = (new AuthService())->register(
            (string) $request->input('name', $request->input('full_name', '')),
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            $request->input('phone') === null ? null : (string) $request->input('phone')
        );
        return $this->respond($result, 201);
    }

    public function login(Request $request): Response {
        return $this->respond((new AuthService())->login((string) $request->input('email', ''), (string) $request->input('password', '')), 200, 401);
    }

    public function logout(Request $request): Response {
        return $this->respond((new AuthService())->logout());
    }

    public function me(Request $request): Response {
        $result = (new AuthService())->getCurrentUserDetails($request->user());
        return $this->respond($result, 200, 401);
    }

    public function requestPasswordReset(Request $request): Response {
        return $this->respond((new AuthService())->requestPasswordReset((string) $request->input('email', '')));
    }

    public function resetPassword(Request $request): Response {
        return $this->respond((new AuthService())->resetPassword((string) $request->input('token', ''), (string) $request->input('password', '')));
    }

    public function changePassword(Request $request): Response {
        $result = (new AuthService())->changePassword($request->user(), $request->input('current_password'), $request->input('new_password'));
        return $this->respond($result);
    }
}
