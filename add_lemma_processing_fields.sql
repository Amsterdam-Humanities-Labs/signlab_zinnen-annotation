-- Add fields to track lemma processing status
ALTER TABLE sentences 
ADD COLUMN lemma_processed BOOLEAN DEFAULT FALSE COMMENT 'Whether AI lemma processing has been completed',
ADD COLUMN lemma_processed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When the lemma was last processed',
ADD COLUMN lemma_error TEXT DEFAULT NULL COMMENT 'Any error message from lemma processing',
ADD INDEX idx_lemma_processed (lemma_processed),
ADD INDEX idx_lemma_processed_at (lemma_processed_at);

-- Update existing sentences with lemmaList as already processed
UPDATE sentences 
SET lemma_processed = TRUE, 
    lemma_processed_at = NOW() 
WHERE lemmaList IS NOT NULL 
  AND lemmaList != '[]' 
  AND lemmaList != '';