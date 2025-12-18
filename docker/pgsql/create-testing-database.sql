-- Ensure pgvector is enabled in the default (main) database.
CREATE EXTENSION IF NOT EXISTS vector;

-- Create a dedicated DB for automated tests (used by phpunit.xml).
CREATE DATABASE marketking_testing;
\c marketking_testing;
CREATE EXTENSION IF NOT EXISTS vector;

-- Backwards-compat: keep the old "testing" DB too.
CREATE DATABASE testing;
\c testing;
CREATE EXTENSION IF NOT EXISTS vector;

