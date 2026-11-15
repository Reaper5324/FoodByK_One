-- Run once on an existing application database before deploying the
-- customer-level saved-card token workflow.
CREATE TABLE customer_payment_tokens (
    user_id       INT UNSIGNED NOT NULL PRIMARY KEY,
    gateway       VARCHAR(30) NOT NULL DEFAULT 'payfast',
    gateway_token VARCHAR(512) NOT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_payment_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE customer_payment_token_setups (
    reference   VARCHAR(64) NOT NULL PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) DEFAULT NULL,
    expires_at  DATETIME NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_payment_token_setups_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_customer_payment_token_setups_expiry (expires_at)
) ENGINE=InnoDB;
