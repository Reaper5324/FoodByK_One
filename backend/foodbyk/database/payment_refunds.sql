-- Apply once to add the auditable PayFast refund request ledger.
CREATE TABLE payment_refunds (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id   INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    amount_cents INT UNSIGNED NOT NULL,
    reason       VARCHAR(255) NOT NULL,
    status       ENUM('pending', 'submitted', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
    CONSTRAINT fk_payment_refunds_user FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT chk_payment_refunds_amount_positive CHECK (amount_cents > 0),
    INDEX idx_payment_refunds_payment_status (payment_id, status)
) ENGINE=InnoDB;
