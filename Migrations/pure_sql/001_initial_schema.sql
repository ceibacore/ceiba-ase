CREATE TABLE IF NOT EXISTS ase_plans (
    id CHAR(36) PRIMARY KEY,
    short_id CHAR(12) NOT NULL UNIQUE,
    slug VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_short_id (short_id)
);

CREATE TABLE IF NOT EXISTS ase_plan_prices (
    id CHAR(36) PRIMARY KEY,
    short_id CHAR(12) NOT NULL UNIQUE,
    plan_id CHAR(36) NOT NULL,
    type ENUM('recurring', 'one_time') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    `interval` ENUM('day', 'month', 'year') NULL,
    interval_count INT DEFAULT 1,
    trial_days INT DEFAULT 0,
    is_active BOOLEAN DEFAULT 1,
    FOREIGN KEY (plan_id) REFERENCES ase_plans(id) ON DELETE CASCADE,
    INDEX idx_short_id (short_id)
);

CREATE TABLE IF NOT EXISTS ase_custom_prices (
    id CHAR(36) PRIMARY KEY,
    short_id CHAR(12) NOT NULL UNIQUE,
    plan_price_id CHAR(36) NOT NULL,
    external_client_id VARCHAR(255) NOT NULL,
    custom_amount DECIMAL(10,2) NOT NULL,
    valid_until TIMESTAMP NULL,
    FOREIGN KEY (plan_price_id) REFERENCES ase_plan_prices(id) ON DELETE CASCADE,
    INDEX idx_short_id (short_id),
    UNIQUE INDEX idx_client_plan (external_client_id, plan_price_id)
);

CREATE TABLE IF NOT EXISTS ase_gateways (
    id CHAR(36) PRIMARY KEY,
    short_id CHAR(12) NOT NULL UNIQUE,
    provider VARCHAR(50) NOT NULL,
    credentials JSON NOT NULL,
    is_active BOOLEAN DEFAULT 1,
    INDEX idx_short_id (short_id)
);

CREATE TABLE IF NOT EXISTS ase_orders (
    id CHAR(36) PRIMARY KEY,
    short_id CHAR(12) NOT NULL UNIQUE,
    external_client_id VARCHAR(255) NOT NULL,
    plan_price_id CHAR(36) NOT NULL,
    gateway_id CHAR(36) NOT NULL,
    custom_price_id CHAR(36) NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    security_hash VARCHAR(255) NOT NULL,
    status ENUM('pending', 'paid', 'failed', 'expired', 'refunded') DEFAULT 'pending',
    expires_at TIMESTAMP NULL,
    external_order_id VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_price_id) REFERENCES ase_plan_prices(id),
    FOREIGN KEY (gateway_id) REFERENCES ase_gateways(id),
    FOREIGN KEY (custom_price_id) REFERENCES ase_custom_prices(id),
    INDEX idx_short_id (short_id),
    INDEX idx_client_status (external_client_id, status)
);

CREATE TABLE IF NOT EXISTS ase_subscriptions (
    id CHAR(36) PRIMARY KEY,
    short_id CHAR(12) NOT NULL UNIQUE,
    order_id CHAR(36) NOT NULL,
    external_client_id VARCHAR(255) NOT NULL,
    plan_price_id CHAR(36) NOT NULL,
    gateway_id CHAR(36) NOT NULL,
    external_subscription_id VARCHAR(255) NULL,
    status ENUM('pending', 'active', 'past_due', 'canceled', 'unpaid') NOT NULL,
    current_period_start TIMESTAMP NULL,
    current_period_end TIMESTAMP NULL,
    canceled_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES ase_orders(id),
    FOREIGN KEY (plan_price_id) REFERENCES ase_plan_prices(id),
    FOREIGN KEY (gateway_id) REFERENCES ase_gateways(id),
    INDEX idx_short_id (short_id),
    INDEX idx_client_status (external_client_id, status)
);

CREATE TABLE IF NOT EXISTS ase_invoices (
    id CHAR(36) PRIMARY KEY,
    short_id CHAR(12) NOT NULL UNIQUE,
    order_id CHAR(36) NOT NULL,
    subscription_id CHAR(36) NULL,
    external_client_id VARCHAR(255) NOT NULL,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    subtotal DECIMAL(10,2) NOT NULL,
    tax_amount DECIMAL(10,2) DEFAULT 0,
    total DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    period_start TIMESTAMP NULL,
    period_end TIMESTAMP NULL,
    status ENUM('draft', 'issued', 'paid', 'void') NOT NULL,
    issued_at TIMESTAMP NULL,
    due_at TIMESTAMP NULL,
    paid_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES ase_orders(id),
    FOREIGN KEY (subscription_id) REFERENCES ase_subscriptions(id),
    INDEX idx_short_id (short_id)
);

CREATE TABLE IF NOT EXISTS ase_transactions_log (
    id CHAR(36) PRIMARY KEY,
    short_id CHAR(12) NOT NULL UNIQUE,
    subscription_id CHAR(36) NULL,
    order_id CHAR(36) NULL,
    external_client_id VARCHAR(255) NOT NULL,
    gateway_id CHAR(36) NOT NULL,
    external_transaction_id VARCHAR(255) UNIQUE NULL,
    type ENUM('payment', 'refund', 'chargeback') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('success', 'failed', 'pending') NOT NULL,
    security_hash VARCHAR(255) NOT NULL,
    raw_payload JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subscription_id) REFERENCES ase_subscriptions(id),
    FOREIGN KEY (order_id) REFERENCES ase_orders(id),
    FOREIGN KEY (gateway_id) REFERENCES ase_gateways(id),
    INDEX idx_short_id (short_id),
    INDEX idx_client_status (external_client_id, status)
);
