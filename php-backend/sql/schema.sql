-- =================================================================================================
-- bet1x — MySQL schema (PHP 8 / cPanel port of backend/prisma/schema.prisma)
-- =================================================================================================
--
-- Table and column names are IDENTICAL to the Prisma model/field names, deliberately: it keeps the
-- mapping between this file and the original readable, and it means a dump exported from the old
-- PostgreSQL database can be loaded here with no column renaming.
--
-- TYPE CHOICES THAT AFFECT BEHAVIOUR — read before changing anything:
--
--   * Money is DOUBLE, not DECIMAL. This is deliberate and is NOT an oversight. Prisma `Float` is
--     PostgreSQL `double precision`, and the application code leans on that: it rounds ad hoc, at
--     specific points, via Math.round(x*100)/100 and parseFloat(n.toFixed(2)). DECIMAL(12,2) would
--     round half-up at EVERY write, so balances would diverge from the Node build. DOUBLE
--     reproduces the current arithmetic exactly. Moving to DECIMAL is a worthwhile change, but a
--     separate and deliberate one, made after this port is proven.
--
--   * `username` and `email` are utf8mb4_bin. PostgreSQL's default collation is case-SENSITIVE, so
--     'Bob' and 'bob' are two different unique keys there. MySQL's usual utf8mb4_unicode_ci would
--     make them collide and reject the second row — a behaviour change. Binary collation matches
--     PostgreSQL. Every case-insensitive lookup in the app is therefore written as an explicit
--     LOWER(col) = LOWER(?), exactly mirroring Prisma's `mode: 'insensitive'`.
--
--   * JSON columns are the MySQL JSON type where the value is genuinely structured, matching
--     Prisma's Json. MySQL 5.7+ / MariaDB 10.2+ both accept this.
--
--   * DATETIME(3), not TIMESTAMP. Millisecond precision matches Prisma's DateTime, and DATETIME has
--     no 2038 limit. All timestamps are written and read as UTC by the application; the connection
--     sets time_zone = '+00:00' so the server's own timezone cannot shift stored values.
--
-- Run this file once, then php-backend/cron/tick.php on a one-minute cron. Do NOT run
-- schema-cricket.sql — the two cricket games are gated off until v2.
-- =================================================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- -------------------------------------------------------------------------------------------------
-- Core account + ledger
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `User` (
  `id`             INT NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `email`          VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `password`       VARCHAR(255) NOT NULL,
  `wallet_balance` DOUBLE NOT NULL DEFAULT 1000,
  `created_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `User_username_key` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Transaction` (
  `id`        VARCHAR(191) NOT NULL,
  `user`      VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `type`      VARCHAR(64) NOT NULL,
  `amount`    DOUBLE NOT NULL,
  `details`   TEXT NOT NULL,
  `status`    VARCHAR(32) NOT NULL,
  `timestamp` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `Transaction_user_idx` (`user`),
  KEY `Transaction_timestamp_idx` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Deposit` (
  `deposit_id`     VARCHAR(191) NOT NULL,
  `order_id`       VARCHAR(191) NULL,
  `username`       VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `amount`         DOUBLE NOT NULL,
  `utr`            VARCHAR(191) NULL,
  `qr_type`        VARCHAR(191) NULL,
  `custom_qr_data` TEXT NULL,
  `status`         VARCHAR(32) NOT NULL,
  `gateway`        VARCHAR(191) NULL,
  `gateway_id`     VARCHAR(191) NULL,
  `created_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`deposit_id`),
  UNIQUE KEY `Deposit_order_id_key` (`order_id`),
  KEY `Deposit_username_idx` (`username`),
  KEY `Deposit_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `Withdrawal` (
  `withdrawal_id` VARCHAR(191) NOT NULL,
  `username`      VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `amount`        DOUBLE NOT NULL,
  `method`        VARCHAR(64) NOT NULL,
  `details`       TEXT NOT NULL,
  `status`        VARCHAR(32) NOT NULL,
  `created_at`    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`withdrawal_id`),
  KEY `Withdrawal_username_idx` (`username`),
  KEY `Withdrawal_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `PaymentLog` (
  `id`        VARCHAR(191) NOT NULL,
  `payload`   JSON NOT NULL,
  `signature` VARCHAR(512) NULL,
  `timestamp` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------------------
-- Generic key/JSON store.
--
-- Carries far more than its name suggests: the whole colour-prediction round state, every per-room
-- rig override, the bot-takeover config, each rig ledger's in-progress 100-slot bag, the Mines rig
-- matrix, and (new in this port) the Aviator runtime that used to live in process memory.
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `GameState` (
  `key`       VARCHAR(191) NOT NULL,
  `data`      JSON NOT NULL,
  `updatedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `RecentResult` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `room`        VARCHAR(64) NOT NULL,
  `roundNumber` VARCHAR(64) NOT NULL,
  `number`      INT NOT NULL,
  `color`       VARCHAR(32) NOT NULL,
  `dotClass`    VARCHAR(32) NOT NULL,
  `size`        VARCHAR(32) NOT NULL,
  `timestamp`   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `RecentResult_room_roundNumber_key` (`room`, `roundNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ChatMessage` (
  `id`        INT NOT NULL AUTO_INCREMENT,
  `username`  VARCHAR(191) NOT NULL,
  `message`   VARCHAR(512) NOT NULL,
  `timestamp` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `ChatMessage_timestamp_idx` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------------------
-- Teen Patti
--
-- `bot_turn_due_at`, `round_end_due_at` and `bot_fill_due_at` are NEW columns and the only schema
-- addition this game needed. In Node these three deadlines were setTimeout handles living in the
-- process; PHP has no process to hold them, so the deadline is written down and whichever request
-- (or the one-minute cron) arrives after it has passed performs the work. Same observable timing
-- for anyone actually sitting at a table, because the client polls every 2 seconds.
-- -------------------------------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `TeenPattiRoom` (
  `id`               VARCHAR(64) NOT NULL,
  `name`             VARCHAR(191) NOT NULL,
  `boot_amount`      DOUBLE NOT NULL DEFAULT 10,
  `status`           VARCHAR(32) NOT NULL DEFAULT 'waiting',
  `pot`              DOUBLE NOT NULL DEFAULT 0,
  `current_stake`    DOUBLE NOT NULL DEFAULT 10,
  `turn_index`       INT NOT NULL DEFAULT 0,
  `turn_seat`        INT NULL,
  `turn_start`       DATETIME(3) NULL,
  `round`            INT NOT NULL DEFAULT 1,
  `winner_seat`      INT NULL,
  `deck_state`       JSON NULL,
  `admin_rig`        JSON NULL,
  `log`              JSON NOT NULL,
  `created_at`       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at`       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  `bot_turn_due_at`  BIGINT NULL,
  `round_end_due_at` BIGINT NULL,
  `bot_fill_due_at`  BIGINT NULL,
  PRIMARY KEY (`id`),
  KEY `TeenPattiRoom_status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `TeenPattiSeat` (
  `id`            INT NOT NULL AUTO_INCREMENT,
  `room_id`       VARCHAR(64) NOT NULL,
  `seat`          INT NOT NULL,
  `username`      VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,
  `is_bot`        TINYINT(1) NOT NULL DEFAULT 0,
  `cards`         JSON NULL,
  `folded`        TINYINT(1) NOT NULL DEFAULT 0,
  `balance`       DOUBLE NOT NULL DEFAULT 0,
  `seen`          TINYINT(1) NOT NULL DEFAULT 0,
  `last_seen_at`  BIGINT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `TeenPattiSeat_room_id_seat_key` (`room_id`, `seat`),
  KEY `TeenPattiSeat_username_idx` (`username`),
  CONSTRAINT `TeenPattiSeat_room_id_fkey`
    FOREIGN KEY (`room_id`) REFERENCES `TeenPattiRoom` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `GameBet` (
  `id`         VARCHAR(191) NOT NULL,
  `username`   VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `game`       VARCHAR(64) NOT NULL,
  `bet_amount` DOUBLE NOT NULL,
  `payout`     DOUBLE NOT NULL DEFAULT 0,
  `status`     VARCHAR(32) NOT NULL DEFAULT 'active',
  `metadata`   JSON NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `settled_at` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  KEY `GameBet_username_game_idx` (`username`, `game`),
  KEY `GameBet_game_status_idx` (`game`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =================================================================================================
-- RUNTIME TABLES — new in the PHP port
--
-- None of these existed in the Node build because none of them needed to: they held state in
-- process memory that survived between requests. PHP starts from nothing on every request, so the
-- state has to be written down. Behaviour is unchanged; only its storage is.
-- =================================================================================================

-- Replaces MINES_USER_SESSIONS. One live board per player.
--
-- The UNIQUE key on username is load-bearing: it is what reproduces the synchronous "claim the
-- player's single session slot before the first await" guard from server.js. An INSERT that
-- collides is the PHP equivalent of finding a session already present, and it is what stops twelve
-- double-clicked Start requests all taking a stake for one board.
CREATE TABLE IF NOT EXISTS `MinesSession` (
  `id`               INT NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `status`           VARCHAR(16) NOT NULL,
  `bet_amount`       DOUBLE NOT NULL DEFAULT 0,
  `mines_count`      INT NOT NULL DEFAULT 3,
  `server_seed`      VARCHAR(191) NULL,
  `seed_hash`        VARCHAR(191) NULL,
  `mine_positions`   JSON NULL,
  `revealed`         JSON NULL,
  `multiplier`       DOUBLE NOT NULL DEFAULT 1,
  `potential_payout` DOUBLE NOT NULL DEFAULT 0,
  `updated_at`       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `MinesSession_username_key` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replaces LIVE_USERS and LIVE_INSTANCES. `kind` is 'user' or 'instance'.
-- The 45-second TTL is applied at read time, exactly as the in-memory version did.
CREATE TABLE IF NOT EXISTS `LivePresence` (
  `id`        INT NOT NULL AUTO_INCREMENT,
  `game`      VARCHAR(64) NOT NULL,
  `kind`      VARCHAR(16) NOT NULL,
  `subject`   VARCHAR(191) NOT NULL,
  `last_seen` BIGINT NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `LivePresence_game_kind_subject_key` (`game`, `kind`, `subject`),
  KEY `LivePresence_last_seen_idx` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replaces lib/rig-audit.js's in-memory ring buffer. Trimmed to MAX_ENTRIES (5000) by the cron and
-- opportunistically on write, matching the ring buffer's cap.
CREATE TABLE IF NOT EXISTS `RigAudit` (
  `id`             BIGINT NOT NULL AUTO_INCREMENT,
  `ts`             BIGINT NOT NULL,
  `game`           VARCHAR(64) NOT NULL,
  `instance`       VARCHAR(191) NULL,
  `round`          VARCHAR(191) NULL,
  `configured_pct` DOUBLE NOT NULL DEFAULT 0,
  `rigged`         TINYINT(1) NOT NULL DEFAULT 0,
  `eligible`       TINYINT(1) NOT NULL DEFAULT 1,
  `live`           INT NULL,
  `targeted`       INT NULL,
  `house_profit`   DOUBLE NULL,
  `note`           VARCHAR(200) NULL,
  PRIMARY KEY (`id`),
  KEY `RigAudit_game_ts_idx` (`game`, `ts`),
  KEY `RigAudit_ts_idx` (`ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Replaces express-rate-limit's in-memory store. One row per (bucket, client, window).
CREATE TABLE IF NOT EXISTS `RateLimit` (
  `id`           BIGINT NOT NULL AUTO_INCREMENT,
  `bucket`       VARCHAR(64) NOT NULL,
  `client`       VARCHAR(191) NOT NULL,
  `window_start` BIGINT NOT NULL,
  `hits`         INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `RateLimit_bucket_client_window_key` (`bucket`, `client`, `window_start`),
  KEY `RateLimit_window_start_idx` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =================================================================================================
-- SEED — the house account.
--
-- REQUIRED. Teen Patti's house-win paths do not work without it, and they fail SILENTLY.
--
-- Both the operator rig (/api/teenpatti/admin/rig) and the per-table takeover seat a player literally
-- named "Admin". tp_start_round then runs its can-this-player-cover-the-boot check over every seated
-- non-filler, looks the name up in `User`, finds nothing, and ejects the seat — so the operator flips
-- the rig, watches the seat vanish, and the house wins nothing. The original Node build behaves
-- exactly the same way; this is an install gap in both, not a difference between them.
--
-- The rest of the design plainly assumes this row exists: tp_start_round tops the account up to 5000
-- before each hand, tp_end_game credits it the pot when it wins, and the superadmin dashboard
-- excludes it from house-profit maths by name, describing it as "the account the house plays
-- through". The winnings have to land in a real wallet.
--
-- The password column deliberately holds a string that is not a valid bcrypt hash, so password_verify
-- can never match it and nobody can sign in as the house. Operators use ADMIN_USERNAME /
-- ADMIN_PASSWORD_HASH for the console; this is a ledger account, not a login.
-- =================================================================================================

INSERT INTO `User` (`username`, `email`, `password`, `wallet_balance`)
VALUES ('Admin', 'admin@bet1x.local', '!house-account-login-disabled', 5000)
ON DUPLICATE KEY UPDATE `username` = `username`;

-- =================================================================================================
-- SEED — the six Teen Patti rooms.
--
-- tpSeedRooms() ran on every Node boot and, for an existing room, cleared every seat and every rig.
-- There is no "boot" in PHP, so that reset now belongs to the installer: running this file resets
-- the rooms exactly the way restarting the old server did.
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
