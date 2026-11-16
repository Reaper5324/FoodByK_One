ALTER TABLE business_settings
    ADD COLUMN minimum_order_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER max_cart_items,
    ADD COLUMN ordering_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER minimum_order_amount,
    ADD COLUMN business_phone VARCHAR(30) NOT NULL DEFAULT '081 447 8365' AFTER ordering_enabled,
    ADD COLUMN business_address VARCHAR(255) NOT NULL DEFAULT '8513 Goshawk Street, Lenasia South, Gauteng' AFTER business_phone;
