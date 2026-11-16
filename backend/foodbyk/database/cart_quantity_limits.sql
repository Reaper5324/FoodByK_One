ALTER TABLE business_settings
    ADD COLUMN max_item_quantity INT UNSIGNED NOT NULL DEFAULT 6 AFTER max_orders_per_slot,
    ADD COLUMN max_cart_items INT UNSIGNED NOT NULL DEFAULT 10 AFTER max_item_quantity;
