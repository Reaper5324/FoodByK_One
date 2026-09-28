-- Food by K Database Migration. Run once against database.

CREATE TABLE roles (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO roles (role_name) VALUES ('customer'), ('staff'), ('admin');

CREATE TABLE users (
    id               INT           UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(150)  NOT NULL,
    email            VARCHAR(150)  NOT NULL UNIQUE,
    password_hash    VARCHAR(255)  NOT NULL,
    role_id          INT           UNSIGNED NOT NULL,
    profile_picture  VARCHAR(255)  DEFAULT NULL,
    phone            VARCHAR(20)   DEFAULT NULL,
    address          VARCHAR(255)  DEFAULT NULL,
    city             VARCHAR(100)  DEFAULT NULL,
    province         VARCHAR(100)  DEFAULT NULL,
    loyalty_points   INT UNSIGNED  NOT NULL DEFAULT 0,
    is_active        TINYINT(1)    NOT NULL DEFAULT 1,
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
    INDEX idx_users_role (role_id)
) ENGINE=InnoDB;

CREATE TABLE business_settings (
    id                     TINYINT       UNSIGNED PRIMARY KEY DEFAULT 1,
    business_lat           DECIMAL(9,6)  NOT NULL DEFAULT 0,
    business_long          DECIMAL(9,6)  NOT NULL DEFAULT 0,
    delivery_radius_km     DECIMAL(6,2)  NOT NULL DEFAULT 5.00,
    collection_radius_km   DECIMAL(6,2)  NOT NULL DEFAULT 15.00,
    delivery_fee           DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
    trading_hours_start    TIME          NOT NULL DEFAULT '09:00:00',
    trading_hours_end      TIME          NOT NULL DEFAULT '20:00:00',
    delivery_enabled       TINYINT(1)    NOT NULL DEFAULT 1,
    collection_enabled     TINYINT(1)    NOT NULL DEFAULT 1,
    slot_duration_minutes  INT UNSIGNED  NOT NULL DEFAULT 30,
    max_orders_per_slot    INT UNSIGNED  NOT NULL DEFAULT 10,
    updated_at             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_settings_singleton CHECK (id = 1)
) ENGINE=InnoDB;

INSERT INTO business_settings (id) VALUES (1);

CREATE TABLE categories (
    id             INT          UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(80)  NOT NULL UNIQUE,
    description    TEXT         DEFAULT NULL,
    display_order  INT          UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE products (
    id            INT            UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id   INT            UNSIGNED NOT NULL,
    name          VARCHAR(150)   NOT NULL,
    description   TEXT           NOT NULL,
    price         DECIMAL(8,2)   NOT NULL,
    is_available  TINYINT(1)     NOT NULL DEFAULT 1,
    status        ENUM('active','inactive','removed') NOT NULL DEFAULT 'active',
    image_url     VARCHAR(255)   DEFAULT NULL,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id),
    CONSTRAINT chk_products_price_nonnegative CHECK (price >= 0),
    INDEX idx_products_category (category_id),
    INDEX idx_products_status (status),
    FULLTEXT INDEX ft_products_search (name, description)
) ENGINE=InnoDB;

CREATE TABLE addresses (
    id            INT            UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT            UNSIGNED NOT NULL,
    raw_address   VARCHAR(500)   NOT NULL,
    street        VARCHAR(255)   DEFAULT NULL,
    postal_code   VARCHAR(20)    DEFAULT NULL,
    city          VARCHAR(100)   DEFAULT NULL,
    latitude      DECIMAL(9,6)   DEFAULT NULL,
    longitude     DECIMAL(9,6)   DEFAULT NULL,
    is_default    TINYINT(1)     NOT NULL DEFAULT 0,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_addresses_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT chk_addresses_coordinate_pair CHECK ((latitude IS NULL AND longitude IS NULL) OR (latitude IS NOT NULL AND longitude IS NOT NULL)),
    CONSTRAINT chk_addresses_latitude CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CONSTRAINT chk_addresses_longitude CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
    INDEX idx_addresses_customer (customer_id)
) ENGINE=InnoDB;

CREATE TABLE cart_items (
    id            INT       UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id   INT       UNSIGNED NOT NULL,
    product_id    INT       UNSIGNED NOT NULL,
    quantity      INT       UNSIGNED NOT NULL DEFAULT 1,
    unit_price    DECIMAL(8,2) DEFAULT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_cart_quantity_positive CHECK (quantity > 0),
    CONSTRAINT chk_cart_unit_price_nonnegative CHECK (unit_price IS NULL OR unit_price >= 0),
    CONSTRAINT fk_cart_customer FOREIGN KEY (customer_id) REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_cart_product  FOREIGN KEY (product_id)  REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_cart_item (customer_id, product_id)
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    id          INT          UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT          UNSIGNED NOT NULL,
    token_hash  VARCHAR(255) NOT NULL UNIQUE,
    expires_at  TIMESTAMP    NOT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_reset_user (user_id)
) ENGINE=InnoDB;

CREATE TABLE promotions (
    id              INT            UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(40)    NOT NULL UNIQUE,
    discount_type   ENUM('percentage','fixed_amount','buy_one_get_one','free_delivery') NOT NULL DEFAULT 'percentage',
    discount_value  DECIMAL(8,2)   NOT NULL,
    start_date      DATE           DEFAULT NULL,
    end_date        DATE           DEFAULT NULL,
    is_active       TINYINT(1)     NOT NULL DEFAULT 1,
    created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_promotions_discount_nonnegative CHECK (discount_value >= 0),
    CONSTRAINT chk_promotions_date_range CHECK (start_date IS NULL OR end_date IS NULL OR end_date >= start_date),
    INDEX idx_promotions_active (is_active, start_date, end_date)
) ENGINE=InnoDB;

CREATE TABLE orders (
    id                       INT            UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id              INT            UNSIGNED NOT NULL,
    staff_id                 INT            UNSIGNED DEFAULT NULL,
    fulfilment_type          ENUM('collection','delivery') NOT NULL,
    status                   ENUM(
                                 'submitted','accepted','adjusted','declined',
                                 'charge_pending','paid','payment_failed',
                                 'cancelled','preparing','ready','completed'
                             ) NOT NULL DEFAULT 'submitted',
    requested_window_start   DATETIME       NOT NULL,
    requested_window_end     DATETIME       NOT NULL,
    confirmed_window_start   DATETIME       DEFAULT NULL,
    confirmed_window_end     DATETIME       DEFAULT NULL,
    decline_reason           VARCHAR(255)   DEFAULT NULL,
    cancel_reason            VARCHAR(255)   DEFAULT NULL,
    promotion_id             INT            UNSIGNED DEFAULT NULL,
    locked_discount          DECIMAL(8,2)   NOT NULL DEFAULT 0.00,
    subtotal                 DECIMAL(10,2)  NOT NULL,
    address_id               INT            UNSIGNED DEFAULT NULL,
    distance_km              DECIMAL(6,2)   NOT NULL DEFAULT 0.00,
    delivery_fee             DECIMAL(8,2)   NOT NULL DEFAULT 0.00,
    created_at                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_customer   FOREIGN KEY (customer_id)  REFERENCES users(id),
    CONSTRAINT fk_orders_staff      FOREIGN KEY (staff_id)     REFERENCES users(id),
    CONSTRAINT fk_orders_address    FOREIGN KEY (address_id)   REFERENCES addresses(id),
    CONSTRAINT fk_orders_promotion  FOREIGN KEY (promotion_id) REFERENCES promotions(id),
    CONSTRAINT chk_orders_requested_window CHECK (requested_window_end > requested_window_start),
    CONSTRAINT chk_orders_confirmed_window CHECK (confirmed_window_start IS NULL OR confirmed_window_end IS NULL OR confirmed_window_end > confirmed_window_start),
    CONSTRAINT chk_orders_amounts_nonnegative CHECK (subtotal >= 0 AND locked_discount >= 0 AND delivery_fee >= 0),
    CONSTRAINT chk_orders_distance_nonnegative CHECK (distance_km >= 0),
    CONSTRAINT chk_orders_delivery_address CHECK (
        (fulfilment_type = 'delivery' AND address_id IS NOT NULL) OR
        (fulfilment_type = 'collection' AND address_id IS NULL)
    ),
    INDEX idx_orders_customer (customer_id),
    INDEX idx_orders_staff (staff_id),
    INDEX idx_orders_status (status),
    INDEX idx_orders_window_status (requested_window_start, status)
) ENGINE=InnoDB;

CREATE TABLE order_items (
    id            INT            UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id      INT            UNSIGNED NOT NULL,
    product_id    INT            UNSIGNED NOT NULL,
    product_name  VARCHAR(150)   NOT NULL,
    quantity      INT            UNSIGNED NOT NULL,
    unit_price    DECIMAL(8,2)   NOT NULL,
    CONSTRAINT fk_items_order   FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
    CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id),
    CONSTRAINT chk_order_items_quantity_positive CHECK (quantity > 0),
    CONSTRAINT chk_order_items_price_nonnegative CHECK (unit_price >= 0),
    INDEX idx_items_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE payments (
    id                 INT            UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id           INT            UNSIGNED NOT NULL UNIQUE,
    gateway            VARCHAR(30)    NOT NULL DEFAULT 'payfast',
    gateway_token      VARCHAR(512)   DEFAULT NULL,
    gateway_reference  VARCHAR(255)   DEFAULT NULL,
    amount             DECIMAL(10,2)  NOT NULL,
    status             ENUM('tokenized','charge_pending','success','failed','voided') NOT NULL DEFAULT 'tokenized',
    created_at         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    charged_at         TIMESTAMP      DEFAULT NULL,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT chk_payments_amount_nonnegative CHECK (amount >= 0),
    INDEX idx_payments_status (status),
    INDEX idx_payments_gateway_ref (gateway_reference)
) ENGINE=InnoDB;

CREATE TABLE order_status_history (
    id           INT          UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id     INT          UNSIGNED NOT NULL,
    from_status  VARCHAR(30)  NOT NULL,
    to_status    VARCHAR(30)  NOT NULL,
    changed_by   INT          UNSIGNED DEFAULT NULL,
    notes        VARCHAR(255) DEFAULT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_osh_order FOREIGN KEY (order_id)   REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_osh_user  FOREIGN KEY (changed_by) REFERENCES users(id),
    INDEX idx_osh_order (order_id)
) ENGINE=InnoDB;

CREATE TABLE login_attempts (
    id           INT       UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(100) NOT NULL,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_identifier_time (identifier, attempted_at),
    INDEX idx_login_attempts_time (attempted_at)
) ENGINE=InnoDB;
