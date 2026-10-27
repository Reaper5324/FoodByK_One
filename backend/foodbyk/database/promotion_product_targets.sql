-- Run once on an existing database to enable promotions for selected menu items.
CREATE TABLE promotion_products (
    promotion_id INT UNSIGNED NOT NULL,
    product_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (promotion_id, product_id),
    CONSTRAINT fk_promotion_products_promotion FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    CONSTRAINT fk_promotion_products_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_promotion_products_product (product_id)
) ENGINE=InnoDB;
