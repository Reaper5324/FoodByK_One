<?php

class AuthService {
    private const MIN_PASSWORD_LENGTH = 12;
    private const MAX_PASSWORD_LENGTH = 128;
    private const MAX_NAME_LENGTH = 120;
    private const MAX_EMAIL_LENGTH = 254;
    private const SESSION_IDLE_LIFETIME_SECONDS = 7200;
    private const SESSION_ABSOLUTE_LIFETIME_SECONDS = 86400;
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
        // Admin-only provisioning - there is no public registration path to
    // staff/admin roles. The account is created with a random, unknown
    // password and an invite link is issued through the same
    // PasswordReset flow used for forgotten passwords - the admin never
    // sets or sees the actual login credential.
    public function createStaffAccount(string $name, string $email, string $role, ?string $phone = null): array {
        $name = trim($name);
        $email = $this->normaliseEmail($email);
        $phone = $this->normalisePhone($phone);

        if (!in_array($role, [Role::STAFF, Role::ADMIN], true)) {
            return $this->failure('Invalid role.');
        }
        if ($name === '' || $this->stringLength($name) > self::MAX_NAME_LENGTH) {
            return $this->failure('Enter a valid full name.');
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->failure('Enter a valid email address.');
        }
        if (User::findByEmail($email) !== null) {
            return $this->failure('An account with this email already exists.');
        }

        $roleRow = Role::findOneBy('role_name', $role);
        if ($roleRow === null || $roleRow->id === null) {
            return $this->failure('That role is not configured.');
        }

        $user = new User(full_name: $name, email: $email, phone: $phone, role_id: $roleRow->id);
        $user->setPassword(bin2hex(random_bytes(32))); // unusable placeholder - account activates only via invite link
        if (!$user->save()) {
            return $this->failure('Unable to create the account.');
        }

        $invite = PasswordReset::create($user->id, 72); // 72h invite window, wider than the 1h forgot-password window
        if ($invite['success']) {
            error_log("Staff/admin invite link for {$email}: /set-password?token={$invite['token']}");
        }

        return $this->success(['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'role' => $role]);
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
        $_SESSION['last_activity'] = time();
    }

    public function getCurrentUser(): ?User {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        $authenticatedAt = $_SESSION['authenticated_at'] ?? null;
        $lastActivity = $_SESSION['last_activity'] ?? null;
        $now = time();

        if (!is_int($userId) && !ctype_digit((string) $userId)
            || !is_int($authenticatedAt) || !is_int($lastActivity)
            || $now - $authenticatedAt >= self::SESSION_ABSOLUTE_LIFETIME_SECONDS
            || $now - $lastActivity >= self::SESSION_IDLE_LIFETIME_SECONDS) {
            $this->destroySession();
            return null;
        }

        $user = User::findById((int) $userId);
        if ($user === null) {
            $this->destroySession();
            return null;
        }

        $_SESSION['last_activity'] = $now;
        return $user;
    }

    private function destroySession(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function requestPasswordReset(string $email): array {
        $email = $this->normaliseEmail($email);
        $user = User::findByEmail($email);

        if($user == null){return $this->success(null);}

        $result = PasswordReset::create($user->id, 1);
        if($result['success']){
            error_log("Password reset link for {$email}: /reset-password?token={$result['token']}");
        }

        return $this->success(null);
    }

     public function resetPassword(string $rawToken, string $newPassword): array {
        $reset = PasswordReset::findByToken($rawToken); // already filters expires_at > NOW()
        if ($reset === null) {
            return $this->failure('This reset link is invalid or has expired.');
        }

        $user = User::findById($reset->user_id);
        if ($user === null || !$user->is_active) {
            return $this->failure('This reset link is invalid or has expired.');
        }
         $error = $this->validatePassword($newPassword, $user->email, $user->full_name);
        if ($error !== null) {
            return $this->failure($error);
        }

        $user->setPassword($newPassword);
        if (!$user->save()) {
            return $this->failure('Unable to reset password at this time.');
        }

        // No used-flag exists on this model - deleting every outstanding
        // token for this user is the closest equivalent, and correctly
        // invalidates any other reset links requested since.
        PasswordReset::deleteForUser($user->id);

        return $this->success(null);
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
        $role = Role::findById($user->role_id);
        return ['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $role?->role_name];
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
