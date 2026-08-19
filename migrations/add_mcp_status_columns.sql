-- Add MCP status columns to sentences table
-- Migration: add_mcp_status_columns
-- Date: 2026-01-21

ALTER TABLE sentences
ADD COLUMN mcp_status_postprocessing VARCHAR(255) DEFAULT NULL AFTER status_video,
ADD COLUMN mcp_status_tijd_annotatie VARCHAR(255) DEFAULT NULL AFTER mcp_status_postprocessing;

-- Verification query
-- Run this to verify the columns were added:
-- DESCRIBE sentences;
