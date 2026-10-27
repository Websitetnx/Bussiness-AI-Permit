-- Migration 012: Add profile picture support for users
ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL DEFAULT NULL AFTER role;
