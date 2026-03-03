-- Migration: Add skills column to alumni_profiles table
-- Run this script if the alumni_profiles table is missing the skills column.
-- Uses IF NOT EXISTS so the statement is idempotent and safe to re-run.

ALTER TABLE `alumni_profiles`
    ADD COLUMN IF NOT EXISTS `skills` TEXT DEFAULT NULL
    COMMENT 'Comma-separated list of skills/competencies';
