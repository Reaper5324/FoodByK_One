-- Run once on the application database before deploying email verification.
-- Accounts that already existed before verification was introduced stay usable.

ALTER TABLE users
    ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL AFTER email;

UPDATE users
SET email_verified_at = COALESCE(created_at, CURRENT_TIMESTAMP)
WHERE email_verified_at IS NULL;

CREATE TABLE customer_email_verifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_email_verification_user (user_id),
    UNIQUE KEY uq_customer_email_verification_token (token_hash),
    CONSTRAINT fk_customer_email_verification_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_customer_email_verification_expiry (expires_at)
) ENGINE=InnoDB;
