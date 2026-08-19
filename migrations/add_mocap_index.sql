-- Add composite index for motion capture filtering optimization
-- Migration: add_mocap_index
-- Date: 2026-01-21

-- This index covers all columns used in motion capture filtering:
-- zOg, added, has_mocap, m_transcription
-- Order is optimized for the most selective columns first

-- Note: m_transcription is TEXT storing integer IDs, so we use a prefix length of 20
CREATE INDEX idx_mocap_filter
ON matched_transcriptions(zOg, added, has_mocap, m_transcription(20));

-- Verification query:
-- SHOW INDEX FROM matched_transcriptions WHERE Key_name = 'idx_mocap_filter';
