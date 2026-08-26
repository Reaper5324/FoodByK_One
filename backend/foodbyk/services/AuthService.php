<?php

class AuthService {
    private const MIN_PASSWORD_LENGTH = 12;
    private const MAX_PASSWORD_LENGTH = 128;
    private const MAX_NAME_LENGTH = 120;
    private const MAX_EMAIL_LENGTH = 254;
    private const DUMMY_PASSWORD_HASH = '$2y$12$Orrp0BWnrHGJxJKEbcr34OM4KZoAFhu7esQzaCO4SaTIAdTMNtbfe';

    public function register(string $name, string $email, string $password, ?string $phone = null): array {
        $name = trim($name);
        $email = $this->normaliseEmail($email);
        $phone = $this->normalisePhone($phone);

        $error = $this->validateRegistrationInput($name, $email, $password, $phone);
        if ($error !== null) {
            return $this->failure($error);
        }

        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            if (User::findByEmail($email) !== null) {
                $db->rollBack();
                return $this->failure('An account with this email already exists.');
            }

            $customerRole = Role::customer();
            if ($customerRole === null || $customerRole->id === null) {
                $db->rollBack();
                return $this->failure('Customer registration is unavailable.');
            }

            $user = new User(full_name: $name, email: $email, phone: $phone, role_id: $customerRole->id);
            $user->setPassword($password);
            if (!$user->save()) {
                $db->rollBack();
                return $this->failure('Unable to create the account.');
            }

            $db->commit();
            $this->establishSession($user);
            return $this->success($this->publicUser($user));
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // A database unique constraint on users.email is still required for concurrent registration.
            return $this->failure('Unable to create the account.');
        }
    }

    public function login(string $email, string $password): array {
        $email = $this->normaliseEmail($email);
        if ($email === '' || $password === '') {
            return $this->failure('Invalid email or password.');
        }

        $user = User::findByEmail($email);
        if ($user === null) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            return $this->failure('Invalid email or password.');
        }
        if (!$user->is_active || !$user->verifyPassword($password)) {
            return $this->failure('Invalid email or password.');
        }

        if (password_needs_rehash($user->password_hash, $this->passwordAlgorithm())) {
            $user->setPassword($password);
            if (!$user->save()) {
                return $this->failure('Unable to sign in at this time.');
            }
        }

        $this->establishSession($user);
        return $this->success($this->publicUser($user));
    }

    public function logout(): array {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
        }
        return $this->success(null);
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): array {
        if ($user->id === null || !$user->is_active || !$user->verifyPassword($currentPassword)) {
            return $this->failure('Unable to change password.');
        }
        $error = $this->validatePassword($newPassword, $user->email, $user->full_name);
        if ($error !== null) {
            return $this->failure($error);
        }

        $user->setPassword($newPassword);
        if (!$user->save()) {
            return $this->failure('Unable to change password.');
        }
        $this->establishSession($user);
        return $this->success(null);
    }

    public function validateRegistrationInput(string $name, string $email, string $password, ?string $phone): ?string {
        if ($name === '' || $this->stringLength($name) > self::MAX_NAME_LENGTH || preg_match('/[\p{C}]/u', $name) === 1) {
            return 'Enter a valid full name.';
        }
        if ($email === '' || strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Enter a valid email address.';
        }
        if ($phone !== null && preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) !== 1) {
            return 'Enter a valid phone number.';
        }
        return $this->validatePassword($password, $email, $name);
    }

    public function validatePassword(string $password, string $email = '', string $name = ''): ?string {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH || strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return 'Password must be between 12 and 128 characters.';
        }

        //Regex
        if (preg_match('/\s/', $password) === 1 || preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1
            || preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
            return 'Password must include upper-case, lower-case, number, and symbol characters, with no spaces.';
        }

        $passwordLower = strtolower($password);
        foreach (array_filter([strtok(strtolower($email), '@') ?: '', strtolower(str_replace(' ', '', $name))]) as $personalValue) {
            if (strlen($personalValue) >= 3 && str_contains($passwordLower, $personalValue)) {
                return 'Password must not contain personal account information.';
            }
        }
        return null;
    }

    private function establishSession(User $user): void {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user->id;
        $_SESSION['authenticated_at'] = time();
    }

    private function normaliseEmail(string $email): string {
        return strtolower(trim($email));
    }

    private function stringLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function normalisePhone(?string $phone): ?string {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        return '+' . ltrim(preg_replace('/[\s\-()]/', '', trim($phone)), '+');
    }

    //should use BCRYPT
    private function passwordAlgorithm(): string|int {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private function publicUser(User $user): array {
        return ['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $user->role];
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
