-- Run once against an existing Food by K database to add support ticket storage.
CREATE TABLE IF NOT EXISTS support_tickets (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    subject     VARCHAR(150) NOT NULL,
    category    ENUM('general', 'order', 'menu_item', 'cart', 'checkout', 'payment', 'delivery', 'account', 'other') NOT NULL,
    message     TEXT NOT NULL,
    related_order_id INT UNSIGNED NULL,
    related_product_id INT UNSIGNED NULL,
    status      ENUM('open', 'in_progress', 'resolved', 'closed') NOT NULL DEFAULT 'open',
    resolved_by INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_tickets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_support_tickets_resolver FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_support_tickets_order FOREIGN KEY (related_order_id) REFERENCES orders(id) ON DELETE SET NULL,
    CONSTRAINT fk_support_tickets_product FOREIGN KEY (related_product_id) REFERENCES products(id) ON DELETE SET NULL,
    INDEX idx_support_tickets_user_updated (user_id, updated_at),
    INDEX idx_support_tickets_status_updated (status, updated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS support_replies (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id     INT UNSIGNED NOT NULL,
    admin_id      INT UNSIGNED NOT NULL,
    reply_message TEXT NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_support_replies_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_replies_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_support_replies_ticket_created (ticket_id, created_at)
) ENGINE=InnoDB;
