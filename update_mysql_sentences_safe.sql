-- Check if columns exist before adding them
-- Run each ALTER statement separately if needed

-- Add lemma_processed column
ALTER TABLE sentences 
ADD COLUMN lemma_processed BOOLEAN DEFAULT FALSE COMMENT 'Whether AI lemma processing has been completed';

-- Add lemma_processed_at column
ALTER TABLE sentences 
ADD COLUMN lemma_processed_at TIMESTAMP NULL DEFAULT NULL COMMENT 'When the lemma was last processed';

-- Add lemma_error column
ALTER TABLE sentences 
ADD COLUMN lemma_error TEXT DEFAULT NULL COMMENT 'Any error message from lemma processing';

-- Add indexes for performance
ALTER TABLE sentences ADD INDEX idx_lemma_processed (lemma_processed);
ALTER TABLE sentences ADD INDEX idx_lemma_processed_at (lemma_processed_at);

-- Update existing sentences with lemmaList as already processed
UPDATE sentences 
SET lemma_processed = TRUE, 
    lemma_processed_at = NOW() 
WHERE lemmaList IS NOT NULL 
  AND lemmaList != '[]' 
  AND lemmaList != '';

-- Reset a few sentences for testing
UPDATE sentences 
SET lemma_processed = FALSE, 
    lemmaList = '[]',
    lemma_error = NULL
WHERE zinString IS NOT NULL 
  AND zinString != ''
ORDER BY id 
LIMIT 10;