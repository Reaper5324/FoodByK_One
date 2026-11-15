-- Run once on an existing database before deploying product customizations.
CREATE TABLE product_options (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    INT UNSIGNED NOT NULL,
    name          VARCHAR(80) NOT NULL,
    price_delta   DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    is_available  TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_product_options_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT chk_product_options_price CHECK (price_delta >= 0),
    INDEX idx_product_options_product (product_id, is_available, display_order)
) ENGINE=InnoDB;

ALTER TABLE cart_items
    ADD COLUMN customization_json LONGTEXT DEFAULT NULL,
    ADD COLUMN customization_hash CHAR(64) DEFAULT NULL;

UPDATE cart_items
SET customization_json = '[]', customization_hash = SHA2('[]', 256)
WHERE customization_json IS NULL OR customization_hash IS NULL;

ALTER TABLE cart_items
    DROP INDEX uq_cart_item,
    ADD UNIQUE KEY uq_cart_item (customer_id, product_id, customization_hash);

ALTER TABLE order_items
    ADD COLUMN customization_json LONGTEXT DEFAULT NULL;
