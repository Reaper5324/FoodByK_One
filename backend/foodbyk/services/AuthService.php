<?php

class AuthService {

    public function listStaffAccounts(): array {
        $db = Database::getConnection();
        $rows = $db->query(
            "SELECT u.id, u.name AS full_name, u.email, u.phone, u.is_active,
                    u.created_at, r.role_name AS role
             FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.role_name IN ('staff', 'admin')
             ORDER BY r.role_name, u.name"
        )->fetchAll();
        return ['success' => true, 'data' => $rows, 'error' => null];
    }

    public function listCustomers(): array {
        $db = Database::getConnection();
        $rows = $db->query(
            "SELECT u.id, u.name AS full_name, u.email, u.phone, u.is_active, u.created_at,
                    COUNT(DISTINCT o.id) AS order_count,
                    COALESCE(SUM(CASE WHEN p.status = 'success' THEN p.amount ELSE 0 END), 0) AS total_spent
             FROM users u JOIN roles r ON r.id = u.role_id
             LEFT JOIN orders o ON o.customer_id = u.id
             LEFT JOIN payments p ON p.order_id = o.id
             WHERE r.role_name = 'customer'
             GROUP BY u.id
             ORDER BY u.created_at DESC"
        )->fetchAll();
        return ['success' => true, 'data' => $rows, 'error' => null];
    }

    public function setCustomerActive(int $customerId, mixed $active): array {
        $customer = User::findById($customerId);
        if (!$customer || $customer->getRole()?->role_name !== Role::CUSTOMER) {
            return $this->failure('Customer account not found.');
        }
        $isActive = filter_var($active, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isActive === null) return $this->failure('Invalid customer account status.');
        $customer->is_active = $isActive;
        return $customer->save()
            ? $this->success(['id' => $customer->id, 'is_active' => $customer->is_active])
            : $this->failure('Unable to update customer account.');
    }
    private const MIN_PASSWORD_LENGTH = 12;
    private const MAX_PASSWORD_LENGTH = 128;
    private const MAX_NAME_LENGTH = 120;
    private const MAX_EMAIL_LENGTH = 254;
    private const SESSION_IDLE_LIFETIME_SECONDS = 7200;
    private const SESSION_ABSOLUTE_LIFETIME_SECONDS = 86400;
    private const DUMMY_PASSWORD_HASH = '$2y$12$Orrp0BWnrHGJxJKEbcr34OM4KZoAFhu7esQzaCO4SaTIAdTMNtbfe';

