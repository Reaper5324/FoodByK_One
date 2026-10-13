<?php

class PolicyService {
    public const TERMS_VERSION = '2026-10-13';
    public const PRIVACY_VERSION = '2026-10-13';

    public function hasAcceptedCurrentVersions(int $userId): bool {
        try {
            $statement = Database::getConnection()->prepare(
                "SELECT COUNT(DISTINCT policy_type) FROM policy_acceptances
                 WHERE user_id = ? AND ((policy_type = 'terms' AND policy_version = ?)
                    OR (policy_type = 'privacy_notice' AND policy_version = ?))"
            );
            $statement->execute([$userId, self::TERMS_VERSION, self::PRIVACY_VERSION]);
            return (int) $statement->fetchColumn() === 2;
        } catch (PDOException $error) {
            // A missing/unavailable policy table must not turn valid credentials
            // into a 500. Treat the user as needing review and keep protected
            // actions blocked until acceptance can actually be persisted.
            error_log('Policy acceptance lookup failed; treating customer as requiring review. ' . $error->getMessage());
            return false;
        }
    }

    public function recordCurrentVersions(int $userId): void {
        $statement = Database::getConnection()->prepare(
            'INSERT IGNORE INTO policy_acceptances (user_id, policy_type, policy_version)
             VALUES (?, ?, ?), (?, ?, ?)'
        );
        $statement->execute([
            $userId, 'terms', self::TERMS_VERSION,
            $userId, 'privacy_notice', self::PRIVACY_VERSION,
        ]);
    }

    public function acceptCurrent(User $user, array $input): array {
        if (($input['terms_accepted'] ?? false) !== true || ($input['privacy_notice_acknowledged'] ?? false) !== true) {
            return ['success' => false, 'data' => null, 'error' => 'Please accept the current terms and acknowledge the privacy notice.'];
        }
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $this->recordCurrentVersions((int) $user->id);
            $db->commit();
            return ['success' => true, 'data' => ['accepted' => true], 'error' => null];
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Policy acceptance could not be recorded: ' . $error->getMessage());
            return ['success' => false, 'data' => null, 'error' => 'We could not save your policy choices right now. Please try again later or contact Food by K.'];
        }
    }
}
