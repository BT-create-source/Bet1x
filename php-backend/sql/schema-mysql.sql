-- =================================================================================================
-- bet1x — MySQL / MariaDB schema (COMPLETE: core tables + every migration 002-018, in one file)
-- =================================================================================================
--
-- For hosts whose cPanel offers only MySQL (e.g. GoDaddy). Requires MySQL 8.0.13+ or MariaDB 10.3+.
-- Import it ONCE into an empty database: phpMyAdmin -> select the database -> Import (or the SQL
-- tab), or:   mysql -u DBUSER -p DBNAME < php-backend/sql/schema-mysql.sql
-- Then set DB_DRIVER=mysql in php-backend/.env. Do NOT also run schema-postgres.sql or any
-- migration-0XX file — everything they create is already in here.
--
-- Optional, after this file: sql/schema-mysql-protect.sql (triggers that make the sub-admin money
-- ledgers undeletable at the database level, as the Postgres build does). Some shared hosts refuse
-- CREATE TRIGGER; the application never deletes those rows either way.
--
-- How this maps the Postgres schema — keep the two in step when either changes:
--
--   * Same table and column names. The application's SQL is written once (Postgres dialect with
--     "double-quoted" identifiers) and lib/db.php puts each MySQL session in ANSI_QUOTES mode, so
--     the same strings run on both. This file uses `backticks` only so it imports in any client.
--
--   * Every table is utf8mb4 with the utf8mb4_bin collation: comparisons are case-SENSITIVE, exactly
--     like Postgres. The app does every case-insensitive lookup explicitly with LOWER(x) = LOWER(?).
--
--   * Postgres's expression indexes (UNIQUE on LOWER("username"), partial UNIQUE indexes) become
--     STORED generated columns with a plain UNIQUE key: `username_lower`, `email_lower`
--     (NULL for an empty email, so many blank emails are allowed) and SubAdminCash.`reference_key`.
--     They are maintained by the database; nothing in the application reads or writes them.
--
--   * Postgres's updated_at triggers -> ON UPDATE CURRENT_TIMESTAMP(3), on the same five tables.
--
--   * TEXT columns that are keys or indexed are VARCHAR(191) (values are ids, keys and usernames,
--     all far shorter); free text stays TEXT; JSON-ish blobs are MEDIUMTEXT/LONGTEXT or JSON.
--     Money stays DOUBLE (Postgres DOUBLE PRECISION) and NUMERIC(p,s) stays DECIMAL(p,s), so every
--     balance rounds exactly as it does on Postgres.
--
--   * "DbLock" is MySQL-only: lib/db.php locks one of its rows where Postgres takes an advisory lock.
-- =================================================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------------------------------------------------
-- Core account + ledger
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `User` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(191) NOT NULL,
  `email`            VARCHAR(255) NOT NULL,
  `password`         VARCHAR(255) NOT NULL,
  `wallet_balance`   DOUBLE NOT NULL DEFAULT 1000,
  `created_at`       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `signup_ip`        VARCHAR(45) NULL DEFAULT NULL,                -- migration 002
  `bonus_credited`   SMALLINT NOT NULL DEFAULT 0,                  -- migration 002
  `phone`            VARCHAR(64) NULL,                             -- migration 003
  `phone_verified`   SMALLINT NOT NULL DEFAULT 0,                  -- migration 003
  `referral_code`    VARCHAR(64) NULL,                             -- migration 005
  `referred_by`      VARCHAR(191) NULL,                            -- migration 005
  `referral_balance` DOUBLE NOT NULL DEFAULT 0,                    -- migration 005
  `username_lower`   VARCHAR(191) GENERATED ALWAYS AS (LOWER(`username`)) STORED,          -- migration 015
  `email_lower`      VARCHAR(255) GENERATED ALWAYS AS (NULLIF(LOWER(`email`), '')) STORED, -- migration 015
  PRIMARY KEY (`id`),
  UNIQUE KEY `User_username_key` (`username`),
  UNIQUE KEY `User_username_lower_key` (`username_lower`),
  UNIQUE KEY `User_email_lower_key` (`email_lower`),
  UNIQUE KEY `User_phone_key` (`phone`),
  UNIQUE KEY `User_referral_code_key` (`referral_code`),
  KEY `User_signup_ip_idx` (`signup_ip`),
  KEY `User_referred_by_idx` (`referred_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `Transaction` (
  `id`        VARCHAR(191) NOT NULL,
  `user`      VARCHAR(191) NOT NULL,
  `type`      VARCHAR(191) NOT NULL,
  `amount`    DOUBLE NOT NULL,
  `details`   TEXT NOT NULL,
  `status`    VARCHAR(191) NOT NULL,
  `timestamp` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `Transaction_user_idx` (`user`),
  KEY `Transaction_timestamp_idx` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `Deposit` (
  `deposit_id`     VARCHAR(191) NOT NULL,
  `order_id`       VARCHAR(191) NULL,
  `username`       VARCHAR(191) NOT NULL,
  `amount`         DOUBLE NOT NULL,
  `utr`            TEXT NULL,
  `qr_type`        TEXT NULL,
  `custom_qr_data` MEDIUMTEXT NULL,
  `status`         VARCHAR(191) NOT NULL,
  `gateway`        TEXT NULL,
  `gateway_id`     TEXT NULL,
  `created_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`deposit_id`),
  UNIQUE KEY `Deposit_order_id_key` (`order_id`),
  KEY `Deposit_username_idx` (`username`),
  KEY `Deposit_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `Withdrawal` (
  `withdrawal_id` VARCHAR(191) NOT NULL,
  `username`      VARCHAR(191) NOT NULL,
  `amount`        DOUBLE NOT NULL,
  `method`        TEXT NOT NULL,
  `details`       TEXT NOT NULL,
  `status`        VARCHAR(191) NOT NULL,
  `created_at`    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`withdrawal_id`),
  KEY `Withdrawal_username_idx` (`username`),
  KEY `Withdrawal_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `PaymentLog` (
  `id`        VARCHAR(191) NOT NULL,
  `payload`   JSON NOT NULL,
  `signature` TEXT NULL,
  `timestamp` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Generic key/JSON store: colour-round state, rig config and ledgers, Aviator runtime, schema flags.
CREATE TABLE IF NOT EXISTS `GameState` (
  `key`       VARCHAR(191) NOT NULL,
  `data`      JSON NOT NULL,
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `RecentResult` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `room`        VARCHAR(191) NOT NULL,
  `roundNumber` VARCHAR(191) NOT NULL,
  `number`      INT NOT NULL,
  `color`       VARCHAR(191) NOT NULL,
  `dotClass`    VARCHAR(191) NOT NULL,
  `size`        VARCHAR(191) NOT NULL,
  `timestamp`   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `RecentResult_room_roundNumber_key` (`room`, `roundNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `ChatMessage` (
  `id`        INT NOT NULL AUTO_INCREMENT,
  `username`  VARCHAR(191) NOT NULL,
  `message`   TEXT NOT NULL,
  `timestamp` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `ChatMessage_timestamp_idx` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Teen Patti
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `TeenPattiRoom` (
  `id`               VARCHAR(191) NOT NULL,
  `name`             VARCHAR(191) NOT NULL,
  `boot_amount`      DOUBLE NOT NULL DEFAULT 10,
  `status`           VARCHAR(191) NOT NULL DEFAULT 'waiting',
  `pot`              DOUBLE NOT NULL DEFAULT 0,
  `current_stake`    DOUBLE NOT NULL DEFAULT 10,
  `turn_index`       INT NOT NULL DEFAULT 0,
  `turn_seat`        INT NULL,
  `turn_start`       DATETIME(3) NULL,
  `round`            INT NOT NULL DEFAULT 1,
  `winner_seat`      INT NULL,
  `deck_state`       JSON NULL,
  `admin_rig`        JSON NULL,
  `log`              JSON NOT NULL DEFAULT ('[]'),
  `created_at`       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  `bot_turn_due_at`  BIGINT NULL,
  `round_end_due_at` BIGINT NULL,
  `bot_fill_due_at`  BIGINT NULL,
  PRIMARY KEY (`id`),
  KEY `TeenPattiRoom_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `TeenPattiSeat` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `room_id`      VARCHAR(191) NOT NULL,
  `seat`         INT NOT NULL,
  `username`     VARCHAR(191) NULL,
  `is_bot`       SMALLINT NOT NULL DEFAULT 0,
  `cards`        JSON NULL,
  `folded`       SMALLINT NOT NULL DEFAULT 0,
  `balance`      DOUBLE NOT NULL DEFAULT 0,
  `seen`         SMALLINT NOT NULL DEFAULT 0,
  `last_seen_at` BIGINT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `TeenPattiSeat_room_id_seat_key` (`room_id`, `seat`),
  KEY `TeenPattiSeat_username_idx` (`username`),
  CONSTRAINT `TeenPattiSeat_room_id_fkey` FOREIGN KEY (`room_id`) REFERENCES `TeenPattiRoom` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `GameBet` (
  `id`         VARCHAR(191) NOT NULL,
  `username`   VARCHAR(191) NOT NULL,
  `game`       VARCHAR(191) NOT NULL,
  `bet_amount` DOUBLE NOT NULL,
  `payout`     DOUBLE NOT NULL DEFAULT 0,
  `status`     VARCHAR(191) NOT NULL DEFAULT 'active',
  `metadata`   JSON NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `settled_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  KEY `GameBet_username_game_idx` (`username`, `game`),
  KEY `GameBet_game_status_idx` (`game`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Runtime tables (state the Node process kept in memory)
-- -------------------------------------------------------------------------------------------------

-- One live Mines board per player; the UNIQUE username is what stops a double-click taking two stakes.
CREATE TABLE IF NOT EXISTS `MinesSession` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(191) NOT NULL,
  `status`           VARCHAR(191) NOT NULL,
  `bet_amount`       DOUBLE NOT NULL DEFAULT 0,
  `mines_count`      INT NOT NULL DEFAULT 3,
  `server_seed`      TEXT NULL,
  `seed_hash`        TEXT NULL,
  `mine_positions`   JSON NULL,
  `revealed`         JSON NULL,
  `multiplier`       DOUBLE NOT NULL DEFAULT 1,
  `potential_payout` DOUBLE NOT NULL DEFAULT 0,
  `updated_at`       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `MinesSession_username_key` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `LivePresence` (
  `id`        INT NOT NULL AUTO_INCREMENT,
  `game`      VARCHAR(191) NOT NULL,
  `kind`      VARCHAR(191) NOT NULL,
  `subject`   VARCHAR(191) NOT NULL,
  `last_seen` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `LivePresence_game_kind_subject_key` (`game`, `kind`, `subject`),
  KEY `LivePresence_last_seen_idx` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `RigAudit` (
  `id`             BIGINT NOT NULL AUTO_INCREMENT,
  `ts`             BIGINT NOT NULL,
  `game`           VARCHAR(191) NOT NULL,
  `instance`       VARCHAR(191) NULL,
  `round`          VARCHAR(191) NULL,
  `configured_pct` DOUBLE NOT NULL DEFAULT 0,
  `rigged`         SMALLINT NOT NULL DEFAULT 0,
  `eligible`       SMALLINT NOT NULL DEFAULT 1,
  `live`           INT NULL,
  `targeted`       INT NULL,
  `house_profit`   DOUBLE NULL,
  `note`           VARCHAR(200) NULL,
  PRIMARY KEY (`id`),
  KEY `RigAudit_game_ts_idx` (`game`, `ts`),
  KEY `RigAudit_ts_idx` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `RateLimit` (
  `id`           BIGINT NOT NULL AUTO_INCREMENT,
  `bucket`       VARCHAR(191) NOT NULL,
  `client`       VARCHAR(191) NOT NULL,
  `window_start` BIGINT NOT NULL,
  `hits`         INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `RateLimit_bucket_client_window_key` (`bucket`, `client`, `window_start`),
  KEY `RateLimit_window_start_idx` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- MySQL-only: the rows lib/db.php's db_xact_lock() locks in place of Postgres advisory locks.
CREATE TABLE IF NOT EXISTS `DbLock` (
  `name` VARCHAR(191) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Migrations 003 / 004 — phone and email verification codes
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `PhoneOtp` (
  `phone`        VARCHAR(64) NOT NULL,
  `otp_hash`     TEXT NOT NULL,
  `expires_at`   BIGINT NOT NULL,
  `attempts`     SMALLINT NOT NULL DEFAULT 0,
  `last_sent_at` BIGINT NOT NULL,
  `sent_count`   SMALLINT NOT NULL DEFAULT 0,
  `window_start` BIGINT NOT NULL,
  `verified_at`  BIGINT NULL,
  PRIMARY KEY (`phone`),
  KEY `PhoneOtp_expires_at_idx` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `EmailOtp` (
  `email`        VARCHAR(255) NOT NULL,
  `otp_hash`     TEXT NOT NULL,
  `expires_at`   BIGINT NOT NULL,
  `attempts`     SMALLINT NOT NULL DEFAULT 0,
  `last_sent_at` BIGINT NOT NULL,
  `sent_count`   SMALLINT NOT NULL DEFAULT 0,
  `window_start` BIGINT NOT NULL,
  `verified_at`  BIGINT NULL,
  PRIMARY KEY (`email`),
  KEY `EmailOtp_expires_at_idx` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Migration 005 — referrals
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ReferralCommission` (
  `id`                VARCHAR(191) NOT NULL,
  `inviter_username`  VARCHAR(191) NOT NULL,
  `referred_username` VARCHAR(191) NOT NULL,
  `source_deposit_id` VARCHAR(191) NOT NULL,
  `deposit_amount`    DOUBLE NOT NULL,
  `commission_rate`   DOUBLE NOT NULL,
  `commission_amount` DOUBLE NOT NULL,
  `created_at`        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `claimed_at`        DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  KEY `ReferralCommission_inviter_idx` (`inviter_username`),
  KEY `ReferralCommission_referred_idx` (`referred_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Migrations 006-010 — Your 11 (fantasy)
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `fantasy_matches` (
  `id`                   INT NOT NULL AUTO_INCREMENT,
  `external_key`         VARCHAR(191) NOT NULL,
  `series_name`          TEXT NOT NULL DEFAULT (''),
  `match_title`          TEXT NOT NULL DEFAULT (''),
  `team_a`               TEXT NOT NULL DEFAULT (''),
  `team_b`               TEXT NOT NULL DEFAULT (''),
  `team_a_short`         TEXT NOT NULL DEFAULT (''),
  `team_b_short`         TEXT NOT NULL DEFAULT (''),
  `team_a_logo`          TEXT NULL,
  `team_b_logo`          TEXT NULL,
  `format`               VARCHAR(32) NOT NULL DEFAULT 'T20',
  `venue`                TEXT NULL,
  `start_time`           DATETIME(3) NOT NULL,
  `lock_time`            DATETIME(3) NOT NULL,
  `status`               VARCHAR(32) NOT NULL DEFAULT 'UPCOMING',
  `scorecard_source_url` TEXT NULL,
  `squads_ready`         SMALLINT NOT NULL DEFAULT 0,
  `last_synced_at`       DATETIME(3) NULL,
  `created_at`           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `source_state`         TEXT NULL,                                -- migration 008
  `source_status_text`   TEXT NULL,                                -- migration 008
  `lineups_announced`    SMALLINT NOT NULL DEFAULT 0,              -- migration 010
  `feed_key`             VARCHAR(191) NULL,                        -- migration 010
  `toss_text`            TEXT NULL,                                -- migration 010
  PRIMARY KEY (`id`),
  UNIQUE KEY `fantasy_matches_external_key_key` (`external_key`),
  KEY `fantasy_matches_status_idx` (`status`),
  KEY `fantasy_matches_start_time_idx` (`start_time`),
  CONSTRAINT `fantasy_matches_status_chk` CHECK (`status` IN ('UPCOMING', 'LIVE', 'SETTLED', 'CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fantasy_players` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `match_id`       INT NOT NULL,
  `external_key`   VARCHAR(191) NOT NULL,
  `name`           TEXT NOT NULL,
  `full_name`      TEXT NOT NULL DEFAULT (''),
  `team_name`      TEXT NOT NULL DEFAULT (''),
  `role`           VARCHAR(8) NOT NULL DEFAULT 'BAT',
  `credits`        DECIMAL(4,1) NOT NULL DEFAULT 8.0,
  `is_playing`     SMALLINT NULL,
  `created_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `is_substitute`  SMALLINT NOT NULL DEFAULT 0,                    -- migration 010
  `credits_locked` SMALLINT NOT NULL DEFAULT 0,                    -- migration 010
  PRIMARY KEY (`id`),
  UNIQUE KEY `fantasy_players_match_key_key` (`match_id`, `external_key`),
  KEY `fantasy_players_match_idx` (`match_id`),
  CONSTRAINT `fantasy_players_match_fk` FOREIGN KEY (`match_id`) REFERENCES `fantasy_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fantasy_players_role_chk` CHECK (`role` IN ('WK', 'BAT', 'ALL', 'BOWL')),
  CONSTRAINT `fantasy_players_credits_chk` CHECK (`credits` >= 5.0 AND `credits` <= 11.5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fantasy_contests` (
  `id`                   INT NOT NULL AUTO_INCREMENT,
  `match_id`             INT NOT NULL,
  `title`                TEXT NOT NULL,
  `entry_fee`            DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total_spots`          INT NOT NULL DEFAULT 0,
  `filled_spots`         INT NOT NULL DEFAULT 0,
  `prize_pool`           DECIMAL(14,2) NOT NULL DEFAULT 0,
  `prize_rules`          MEDIUMTEXT NOT NULL DEFAULT ('[]'),
  `rake_pct`             DECIMAL(5,2) NOT NULL DEFAULT 0,
  `status`               VARCHAR(32) NOT NULL DEFAULT 'OPEN',
  `created_at`           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `min_entries`          INT NOT NULL DEFAULT 0,                   -- migration 009
  `max_entries_per_user` INT NOT NULL DEFAULT 1,                   -- migration 010
  `contest_type`         VARCHAR(64) NOT NULL DEFAULT 'custom',    -- migration 010
  `is_guaranteed`        SMALLINT NOT NULL DEFAULT 0,              -- migration 010
  `template_key`         VARCHAR(191) NULL,                        -- migration 010
  PRIMARY KEY (`id`),
  UNIQUE KEY `fantasy_contests_template_once` (`match_id`, `template_key`),
  KEY `fantasy_contests_match_idx` (`match_id`),
  CONSTRAINT `fantasy_contests_match_fk` FOREIGN KEY (`match_id`) REFERENCES `fantasy_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fantasy_contests_status_chk` CHECK (`status` IN ('OPEN', 'LOCKED', 'SETTLED', 'CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fantasy_user_teams` (
  `id`                     INT NOT NULL AUTO_INCREMENT,
  `user_id`                INT NOT NULL,
  `match_id`               INT NOT NULL,
  `team_name`              TEXT NOT NULL DEFAULT (''),
  `captain_player_id`      INT NOT NULL,
  `vice_captain_player_id` INT NOT NULL,
  `total_credits`          DECIMAL(5,1) NOT NULL DEFAULT 0,
  `total_points`           DECIMAL(10,2) NOT NULL DEFAULT 0,
  `rank`                   INT NULL,
  `created_at`             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `fantasy_user_teams_user_idx` (`user_id`),
  KEY `fantasy_user_teams_match_idx` (`match_id`),
  CONSTRAINT `fantasy_user_teams_user_fk` FOREIGN KEY (`user_id`) REFERENCES `User` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fantasy_user_teams_match_fk` FOREIGN KEY (`match_id`) REFERENCES `fantasy_matches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fantasy_team_players` (
  `id`           INT NOT NULL AUTO_INCREMENT,
  `user_team_id` INT NOT NULL,
  `player_id`    INT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fantasy_team_players_unique` (`user_team_id`, `player_id`),
  KEY `fantasy_team_players_team_idx` (`user_team_id`),
  CONSTRAINT `fantasy_team_players_team_fk` FOREIGN KEY (`user_team_id`) REFERENCES `fantasy_user_teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fantasy_team_players_player_fk` FOREIGN KEY (`player_id`) REFERENCES `fantasy_players` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fantasy_contest_entries` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `contest_id`     INT NOT NULL,
  `user_team_id`   INT NOT NULL,
  `user_id`        INT NOT NULL,
  `entry_fee_paid` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `txn_id`         VARCHAR(191) NULL,
  `points`         DECIMAL(10,2) NOT NULL DEFAULT 0,
  `rank`           INT NULL,
  `prize_won`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `is_settled`     SMALLINT NOT NULL DEFAULT 0,
  `settled_at`     DATETIME(3) NULL,
  `created_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `fantasy_entries_unique` (`contest_id`, `user_team_id`),
  KEY `fantasy_entries_contest_idx` (`contest_id`),
  KEY `fantasy_entries_user_idx` (`user_id`),
  CONSTRAINT `fantasy_entries_contest_fk` FOREIGN KEY (`contest_id`) REFERENCES `fantasy_contests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fantasy_entries_team_fk` FOREIGN KEY (`user_team_id`) REFERENCES `fantasy_user_teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fantasy_entries_user_fk` FOREIGN KEY (`user_id`) REFERENCES `User` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fantasy_player_live_stats` (
  `id`                        INT NOT NULL AUTO_INCREMENT,
  `match_id`                  INT NOT NULL,
  `player_id`                 INT NOT NULL,
  `runs`                      INT NOT NULL DEFAULT 0,
  `balls`                     INT NOT NULL DEFAULT 0,
  `fours`                     INT NOT NULL DEFAULT 0,
  `sixes`                     INT NOT NULL DEFAULT 0,
  `wickets`                   INT NOT NULL DEFAULT 0,
  `overs`                     DECIMAL(5,1) NOT NULL DEFAULT 0,
  `maidens`                   INT NOT NULL DEFAULT 0,
  `runs_conceded`             INT NOT NULL DEFAULT 0,
  `bowled_lbw`                INT NOT NULL DEFAULT 0,
  `catches`                   INT NOT NULL DEFAULT 0,
  `stumpings`                 INT NOT NULL DEFAULT 0,
  `runouts_direct`            INT NOT NULL DEFAULT 0,
  `runouts_shared`            INT NOT NULL DEFAULT 0,
  `is_out`                    SMALLINT NOT NULL DEFAULT 0,
  `did_bat`                   SMALLINT NOT NULL DEFAULT 0,
  `calculated_fantasy_points` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `updated_at`                DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `dot_balls`                 INT NOT NULL DEFAULT 0,              -- migration 010
  `balls_bowled`              INT NOT NULL DEFAULT 0,              -- migration 010
  `in_lineup`                 SMALLINT NOT NULL DEFAULT 0,         -- migration 010
  `is_substitute`             SMALLINT NOT NULL DEFAULT 0,         -- migration 010
  `breakdown`                 MEDIUMTEXT NOT NULL DEFAULT ('{}'),  -- migration 010
  PRIMARY KEY (`id`),
  UNIQUE KEY `fantasy_live_stats_unique` (`match_id`, `player_id`),
  KEY `fantasy_live_stats_match_idx` (`match_id`),
  CONSTRAINT `fantasy_live_stats_match_fk` FOREIGN KEY (`match_id`) REFERENCES `fantasy_matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fantasy_live_stats_player_fk` FOREIGN KEY (`player_id`) REFERENCES `fantasy_players` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fantasy_sync_log` (
  `id`     INT NOT NULL AUTO_INCREMENT,
  `worker` VARCHAR(191) NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'ok',
  `detail` TEXT NULL,
  `ran_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `fantasy_sync_log_worker_idx` (`worker`, `ran_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `fantasy_player_history` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `player_key` VARCHAR(191) NOT NULL,
  `match_id`   INT NOT NULL,
  `format`     VARCHAR(32) NOT NULL DEFAULT 'T20',
  `points`     DECIMAL(10,2) NOT NULL DEFAULT 0,
  `played_at`  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `fantasy_player_history_once` (`player_key`, `match_id`),
  KEY `fantasy_player_history_key_idx` (`player_key`, `played_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Migrations 010 + 014 — the shared cricket feed and Ball by Ball
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `cricket_feed_raw` (
  `id`          BIGINT NOT NULL AUTO_INCREMENT,
  `match_key`   VARCHAR(191) NOT NULL,
  `body_sha`    VARCHAR(128) NOT NULL,
  `body`        LONGTEXT NOT NULL,
  `source`      VARCHAR(32) NOT NULL DEFAULT 'push',
  `received_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `cricket_feed_raw_dedup` (`match_key`, `body_sha`),
  KEY `cricket_feed_raw_match_idx` (`match_key`, `received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `cricket_deliveries` (
  `id`              BIGINT NOT NULL AUTO_INCREMENT,
  `match_key`       VARCHAR(191) NOT NULL,
  `ball_uid`        VARCHAR(191) NOT NULL,
  `seq`             BIGINT NOT NULL,
  `innings`         INT NOT NULL,
  `over_no`         INT NOT NULL,
  `ball_no`         INT NOT NULL,
  `is_legal`        SMALLINT NOT NULL DEFAULT 1,
  `is_super_over`   SMALLINT NOT NULL DEFAULT 0,
  `batting_team`    TEXT NULL,
  `batsman_key`     TEXT NULL,
  `non_striker_key` TEXT NULL,
  `bowler_key`      TEXT NULL,
  `batsman_runs`    INT NOT NULL DEFAULT 0,
  `extra_type`      TEXT NULL,
  `extra_runs`      INT NOT NULL DEFAULT 0,
  `is_boundary`     SMALLINT NOT NULL DEFAULT 0,
  `is_wicket`       SMALLINT NOT NULL DEFAULT 0,
  `wicket_type`     TEXT NULL,
  `out_player_key`  TEXT NULL,
  `fielders`        MEDIUMTEXT NOT NULL DEFAULT ('[]'),
  `commentary`      MEDIUMTEXT NULL,
  `first_seen_at`   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `fp`              VARCHAR(191) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `cricket_deliveries_uid` (`match_key`, `ball_uid`),
  KEY `cricket_deliveries_seq_idx` (`match_key`, `seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `cricket_match_feed` (
  `match_key`    VARCHAR(191) NOT NULL,
  `format`       VARCHAR(32) NOT NULL DEFAULT 'T20',
  `title`        TEXT NOT NULL DEFAULT (''),
  `team_a_key`   TEXT NULL,
  `team_b_key`   TEXT NULL,
  `team_a`       TEXT NOT NULL DEFAULT (''),
  `team_b`       TEXT NOT NULL DEFAULT (''),
  `team_a_short` TEXT NOT NULL DEFAULT (''),
  `team_b_short` TEXT NOT NULL DEFAULT (''),
  `start_time`   DATETIME(3) NULL,
  `status`       VARCHAR(64) NOT NULL DEFAULT 'not_started',
  `status_text`  TEXT NULL,
  `toss`         MEDIUMTEXT NOT NULL DEFAULT ('{}'),
  `lineups`      MEDIUMTEXT NOT NULL DEFAULT ('{}'),
  `players`      MEDIUMTEXT NOT NULL DEFAULT ('{}'),
  `result_text`  TEXT NULL,
  `last_feed_at` DATETIME(3) NULL,
  `last_ball_at` DATETIME(3) NULL,
  `stalled`      SMALLINT NOT NULL DEFAULT 0,
  `updated_at`   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `meta`         MEDIUMTEXT NOT NULL DEFAULT ('{}'),
  PRIMARY KEY (`match_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `bbb_rounds` (
  `id`              BIGINT NOT NULL AUTO_INCREMENT,
  `match_key`       VARCHAR(191) NOT NULL,
  `innings`         INT NOT NULL,
  `delivery_idx`    INT NOT NULL,
  `over_no`         INT NOT NULL,
  `ball_no`         INT NOT NULL,
  `label`           TEXT NOT NULL DEFAULT (''),
  `status`          VARCHAR(16) NOT NULL DEFAULT 'OPEN',
  `rake_pct`        DECIMAL(5,2) NOT NULL DEFAULT 10,
  `opened_at`       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `closes_at`       DATETIME(3) NOT NULL,
  `result_ball_uid` TEXT NULL,
  `outcome`         TEXT NULL,
  `result_text`     TEXT NULL,
  `pool_total`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `paid_total`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `rake_taken`      DECIMAL(14,2) NOT NULL DEFAULT 0,
  `void_reason`     TEXT NULL,
  `settled_at`      DATETIME(3) NULL,
  `slot`            INT NULL,                                       -- migration 014
  `attempt`         INT NULL,                                       -- migration 014
  PRIMARY KEY (`id`),
  UNIQUE KEY `bbb_rounds_one_per_ball` (`match_key`, `innings`, `slot`, `attempt`),
  KEY `bbb_rounds_match_idx` (`match_key`, `id`),
  CONSTRAINT `bbb_rounds_status_chk` CHECK (`status` IN ('OPEN', 'SUSPENDED', 'SETTLED', 'VOID'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `bbb_bets` (
  `id`         BIGINT NOT NULL AUTO_INCREMENT,
  `round_id`   BIGINT NOT NULL,
  `user_id`    INT NOT NULL,
  `username`   VARCHAR(191) NOT NULL,
  `outcome`    VARCHAR(191) NOT NULL,
  `stake`      DECIMAL(12,2) NOT NULL,
  `txn_id`     VARCHAR(191) NULL,
  `status`     VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  `payout`     DECIMAL(14,2) NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `settled_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  KEY `bbb_bets_round_idx` (`round_id`),
  KEY `bbb_bets_user_idx` (`user_id`, `id`),
  CONSTRAINT `bbb_bets_round_fk` FOREIGN KEY (`round_id`) REFERENCES `bbb_rounds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bbb_bets_user_fk` FOREIGN KEY (`user_id`) REFERENCES `User` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bbb_bets_status_chk` CHECK (`status` IN ('PENDING', 'WON', 'LOST', 'REFUNDED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Migration 011 — cricket match betting (exchange)
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `mx_bets` (
  `id`             BIGINT NOT NULL AUTO_INCREMENT,
  `match_key`      VARCHAR(191) NOT NULL,
  `market_key`     VARCHAR(191) NOT NULL,
  `market_type`    VARCHAR(32) NOT NULL,
  `market_name`    TEXT NOT NULL DEFAULT (''),
  `selection`      VARCHAR(191) NOT NULL,
  `selection_name` TEXT NOT NULL DEFAULT (''),
  `side`           VARCHAR(8) NOT NULL,
  `odds`           DECIMAL(10,3) NOT NULL DEFAULT 0,
  `rate`           DECIMAL(10,2) NOT NULL DEFAULT 0,
  `line`           INT NULL,
  `stake`          DECIMAL(12,2) NOT NULL,
  `liability`      DECIMAL(14,2) NOT NULL,
  `profit`         DECIMAL(14,2) NOT NULL,
  `user_id`        INT NOT NULL,
  `username`       VARCHAR(191) NOT NULL,
  `txn_id`         VARCHAR(191) NULL,
  `status`         VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  `payout`         DECIMAL(14,2) NOT NULL DEFAULT 0,
  `void_reason`    TEXT NULL,
  `created_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `settled_at`     DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  KEY `mx_bets_match_idx` (`match_key`, `market_key`, `status`),
  KEY `mx_bets_user_idx` (`user_id`, `id`),
  KEY `mx_bets_created_idx` (`match_key`, `created_at`),
  CONSTRAINT `mx_bets_user_fk` FOREIGN KEY (`user_id`) REFERENCES `User` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mx_bets_status_chk` CHECK (`status` IN ('PENDING', 'WON', 'LOST', 'VOID', 'CASHED_OUT')),
  CONSTRAINT `mx_bets_side_chk` CHECK (`side` IN ('BACK', 'LAY', 'YES', 'NO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `mx_markets` (
  `match_key`   VARCHAR(191) NOT NULL,
  `market_key`  VARCHAR(191) NOT NULL,
  `market_type` VARCHAR(32) NOT NULL,
  `market_name` TEXT NOT NULL DEFAULT (''),
  `status`      VARCHAR(16) NOT NULL,
  `result`      TEXT NULL,
  `void_reason` TEXT NULL,
  `settled_at`  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`match_key`, `market_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `mx_match_settings` (
  `match_key`  VARCHAR(191) NOT NULL,
  `fav_side`   VARCHAR(8) NULL,
  `fav_price`  DECIMAL(8,3) NULL,
  `delta`      DECIMAL(10,5) NOT NULL DEFAULT 0,
  `suspended`  SMALLINT NOT NULL DEFAULT 0,
  `source`     VARCHAR(32) NOT NULL DEFAULT 'operator',
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`match_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Migrations 012 / 013 — Chicken Road, Astronaut, Tower Rush
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `ChickenRoadSession` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `username`    VARCHAR(191) NOT NULL,
  `status`      VARCHAR(32) NOT NULL,
  `difficulty`  VARCHAR(32) NOT NULL DEFAULT 'easy',
  `bet_amount`  DOUBLE NOT NULL DEFAULT 0,
  `step`        INT NOT NULL DEFAULT 0,
  `fail_step`   INT NOT NULL DEFAULT 0,
  `server_seed` TEXT NULL,
  `seed_hash`   TEXT NULL,
  `multiplier`  DOUBLE NOT NULL DEFAULT 1,
  `payout`      DOUBLE NOT NULL DEFAULT 0,
  `round_ref`   VARCHAR(191) NULL,
  `updated_at`  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ChickenRoadSession_username_key` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `AstronautBet` (
  `id`           BIGINT NOT NULL AUTO_INCREMENT,
  `round_id`     BIGINT NOT NULL,
  `username`     VARCHAR(191) NOT NULL,
  `panel`        SMALLINT NOT NULL,
  `amount`       DOUBLE NOT NULL,
  `auto_cashout` DOUBLE NULL,
  `status`       VARCHAR(16) NOT NULL DEFAULT 'pending',
  `cashout_mult` DOUBLE NULL,
  `payout`       DOUBLE NOT NULL DEFAULT 0,
  `created_at`   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `settled_at`   DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `AstronautBet_round_user_panel_key` (`round_id`, `username`, `panel`),
  KEY `AstronautBet_round_status_idx` (`round_id`, `status`),
  KEY `AstronautBet_user_idx` (`username`, `id`),
  CONSTRAINT `AstronautBet_panel_chk` CHECK (`panel` IN (1, 2)),
  CONSTRAINT `AstronautBet_status_chk` CHECK (`status` IN ('pending', 'won', 'lost', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `TowerRushSession` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `username`    VARCHAR(191) NOT NULL,
  `status`      VARCHAR(32) NOT NULL,
  `bet_amount`  DOUBLE NOT NULL DEFAULT 0,
  `floors`      INT NOT NULL DEFAULT 0,
  `factors`     JSON NULL,
  `multiplier`  DOUBLE NOT NULL DEFAULT 1,
  `rtp`         DOUBLE NOT NULL DEFAULT 0.97,
  `payout`      DOUBLE NOT NULL DEFAULT 0,
  `server_seed` TEXT NULL,
  `seed_hash`   TEXT NULL,
  `round_ref`   VARCHAR(191) NULL,
  `updated_at`  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `TowerRushSession_username_key` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Migrations 016 / 017 — operator action log, signed-in presence
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `AdminLog` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `actor`      TEXT NULL,
  `role`       TEXT NULL,
  `method`     TEXT NULL,
  `path`       TEXT NULL,
  `action`     TEXT NULL,
  `summary`    TEXT NULL,
  `status`     INT NULL,
  `ip`         TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `AdminLog_created_at_idx` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `SessionPresence` (
  `username`  VARCHAR(191) NOT NULL,
  `last_seen` BIGINT NOT NULL,
  `role`      TEXT NULL,
  PRIMARY KEY (`username`),
  KEY `SessionPresence_last_seen_idx` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- -------------------------------------------------------------------------------------------------
-- Migration 018 — Sub-Admins
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `SubAdmin` (
  `id`                   INT NOT NULL AUTO_INCREMENT,
  `public_id`            VARCHAR(191) NOT NULL,
  `full_name`            TEXT NOT NULL,
  `username`             VARCHAR(191) NOT NULL,
  `password_hash`        TEXT NOT NULL,
  `must_change_password` SMALLINT NOT NULL DEFAULT 1,
  `status`               VARCHAR(16) NOT NULL DEFAULT 'active',
  `coin_balance`         DOUBLE NOT NULL DEFAULT 0,
  `created_at`           DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `created_by`           TEXT NOT NULL,
  `created_by_role`      TEXT NOT NULL,
  `status_changed_at`    DATETIME(3) NULL,
  `status_changed_by`    TEXT NULL,
  `status_reason`        TEXT NULL,
  `password_changed_at`  DATETIME(3) NULL,
  `last_login_at`        DATETIME(3) NULL,
  `username_lower`       VARCHAR(191) GENERATED ALWAYS AS (LOWER(`username`)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `SubAdmin_public_id_key` (`public_id`),
  UNIQUE KEY `SubAdmin_username_lower_key` (`username_lower`),
  CONSTRAINT `SubAdmin_status_check` CHECK (`status` IN ('active', 'suspended')),
  CONSTRAINT `SubAdmin_coin_balance_check` CHECK (`coin_balance` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `SubAdminUser` (
  `user_id`          INT NOT NULL,
  `subadmin_id`      INT NOT NULL,
  `assigned_at`      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `assigned_by`      TEXT NOT NULL,
  `assigned_by_role` TEXT NOT NULL,
  `how`              TEXT NOT NULL,
  PRIMARY KEY (`user_id`),
  KEY `SubAdminUser_subadmin_idx` (`subadmin_id`),
  CONSTRAINT `SubAdminUser_subadmin_fk` FOREIGN KEY (`subadmin_id`) REFERENCES `SubAdmin` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `SubAdminAllocation` (
  `id`              VARCHAR(191) NOT NULL,
  `subadmin_id`     INT NOT NULL,
  `coins`           DOUBLE NOT NULL,
  `agreed_amount`   DOUBLE NOT NULL,
  `credit_mode`     VARCHAR(32) NOT NULL,
  `status`          VARCHAR(32) NOT NULL,
  `notes`           TEXT NULL,
  `idempotency_key` VARCHAR(191) NULL,
  `created_by`      TEXT NOT NULL,
  `created_by_role` TEXT NOT NULL,
  `created_at`      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `credited_by`     TEXT NULL,
  `credited_at`     DATETIME(3) NULL,
  `cancelled_by`    TEXT NULL,
  `cancelled_at`    DATETIME(3) NULL,
  `cancel_reason`   TEXT NULL,
  `reversed_by`     TEXT NULL,
  `reversed_at`     DATETIME(3) NULL,
  `reverse_reason`  TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `SubAdminAllocation_idempotency_key` (`idempotency_key`),
  KEY `SubAdminAllocation_subadmin_idx` (`subadmin_id`, `created_at`),
  CONSTRAINT `SubAdminAllocation_subadmin_fk` FOREIGN KEY (`subadmin_id`) REFERENCES `SubAdmin` (`id`),
  CONSTRAINT `SubAdminAllocation_coins_check` CHECK (`coins` > 0),
  CONSTRAINT `SubAdminAllocation_amount_check` CHECK (`agreed_amount` >= 0),
  CONSTRAINT `SubAdminAllocation_mode_check` CHECK (`credit_mode` IN ('after_payment', 'immediate')),
  CONSTRAINT `SubAdminAllocation_status_check` CHECK (`status` IN ('awaiting_payment', 'credited', 'cancelled', 'reversed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `SubAdminDeposit` (
  `id`                  VARCHAR(191) NOT NULL,
  `subadmin_id`         INT NOT NULL,
  `user_id`             INT NOT NULL,
  `username`            VARCHAR(191) NOT NULL,
  `coins`               DOUBLE NOT NULL,
  `amount_inr`          DOUBLE NOT NULL DEFAULT 0,
  `status`              VARCHAR(32) NOT NULL,
  `method`              TEXT NULL,
  `reference`           VARCHAR(191) NULL,
  `notes`               TEXT NULL,
  `cash_id`             VARCHAR(191) NULL,
  `idempotency_key`     VARCHAR(191) NULL,
  `created_by`          TEXT NOT NULL,
  `created_by_role`     TEXT NOT NULL,
  `created_at`          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `payment_recorded_at` DATETIME(3) NULL,
  `confirmed_by`        TEXT NULL,
  `confirmed_at`        DATETIME(3) NULL,
  `cancelled_by`        TEXT NULL,
  `cancelled_at`        DATETIME(3) NULL,
  `cancel_reason`       TEXT NULL,
  `reversed_by`         TEXT NULL,
  `reversed_at`         DATETIME(3) NULL,
  `reverse_reason`      TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `SubAdminDeposit_idempotency_key` (`idempotency_key`),
  KEY `SubAdminDeposit_subadmin_idx` (`subadmin_id`, `created_at`),
  KEY `SubAdminDeposit_user_idx` (`user_id`),
  CONSTRAINT `SubAdminDeposit_subadmin_fk` FOREIGN KEY (`subadmin_id`) REFERENCES `SubAdmin` (`id`),
  CONSTRAINT `SubAdminDeposit_coins_check` CHECK (`coins` > 0),
  CONSTRAINT `SubAdminDeposit_amount_check` CHECK (`amount_inr` >= 0),
  CONSTRAINT `SubAdminDeposit_status_check` CHECK (`status` IN ('Requested', 'Unconfirmed', 'Confirmed', 'Cancelled', 'Reversed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `SubAdminWithdrawal` (
  `withdrawal_id`    VARCHAR(191) NOT NULL,
  `subadmin_id`      INT NOT NULL,
  `user_id`          INT NOT NULL,
  `username`         VARCHAR(191) NOT NULL,
  `coins`            DOUBLE NOT NULL,
  `rate`             DOUBLE NOT NULL,
  `rupee_equivalent` DOUBLE NOT NULL,
  `status`           VARCHAR(32) NOT NULL,
  `coins_to`         TEXT NULL,
  `approved_by`      TEXT NULL,
  `approved_at`      DATETIME(3) NULL,
  `paid_amount`      DOUBLE NULL,
  `payout_method`    TEXT NULL,
  `payout_reference` TEXT NULL,
  `paid_by`          TEXT NULL,
  `paid_at`          DATETIME(3) NULL,
  `cash_id`          VARCHAR(191) NULL,
  `rejected_by`      TEXT NULL,
  `rejected_at`      DATETIME(3) NULL,
  `reject_reason`    TEXT NULL,
  `reversed_by`      TEXT NULL,
  `reversed_at`      DATETIME(3) NULL,
  `reverse_reason`   TEXT NULL,
  `returned_by`      TEXT NULL,
  `returned_at`      DATETIME(3) NULL,
  `created_at`       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`withdrawal_id`),
  KEY `SubAdminWithdrawal_subadmin_idx` (`subadmin_id`, `status`),
  KEY `SubAdminWithdrawal_user_idx` (`user_id`),
  CONSTRAINT `SubAdminWithdrawal_subadmin_fk` FOREIGN KEY (`subadmin_id`) REFERENCES `SubAdmin` (`id`),
  CONSTRAINT `SubAdminWithdrawal_status_check` CHECK (`status` IN ('Pending', 'Approved', 'Paid', 'Rejected', 'Reversed', 'Returned'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- reference_key: the Postgres partial unique index on (kind, UPPER(reference)) for live references.
CREATE TABLE IF NOT EXISTS `SubAdminCash` (
  `id`                VARCHAR(191) NOT NULL,
  `kind`              VARCHAR(32) NOT NULL,
  `direction`         VARCHAR(32) NOT NULL,
  `subadmin_id`       INT NOT NULL,
  `user_id`           INT NULL,
  `username`          VARCHAR(191) NULL,
  `amount`            DOUBLE NOT NULL,
  `method`            TEXT NULL,
  `reference`         VARCHAR(191) NULL,
  `status`            VARCHAR(16) NOT NULL,
  `allocation_id`     VARCHAR(191) NULL,
  `deposit_id`        VARCHAR(191) NULL,
  `withdrawal_id`     VARCHAR(191) NULL,
  `reverses`          VARCHAR(191) NULL,
  `notes`             TEXT NULL,
  `created_by`        TEXT NOT NULL,
  `created_by_role`   TEXT NOT NULL,
  `created_at`        DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `confirmed_by`      TEXT NULL,
  `confirmed_by_role` TEXT NULL,
  `confirmed_at`      DATETIME(3) NULL,
  `failed_by`         TEXT NULL,
  `failed_at`         DATETIME(3) NULL,
  `fail_reason`       TEXT NULL,
  `reversed_by`       TEXT NULL,
  `reversed_at`       DATETIME(3) NULL,
  `reverse_reason`    TEXT NULL,
  `reference_key`     VARCHAR(191) GENERATED ALWAYS AS (
                         CASE WHEN `reference` IS NOT NULL AND `reference` <> ''
                                   AND `status` IN ('Unconfirmed', 'Confirmed')
                              THEN UPPER(`reference`) END) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `SubAdminCash_reference_key` (`kind`, `reference_key`),
  KEY `SubAdminCash_subadmin_idx` (`subadmin_id`, `created_at`),
  KEY `SubAdminCash_allocation_idx` (`allocation_id`),
  KEY `SubAdminCash_deposit_idx` (`deposit_id`),
  KEY `SubAdminCash_withdrawal_idx` (`withdrawal_id`),
  CONSTRAINT `SubAdminCash_subadmin_fk` FOREIGN KEY (`subadmin_id`) REFERENCES `SubAdmin` (`id`),
  CONSTRAINT `SubAdminCash_amount_check` CHECK (`amount` >= 0),
  CONSTRAINT `SubAdminCash_kind_check` CHECK (`kind` IN ('allocation_payment', 'user_deposit', 'user_payout', 'reversal')),
  CONSTRAINT `SubAdminCash_direction_check` CHECK (`direction` IN ('subadmin_to_admin', 'admin_to_subadmin', 'user_to_subadmin', 'subadmin_to_user')),
  CONSTRAINT `SubAdminCash_status_check` CHECK (`status` IN ('Unconfirmed', 'Confirmed', 'Failed', 'Reversed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `SubAdminLedger` (
  `id`              VARCHAR(191) NOT NULL,
  `type`            VARCHAR(64) NOT NULL,
  `from_account`    VARCHAR(191) NOT NULL,
  `to_account`      VARCHAR(191) NOT NULL,
  `coins`           DOUBLE NOT NULL,
  `rupees`          DOUBLE NULL,
  `subadmin_id`     INT NOT NULL,
  `user_id`         INT NULL,
  `username`        VARCHAR(191) NULL,
  `ref`             VARCHAR(191) NULL,
  `cash_id`         VARCHAR(191) NULL,
  `reverses`        VARCHAR(191) NULL,
  `inventory_after` DOUBLE NULL,
  `actor`           TEXT NOT NULL,
  `actor_role`      TEXT NOT NULL,
  `notes`           TEXT NULL,
  `created_at`      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `SubAdminLedger_subadmin_idx` (`subadmin_id`, `created_at`),
  KEY `SubAdminLedger_ref_idx` (`ref`),
  KEY `SubAdminLedger_user_idx` (`user_id`),
  CONSTRAINT `SubAdminLedger_subadmin_fk` FOREIGN KEY (`subadmin_id`) REFERENCES `SubAdmin` (`id`),
  CONSTRAINT `SubAdminLedger_coins_check` CHECK (`coins` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS `SubAdminEvent` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `subadmin_id` INT NOT NULL,
  `event`       VARCHAR(64) NOT NULL,
  `user_id`     INT NULL,
  `username`    VARCHAR(191) NULL,
  `actor`       TEXT NOT NULL,
  `actor_role`  TEXT NOT NULL,
  `details`     TEXT NULL,
  `created_at`  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `SubAdminEvent_subadmin_idx` (`subadmin_id`, `created_at`),
  CONSTRAINT `SubAdminEvent_subadmin_fk` FOREIGN KEY (`subadmin_id`) REFERENCES `SubAdmin` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- =================================================================================================
-- SEED — the house account. REQUIRED: Teen Patti's house-win paths fail silently without it.
-- Its password is deliberately not a valid bcrypt hash, so nobody can sign in as the house.
-- =================================================================================================

INSERT IGNORE INTO `User` (`username`, `email`, `password`, `wallet_balance`)
VALUES ('Admin', 'admin@bet1x.local', '!house-account-login-disabled', 5000);

-- =================================================================================================
-- SEED — the six Teen Patti rooms (re-running this file resets them, as a Node restart did).
-- =================================================================================================

INSERT INTO `TeenPattiRoom` (`id`,`name`,`boot_amount`,`status`,`pot`,`current_stake`,`log`)
VALUES
  ('room_101','Room 1', 10,'waiting',0, 10,'[]'),
  ('room_102','Room 2',100,'waiting',0,100,'[]'),
  ('room_103','Room 3', 50,'waiting',0, 50,'[]'),
  ('room_104','Room 4', 50,'waiting',0, 50,'[]'),
  ('room_105','Room 5', 25,'waiting',0, 25,'[]'),
  ('room_106','Room 6',250,'waiting',0,250,'[]')
ON DUPLICATE KEY UPDATE
  `status`='waiting', `pot`=0, `winner_seat`=NULL, `admin_rig`=NULL,
  `bot_turn_due_at`=NULL, `round_end_due_at`=NULL, `bot_fill_due_at`=NULL;

INSERT INTO `TeenPattiSeat` (`room_id`,`seat`,`username`,`is_bot`,`cards`,`folded`)
VALUES
  ('room_101',0,NULL,0,NULL,0),('room_101',1,NULL,0,NULL,0),('room_101',2,NULL,0,NULL,0),('room_101',3,NULL,0,NULL,0),
  ('room_102',0,NULL,0,NULL,0),('room_102',1,NULL,0,NULL,0),('room_102',2,NULL,0,NULL,0),('room_102',3,NULL,0,NULL,0),
  ('room_103',0,NULL,0,NULL,0),('room_103',1,NULL,0,NULL,0),('room_103',2,NULL,0,NULL,0),('room_103',3,NULL,0,NULL,0),
  ('room_104',0,NULL,0,NULL,0),('room_104',1,NULL,0,NULL,0),('room_104',2,NULL,0,NULL,0),('room_104',3,NULL,0,NULL,0),
  ('room_105',0,NULL,0,NULL,0),('room_105',1,NULL,0,NULL,0),('room_105',2,NULL,0,NULL,0),('room_105',3,NULL,0,NULL,0),
  ('room_106',0,NULL,0,NULL,0),('room_106',1,NULL,0,NULL,0),('room_106',2,NULL,0,NULL,0),('room_106',3,NULL,0,NULL,0)
ON DUPLICATE KEY UPDATE
  `username`=NULL, `is_bot`=0, `cards`=NULL, `folded`=0, `last_seen_at`=NULL;

-- Schema-version flags, so the PHP code's "apply once" checks see these parts as already in place.
INSERT INTO `GameState` (`key`, `data`) VALUES
  ('admin_log_schema_version', '16'),
  ('session_presence_schema_version', '17'),
  ('user_identity_schema_version', '15'),
  ('subadmin_schema_version', '18'),
  ('bbb_schema_version', '14')
ON DUPLICATE KEY UPDATE `key` = `key`;