    public function register(string $name, string $email, string $password, ?string $phone = null, bool $termsAccepted = false, bool $privacyAcknowledged = false): array {
        if (!$termsAccepted || !$privacyAcknowledged) {
            return $this->failure('Please accept the current terms and acknowledge the privacy notice to create an account.');
        }
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

            (new PolicyService())->recordCurrentVersions((int) $user->id);

            $db->commit();
            $this->establishSession($user);
return $this->success(array_merge($this->publicUser($user), ['session_token' => session_id()]));
        } catch (Throwable) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // The database unique constraint handles simultaneous registrations.
            return $this->failure('Unable to create the account.');
        }
    }

    public function login(string $email, string $password): array {
        $email = $this->normaliseEmail($email);
        if ($email === '' || $password === '') {
            RateLimitMiddleware::recordLoginFailure($email);
            return $this->failure('Invalid email or password.');
        }

        $user = User::findByEmail($email);
        if ($user === null) {
            password_verify($password, self::DUMMY_PASSWORD_HASH);
            RateLimitMiddleware::recordLoginFailure($email);
            return $this->failure('Invalid email or password.');
        }
        if (!$user->is_active || !$user->verifyPassword($password)) {
            RateLimitMiddleware::recordLoginFailure($email);
            return $this->failure('Invalid email or password.');
        }

        if (password_needs_rehash($user->password_hash, $this->passwordAlgorithm())) {
            $user->setPassword($password);
            if (!$user->save()) {
                return $this->failure('Unable to sign in at this time.');
            }
        }

        $this->establishSession($user);
return $this->success(array_merge($this->publicUser($user), ['session_token' => session_id()]));
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
    // Staff set their password from the setup link sent by email.
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
        $user->setPassword(bin2hex(random_bytes(32)));
        if (!$user->save()) {
            return $this->failure('Unable to create the account.');
        }

        $invite = PasswordReset::create($user->id, 72);
        if ($invite['success']) {
            if (!$this->sendAccountLink($email, $name, $invite['token'], true)) {
                PasswordReset::deleteForUser($user->id);
                $user->delete();
                return $this->failure('Unable to send the account setup email.');
            }
        } else {
            $user->delete();
            return $this->failure('Unable to create the account setup link.');
        }

        return $this->success(['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'role' => $role]);
    }

    public function updateStaffAccount(int $userId, array $input): array {
        $user = User::findById($userId);
        if ($user === null || $user->getRole()?->role_name !== Role::STAFF) {
            return $this->failure('Staff account not found.');
        }

        $name = trim((string) ($input['full_name'] ?? $user->full_name));
        $email = $this->normaliseEmail((string) ($input['email'] ?? $user->email));
        $phone = array_key_exists('phone', $input)
            ? $this->normalisePhone($input['phone'] === null ? null : (string) $input['phone'])
            : $user->phone;

        if ($name === '' || $this->stringLength($name) > self::MAX_NAME_LENGTH || preg_match('/[\p{C}]/u', $name) === 1) {
            return $this->failure('Enter a valid full name.');
        }
        if ($email === '' || strlen($email) > self::MAX_EMAIL_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->failure('Enter a valid email address.');
        }
        if ($phone !== null && preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) !== 1) {
            return $this->failure('Enter a valid phone number.');
        }
        $existing = User::findByEmail($email);
        if ($existing !== null && $existing->id !== $user->id) {
            return $this->failure('An account with this email already exists.');
        }

        if (array_key_exists('is_active', $input)) {
            $active = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) {
                return $this->failure('Invalid staff account status.');
            }
            $user->is_active = $active;
        }

        $user->full_name = $name;
        $user->email = $email;
        $user->phone = $phone;

        return $user->save()
            ? $this->success(['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'phone' => $user->phone, 'role' => Role::STAFF, 'is_active' => $user->is_active])
            : $this->failure('Unable to update staff account.');
    }

    public function deactivateStaffAccount(int $userId): array {
        $user = User::findById($userId);
        if ($user === null || $user->getRole()?->role_name !== Role::STAFF) {
            return $this->failure('Staff account not found.');
        }
        if (!$user->is_active) {
            return $this->success(['id' => $user->id, 'is_active' => false]);
        }

        $user->is_active = false;
        return $user->save()
            ? $this->success(['id' => $user->id, 'is_active' => false])
            : $this->failure('Unable to deactivate staff account.');
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

    public function getCurrentUserDetails(?User $user = null): array {
        $user ??= $this->getCurrentUser();
        if ($user === null) return $this->failure('Not authenticated.');

        $role = Role::findById($user->role_id);
        return $this->success([
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'address' => $user->address,
            'city' => $user->city,
            'province' => $user->province,
            'role' => $role?->role_name,
            'policy_acceptance_required' => $role?->role_name === Role::CUSTOMER
                && !(new PolicyService())->hasAcceptedCurrentVersions((int) $user->id),
        ]);
    }

    public function acceptCurrentPolicies(User $user, array $input): array {
        if ($user->getRole()?->role_name !== Role::CUSTOMER) return $this->failure('Customer account not found.');
        return (new PolicyService())->acceptCurrent($user, $input);
    }

    public function deleteCustomerAccount(User $sessionUser, string $password): array {
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $lock = $db->prepare('SELECT id, name, email, password_hash, role_id FROM users WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $sessionUser->id]);
            $account = $lock->fetch(PDO::FETCH_ASSOC);
            $customerRole = Role::customer();
            if (!$account || !$customerRole || (int) $account['role_id'] !== (int) $customerRole->id
                || !password_verify($password, $account['password_hash'])) {
                $db->rollBack();
                return $this->failure('The password is incorrect or the customer account was not found.');
            }

            $active = $db->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status IN ('submitted','accepted','adjusted','charge_pending','payment_failed','paid','preparing','ready')");
            $active->execute([(int) $account['id']]);
            if ((int) $active->fetchColumn() > 0) {
                $db->rollBack();
                return $this->failure('Your account cannot be deleted while an order is in progress. Contact Food by K to resolve the order first.');
            }

            $addressIds = $db->prepare('SELECT a.id FROM addresses a WHERE a.customer_id = ? AND EXISTS (SELECT 1 FROM orders o WHERE o.address_id = a.id)');
            $addressIds->execute([(int) $account['id']]);
            foreach ($addressIds->fetchAll(PDO::FETCH_COLUMN) as $addressId) {
                $scrubAddress = $db->prepare("UPDATE addresses SET raw_address = 'Deleted customer address', street = NULL, postal_code = NULL, city = NULL, province = NULL, latitude = NULL, longitude = NULL, is_default = 0 WHERE id = ?");
                $scrubAddress->execute([(int) $addressId]);
            }
            $db->prepare('DELETE FROM addresses WHERE customer_id = ? AND id NOT IN (SELECT address_id FROM orders WHERE address_id IS NOT NULL)')->execute([(int) $account['id']]);
            $db->prepare('DELETE FROM cart_items WHERE customer_id = ?')->execute([(int) $account['id']]);
            $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int) $account['id']]);
            $db->prepare("UPDATE payments p JOIN orders o ON o.id = p.order_id SET p.gateway_token = NULL, p.status = IF(p.status = 'tokenized', 'voided', p.status) WHERE o.customer_id = ?")->execute([(int) $account['id']]);
            $db->prepare('UPDATE orders SET decline_reason = NULL, cancel_reason = NULL WHERE customer_id = ?')->execute([(int) $account['id']]);
            $db->prepare("UPDATE order_status_history h JOIN orders o ON o.id = h.order_id SET h.notes = NULL WHERE o.customer_id = ?")->execute([(int) $account['id']]);

            $deletedEmail = 'deleted-' . (int) $account['id'] . '-' . bin2hex(random_bytes(8)) . '@account.invalid';
            $deletedPassword = password_hash(bin2hex(random_bytes(32)), $this->passwordAlgorithm());
            $db->prepare("UPDATE users SET name = 'Deleted customer', email = ?, password_hash = ?, phone = NULL, address = NULL, city = NULL, province = NULL, profile_picture = NULL, loyalty_points = 0, is_active = 0, deleted_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$deletedEmail, $deletedPassword, (int) $account['id']]);
            $db->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([
                'login:email:' . hash('sha256', strtolower((string) $account['email'])),
            ]);
            $db->commit();
            $this->destroySession();
            return $this->success(['deleted' => true]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Customer account deletion failed: ' . $error->getMessage());
            return $this->failure('Unable to delete the account right now. Please contact Food by K.');
        }
    }

    public function updateCustomerProfile(User $sessionUser, array $input): array {
        $user = User::findById((int) $sessionUser->id);
        if ($user === null || $user->getRole()?->role_name !== Role::CUSTOMER) {
            return $this->failure('Customer account not found.');
        }

        $name = trim((string) ($input['full_name'] ?? ''));
        $email = $this->normaliseEmail((string) ($input['email'] ?? ''));
        $phone = $this->normalisePhone(isset($input['phone']) ? (string) $input['phone'] : null);
        $address = array_key_exists('address', $input) ? trim((string) $input['address']) : (string) ($user->address ?? '');
        $city = array_key_exists('city', $input) ? trim((string) $input['city']) : (string) ($user->city ?? '');
        $province = array_key_exists('province', $input) ? trim((string) $input['province']) : (string) ($user->province ?? '');

        if ($name === '' || $this->stringLength($name) > self::MAX_NAME_LENGTH || preg_match('/[\p{C}]/u', $name) === 1) {
            return $this->failure('Enter a valid full name.');
        }
        if ($email === '' || strlen($email) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->failure('Enter a valid email address.');
        }
        if ($email !== $user->email) {
            return $this->failure('Your login email cannot be changed from this page. Contact Food by K for help updating it.');
        }
        if ($phone !== null && preg_match('/^\+[1-9][0-9]{7,14}$/', $phone) !== 1) {
            return $this->failure('Enter a valid phone number with country code.');
        }
        if ($this->stringLength($address) > 255 || $this->stringLength($city) > 100 || $this->stringLength($province) > 100) {
            return $this->failure('Address, city, or province is too long.');
        }

        $existing = User::findByEmail($email);
        if ($existing !== null && $existing->id !== $user->id) {
            return $this->failure('An account with this email already exists.');
        }

        $user->full_name = $name;
        $user->email = $email;
        $user->phone = $phone;
        $user->address = $address === '' ? null : $address;
        $user->city = $city === '' ? null : $city;
        $user->province = $province === '' ? null : $province;

        return $user->save()
            ? $this->success([
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => $user->address,
                'city' => $user->city,
                'province' => $user->province,
                'role' => Role::CUSTOMER,
            ])
            : $this->failure('Unable to update your account details.');
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
        if ($result['success'] && !$this->sendAccountLink($email, $user->full_name, $result['token'], false)) {
            // Keep the public response generic to avoid revealing account
            // existence; never put the bearer token in application logs.
            error_log('Password reset email delivery failed for user ' . $user->id . '.');
        }

        return $this->success(null);
    }

     public function resetPassword(string $rawToken, string $newPassword): array {
        $reset = PasswordReset::findByToken($rawToken);
        if ($reset === null) {
            RateLimitMiddleware::recordResetFailure();
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

        // Invalidate any other reset links issued for this account.
        PasswordReset::deleteForUser($user->id);

        return $this->success(null);
    }

    private function normaliseEmail(string $email): string {
        return strtolower(trim($email));
    }

    private function sendAccountLink(string $email, string $name, string $token, bool $invite): bool {
        if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '') {
            error_log('Resend account link not sent: RESEND_API_KEY is not configured.');
            return false;
        }
        if (!defined('FRONTEND_URL') || FRONTEND_URL === '') {
            error_log('Resend account link not sent: FRONTEND_URL is not configured.');
            return false;
        }
        $link = rtrim(FRONTEND_URL, '/') . '/pages/auth/reset-password.html?token=' . rawurlencode($token);
        $subject = $invite ? 'Set up your Food by K account' : 'Reset your Food by K password';
        $action = $invite ? 'set your password' : 'reset your password';
        $payload = [
            'from' => RESEND_FROM_EMAIL,
            'to' => [$email],
            'subject' => $subject,
            'text' => "Hi {$name},\n\nUse this link to {$action}: {$link}\n\nIf you did not request this, you can ignore this email.",
        ];
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer " . RESEND_API_KEY . "\r\nContent-Type: application/json\r\n",
            'content' => json_encode($payload),
            'timeout' => 8,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents('https://api.resend.com/emails', false, $context);
        $status = $http_response_header[0] ?? '';
        if ($response === false || preg_match('/\s2\d\d\s/', $status) !== 1) {
            $error = json_decode(is_string($response) ? $response : '', true);
            $errorName = is_array($error) ? (string) ($error['name'] ?? $error['error'] ?? 'unknown_error') : 'no_response_body';
            $errorMessage = is_array($error) ? (string) ($error['message'] ?? 'No provider error message.') : 'The provider returned no readable error.';
            error_log(sprintf(
                'Resend account link failed (%s; %s): %s',
                $status !== '' ? $status : 'no HTTP status',
                substr($errorName, 0, 80),
                substr($errorMessage, 0, 300)
            ));
            return false;
        }

        return true;
    }

    private function stringLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function normalisePhone(?string $phone): ?string {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $phone = preg_replace('/[\s\-().]/', '', trim($phone));
        if (str_starts_with($phone, '0')) {
            $phone = '+27' . substr($phone, 1);
        } elseif (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }
        return $phone;
    }

    private function passwordAlgorithm(): string|int {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private function publicUser(User $user): array {
        $role = Role::findById($user->role_id);
        return ['id' => $user->id, 'full_name' => $user->full_name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $role?->role_name,
            'policy_acceptance_required' => $role?->role_name === Role::CUSTOMER && !(new PolicyService())->hasAcceptedCurrentVersions((int) $user->id)];
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
