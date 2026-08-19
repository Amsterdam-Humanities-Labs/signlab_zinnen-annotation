-- Create lemmaTable for storing unique lemmas with auto-increment IDs
CREATE TABLE IF NOT EXISTS lemmaTable (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lemma VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lemma (lemma)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;