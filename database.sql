CREATE DATABASE IF NOT EXISTS segno_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE segno_db;

CREATE TABLE IF NOT EXISTS segno_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS segno_product (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL UNIQUE,
    category VARCHAR(80) NOT NULL,
    kind VARCHAR(100) NOT NULL,
    level VARCHAR(120) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS segno_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'placed',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_segno_orders_user
        FOREIGN KEY (user_id) REFERENCES segno_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS segno_order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    product_title VARCHAR(180) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_segno_order_items_order
        FOREIGN KEY (order_id) REFERENCES segno_orders(id),
    CONSTRAINT fk_segno_order_items_product
        FOREIGN KEY (product_id) REFERENCES segno_product(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS segno_inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    message TEXT NOT NULL,
    email_status ENUM('pending', 'accepted', 'failed') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email_accepted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_segno_inquiries_status_created (email_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO segno_product (title, category, kind, level, price) VALUES
    ('Playing God by Polyphia', 'Marimba', 'Solo arrangement', 'Advanced · 4 mallets', 18.00),
    ('Mia and Sebastian''s Theme by Justin Hurwitz', 'Marimba', 'Solo arrangement', 'Intermediate · 4 mallets', 20.00),
    ('Truman Sleeps by Philip Glass', 'Marimba', 'Original composition', 'Intermediate · 4 mallets', 16.00),
    ('Rico Blanco Medley', 'Drumline', 'Original composition', 'Open class · 8 voices', 42.00),
    ('Scharton', 'Drumline', 'Battery arrangement', 'World class · 11 voices', 38.00),
    ('What We Carry', 'Percussion Ensemble', 'Ensemble arrangement', 'Advanced · 19 players', 34.00),
    ('Kalapastangan by Fitterkarma', 'Percussion Ensemble', 'Ensemble arrangement', 'Intermediate · 22 players', 29.00),
    ('Blue by Yung Kai', 'Percussion Ensemble', 'Ensemble arrangement', 'Flexible · 10-15 players', 32.00),
    ('Mapa by SB19', 'Percussion Ensemble', 'Ensemble arrangement', 'Advanced · 15 players', 36.00),
    ('Moira medley', 'Percussion Ensemble', 'Ensemble arrangement', 'Intermediate · 15 players', 30.00),
    ('Who Knows by Daniel Caesar', 'Percussion Ensemble', 'Ensemble arrangement', 'Expert · 20 players', 20.00);
