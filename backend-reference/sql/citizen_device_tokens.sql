-- Reference schema for CENTRIX device biometric authentication.
-- Deploy on your MySQL server alongside the existing users table.

CREATE TABLE IF NOT EXISTS citizen_device_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device_id VARCHAR(64) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    device_label VARCHAR(120) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_device_id (device_id),
    KEY idx_user_id (user_id),
    KEY idx_expires_at (expires_at),
    CONSTRAINT fk_citizen_device_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
