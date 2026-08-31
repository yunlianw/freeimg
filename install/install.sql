-- ============================================================
-- FreeImg / 自由图床 - 完整数据库结构
-- MySQL 5.7+ 兼容（utf8mb4 / utf8mb4_general_ci）
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `access_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `image_id` bigint(20) unsigned NOT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `referer` varchar(255) DEFAULT NULL,
  `country` varchar(32) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_image_id` (`image_id`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `album_images`;
CREATE TABLE IF NOT EXISTS `album_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `album_id` int(10) unsigned NOT NULL,
  `image_id` bigint(20) unsigned NOT NULL,
  `sort` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_album_image` (`album_id`,`image_id`),
  KEY `idx_image_id` (`image_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `albums`;
CREATE TABLE IF NOT EXISTS `albums` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(128) NOT NULL,
  `description` varchar(512) DEFAULT NULL,
  `cover_image_id` bigint(20) unsigned DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT '0',
  `view_count` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_public` (`is_public`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `api_keys`;
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(64) NOT NULL,
  `access_key` varchar(64) NOT NULL,
  `secret_key_hash` varchar(255) NOT NULL,
  `scopes` varchar(255) DEFAULT NULL COMMENT 'upload,delete,read 等',
  `compression_profile_id` int(10) unsigned DEFAULT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_access_key` (`access_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_compression_profile` (`compression_profile_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `compression_profiles`;
CREATE TABLE IF NOT EXISTS `compression_profiles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `code` varchar(32) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `max_dimension` int(10) unsigned NOT NULL DEFAULT '1600' COMMENT '最大宽或高（0=不缩放）',
  `jpeg_quality` tinyint(3) unsigned NOT NULL DEFAULT '70' COMMENT 'JPEG 质量 1-100',
  `webp_quality` tinyint(3) unsigned NOT NULL DEFAULT '70' COMMENT 'WebP 质量 1-100',
  `png_compression` tinyint(3) unsigned NOT NULL DEFAULT '6' COMMENT 'PNG 压缩等级 0-9',
  `png_quality_min` tinyint(3) unsigned NOT NULL DEFAULT '40' COMMENT 'PNG pngquant quality 最小（用于 --quality=MIN-MAX）',
  `png_quality_max` tinyint(3) unsigned NOT NULL DEFAULT '80' COMMENT 'PNG pngquant quality 最大',
  `target_size_kb` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '目标文件大小（0=不限）',
  `minimum_quality` tinyint(3) unsigned NOT NULL DEFAULT '40' COMMENT '最低质量（防止压太多）',
  `strip_metadata` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=去除 EXIF 等元数据',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `is_builtin` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=系统预设（不可删）',
  `sort_order` int(11) NOT NULL DEFAULT '0',
  `output_format` varchar(16) NOT NULL DEFAULT 'auto' COMMENT '输出格式: auto/jpg/webp/png',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code` (`code`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `folders`;
CREATE TABLE IF NOT EXISTS `folders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `parent_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(128) NOT NULL,
  `path` varchar(512) NOT NULL,
  `share_token` varchar(64) DEFAULT NULL,
  `share_expires_at` datetime DEFAULT NULL,
  `share_password` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_share_token` (`share_token`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_path` (`path`(191)),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `images`;
CREATE TABLE IF NOT EXISTS `images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `storage_id` int(10) unsigned NOT NULL,
  `folder_id` int(10) unsigned DEFAULT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `extension` varchar(16) NOT NULL,
  `mime_type` varchar(64) NOT NULL,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `original_size` bigint(20) unsigned NOT NULL DEFAULT '0',
  `final_size` bigint(20) unsigned NOT NULL DEFAULT '0',
  `compression_ratio` decimal(5,2) DEFAULT '0.00',
  `original_mime` varchar(32) DEFAULT NULL COMMENT '原始文件真实 MIME（detectMime 检测）',
  `original_extension` varchar(16) DEFAULT NULL COMMENT '原始扩展名（取自上传文件名）',
  `compressor` varchar(16) DEFAULT NULL COMMENT '压缩器：pngquant / gd / cwebp / original',
  `compression` varchar(32) DEFAULT NULL COMMENT '压缩档代码：original / high / balanced / saver / extreme / ultra（force_recompress 时同 sha256 可多记录）',
  `compression_source` varchar(16) DEFAULT NULL COMMENT '压缩源：browser / api-server / none（未压缩）',
  `sha256` varchar(64) NOT NULL,
  `storage_path` varchar(512) NOT NULL,
  `public_url` varchar(512) NOT NULL,
  `status` enum('active','expired','recycle') NOT NULL DEFAULT 'active',
  `is_public` tinyint(1) NOT NULL DEFAULT '1',
  `tags` varchar(500) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  KEY `idx_sha256_user` (`sha256`,`user_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_folder_id` (`folder_id`),
  KEY `idx_status` (`status`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=112 DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `login_logs`;
CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `username` varchar(64) DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` enum('success','fail','locked') NOT NULL,
  `reason` varchar(128) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_ip_created` (`ip`,`created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `settings`;
CREATE TABLE IF NOT EXISTS `settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(64) NOT NULL,
  `value` text,
  `group` varchar(32) NOT NULL DEFAULT 'general',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_key` (`key`),
  KEY `idx_group` (`group`)
) ENGINE=InnoDB AUTO_INCREMENT=93 DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `shares`;
CREATE TABLE IF NOT EXISTS `shares` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(36) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `scope` enum('image','album') NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `type` enum('public','password','private') NOT NULL DEFAULT 'public',
  `password_hash` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `view_count` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uuid` (`uuid`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_target` (`scope`,`target_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `storages`;
CREATE TABLE IF NOT EXISTS `storages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `name` varchar(64) NOT NULL,
  `driver` enum('local','s3','cos','oss','obs','sftp') NOT NULL DEFAULT 'local',
  `config` text NOT NULL COMMENT '加密 JSON',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `priority` int(11) NOT NULL DEFAULT '0',
  `visible_in_upload` tinyint(1) NOT NULL DEFAULT '1',
  `max_capacity_mb` int(11) DEFAULT NULL,
  `current_usage_mb` decimal(12,4) NOT NULL DEFAULT '0.0000',
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_driver` (`driver`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `upload_logs`;
CREATE TABLE IF NOT EXISTS `upload_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `api_key_id` int(10) unsigned DEFAULT NULL,
  `image_id` bigint(20) unsigned DEFAULT NULL,
  `filename` varchar(255) NOT NULL,
  `size` bigint(20) unsigned NOT NULL DEFAULT '0',
  `status` enum('success','failed','rejected') NOT NULL,
  `error_msg` varchar(255) DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_api_key_id` (`api_key_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `session_token` varchar(64) NOT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `payload` text,
  `last_activity_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`session_token`),
  KEY `idx_user` (`user_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4;
-- (original) DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(32) NOT NULL,
  `email` varchar(128) NOT NULL,
  `password` varchar(255) NOT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `totp_backup_codes` text,
  `role` enum('admin','user') NOT NULL DEFAULT 'user',
  `avatar` varchar(255) DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `failed_login_count` int(11) NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `last_failed_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `password_history` text,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(64) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_status` (`status`),
  KEY `idx_deleted_at` (`deleted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4;


SET FOREIGN_KEY_CHECKS = 1;
