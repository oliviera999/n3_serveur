-- Table pour stocker les erreurs et détecter les répétitions
-- Créée automatiquement par ErrorAlertService si elle n'existe pas

CREATE TABLE IF NOT EXISTS error_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message TEXT NOT NULL,
    normalized_message VARCHAR(255) NOT NULL,
    context TEXT,
    created_at DATETIME NOT NULL,
    alerted_at DATETIME NULL,
    INDEX idx_normalized (normalized_message),
    INDEX idx_created (created_at),
    INDEX idx_alerted (alerted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
