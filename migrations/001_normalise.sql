-- ============================================
-- PDU Procurement Management System
-- Migration: normalise the database to 3NF
--
-- Target:  MySQL 8.x (WAMP)
-- Source:   the pre-normalisation schema (wide `statutory_rules`,
--            ENUM `procurements.type`, VARCHAR stage names / parties)
-- Authority for the statutory numbers: "Procurement Process System Periods.xlsx"
--
-- This migration is IDEMPOTENT: run it as many times as you like.
-- Existing procurements, stages, users and audit history are preserved.
--
-- Run:  mysql -u root pdu < migrations/001_normalise.sql
-- ============================================

-- The migration runs against the schema SELECTED ON THE CONNECTION, so the
-- caller always chooses the target and nothing here can silently fall back to
-- the live database:
--   mysql -u root pdu_v3   < 001_normalise.sql     # a copy, for testing
--   mysql -u root pdu      < 001_normalise.sql     # the live database
-- There is deliberately no `USE` statement in this file. A hardcoded USE cannot
-- be overridden by the caller and would migrate whatever schema it names.
SET @pdu_schema := DATABASE();

-- Refuse to run against no schema, and against the information_schema /
-- performance_schema pseudo-schemas, where the DDL below would be rejected.
-- SIGNAL is only legal inside a stored program, so the check goes through a
-- one-shot procedure that is dropped again immediately.
DROP PROCEDURE IF EXISTS `pdu_assert_target`;
DELIMITER $$
CREATE PROCEDURE `pdu_assert_target`()
BEGIN
  IF DATABASE() IS NULL OR DATABASE() IN
     ('', 'information_schema', 'performance_schema', 'mysql', 'sys') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'No usable target schema: run "mysql -u root <schema> < 001_normalise.sql"';
  END IF;
END$$
DELIMITER ;
CALL `pdu_assert_target`();
DROP PROCEDURE IF EXISTS `pdu_assert_target`;

-- String literals must use the same collation as the tables, otherwise the
-- seed INSERT..SELECT joins below raise "Illegal mix of collations".
SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;

SET @old_rules_table_exists := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @pdu_schema AND TABLE_NAME = 'statutory_rules'
    AND TABLE_NAME NOT IN ('statutory_periods', 'statutory_period_variants')
);

-- -------------------------------------------
-- 0. BACKUP GUARD
-- -------------------------------------------
-- Take a dump first. This script is additive and reversible, but it does
-- ALTER existing tables, so back up before running:
--   mysqldump -u root pdu > pdu_backup_YYYYMMDD.sql

-- MySQL has no "ADD COLUMN IF NOT EXISTS", so a tiny helper does it.
DROP PROCEDURE IF EXISTS `pdu_add_column`;
DELIMITER $$
CREATE PROCEDURE `pdu_add_column`(
  IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;
-- Take a dump first. This script is additive and reversible, but it does
-- ALTER existing tables, so back up before running:
--   mysqldump -u root pdu > pdu_backup_YYYYMMDD.sql

-- -------------------------------------------
-- 1. LOOKUP TABLES
-- -------------------------------------------

-- 1a. Procurement types.
-- The pre-existing table only had (id, name); it is extended in place so the
-- existing ids stay valid.
CREATE TABLE IF NOT EXISTS `procurement_types` (
  `id`         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pt_code` (`code`),
  UNIQUE KEY `uq_pt_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The helper procedures must exist before the first DDL runs.
DROP PROCEDURE IF EXISTS `pdu_add_column`;
DELIMITER $$
CREATE PROCEDURE `pdu_add_column`(
  IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition TEXT
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

-- A pre-existing table is created by the old script, so its unique keys are
-- missing. Without them the seed below silently creates duplicate lookup rows.
DROP PROCEDURE IF EXISTS `pdu_add_index`;
DELIMITER $$
CREATE PROCEDURE `pdu_add_index`(
  IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_columns VARCHAR(255)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND INDEX_NAME = p_index
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD UNIQUE KEY `', p_index, '` (', p_columns, ')');
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

CALL `pdu_add_column`('procurement_types', 'code',       "VARCHAR(20) NOT NULL DEFAULT ''");
CALL `pdu_add_column`('procurement_types', 'sort_order', 'TINYINT UNSIGNED NOT NULL DEFAULT 0');
-- The legacy table used a signed INT id and a different collation; the lookups
-- use TINYINT UNSIGNED and a single collation so the foreign keys and the text
-- joins are type-compatible. Align before anything references this table.
ALTER TABLE `procurement_types`
  MODIFY COLUMN `id`   TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  MODIFY COLUMN `name` VARCHAR(100) NOT NULL;
-- The unique keys on procurement_types are added in section 2, after the
-- existing rows have been given distinct codes (see "Types:" below).

-- 1b. Procurement methods (was: method_code + method_name duplicated on every rule row)
CREATE TABLE IF NOT EXISTS `procurement_methods` (
  `id`         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pm_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 1c. Process stage catalogue (was: hardcoded DEFAULT_STAGE_NAMES in index.php)
CREATE TABLE IF NOT EXISTS `process_stages` (
  `id`                       TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                     VARCHAR(32)  NOT NULL,
  `name`                     VARCHAR(100) NOT NULL,
  `stage_order`              TINYINT UNSIGNED NOT NULL,
  `default_responsible_party` VARCHAR(100) DEFAULT NULL,
  `anchors_from_code`        VARCHAR(32)  DEFAULT NULL,
  `date_role`                ENUM('BEB','COMPLETION') DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ps_code`  (`code`),
  UNIQUE KEY `uq_ps_order` (`stage_order`),
  CONSTRAINT `fk_ps_anchor` FOREIGN KEY (`anchors_from_code`)
    REFERENCES `process_stages` (`code`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 1d. Responsible parties (was: procurement_stages.responsible_party VARCHAR)
CREATE TABLE IF NOT EXISTS `responsible_parties` (
  `id`   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rp_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 1e. Public holidays (was: duplicated PHP array in two files)
CREATE TABLE IF NOT EXISTS `public_holidays` (
  `id`          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mmdd`        CHAR(5) NOT NULL,
  `description` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ph_mmdd` (`mmdd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- -------------------------------------------
-- 2. LOOKUP SEED + BACK-FILL
-- -------------------------------------------

-- Types: give every existing row a distinct code before the unique key is
-- added, so no two rows can collide on the placeholder value.
UPDATE `procurement_types` SET `code` = 'SUPPLIES', `sort_order` = 1
WHERE `name` LIKE 'Supplies%' AND `code` IN ('', 'LEGACY%');

UPDATE `procurement_types` SET `code` = 'WORKS', `sort_order` = 2
WHERE `name` IN ('Work', 'Works') AND `code` IN ('', 'LEGACY%');

UPDATE `procurement_types` SET `code` = 'CONSULTANCIES', `sort_order` = 3
WHERE `name` LIKE 'Consultanc%' AND `code` IN ('', 'LEGACY%');

-- Any type that is not one of the three canonical ones keeps a distinct
-- placeholder code and name so it survives without colliding.
UPDATE `procurement_types`
SET `code`  = CONCAT('LEGACY_', `id`),
    `name`  = CONCAT(`name`, ' (legacy ', `id`, ')')
WHERE `code` = '' OR `code` LIKE 'LEGACY%';

-- Canonical display names, applied after the codes are settled.
UPDATE `procurement_types` SET `name` = 'Supplies & Non-Consultancy' WHERE `code` = 'SUPPLIES';
UPDATE `procurement_types` SET `name` = 'Works'                    WHERE `code` = 'WORKS';
UPDATE `procurement_types` SET `name` = 'Consultancies'            WHERE `code` = 'CONSULTANCIES';

-- Now that every code is distinct, the lookup keys can be enforced.
CALL `pdu_add_index`('procurement_types', 'uq_pt_code', '`code`');
CALL `pdu_add_index`('procurement_types', 'uq_pt_name', '`name`');

-- Methods: seed the canonical list.
INSERT INTO `procurement_methods` (`code`, `name`, `sort_order`) VALUES
  ('ODB',     'Open Domestic Bidding',                   1),
  ('OIB',     'Open International Bidding',              2),
  ('RDB',     'Restricted Domestic Bidding',             3),
  ('RIB',     'Restricted International Bidding',        4),
  ('RFQ',     'Request for Quotations',                  5),
  ('PREQUAL', 'Prequalification (Open)',                 6),
  ('MICRO',   'Micro Procurement',                       7),
  ('DIRECT',  'Direct Procurement',                      8),
  ('EOI_UG',  'Expression of Interest (Uganda)',         9),
  ('EOI_INT', 'Expression of Interest (International)', 10)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `sort_order` = VALUES(`sort_order`);

-- 7-stage catalogue. The draft contract is anchored to the Contracts Committee
-- decision date, and the BEB / completion roles replace the hardcoded JS indices.
-- Only the two accountable bodies are used: PDU runs the process and Depoint is
-- the procuring entity that drafts, issues and receives the bid.
INSERT INTO `process_stages` (`code`, `name`, `stage_order`, `default_responsible_party`, `anchors_from_code`, `date_role`) VALUES
  ('BID_DRAFT',      'Bid Draft Preparation',                   0, 'Depoint', NULL,          NULL),
  ('BID_ISSUANCE',   'Issuance of the Bid',                      1, 'Depoint', NULL,          NULL),
  ('BIDDING',        'Bidding / Proposal Submission Period',     2, 'Depoint', NULL,          NULL),
  ('EVALUATION',     'Evaluation Period',                        3, 'PDU',     NULL,          NULL),
  ('CC_DECISION',    'Contracts Committee Decision',             4, 'PDU',     NULL,          NULL),
  ('BEB_DISPLAY',    'BEB Display Window',                       5, 'PDU',     NULL,          'BEB'),
  ('DRAFT_CONTRACT', 'Submission of Draft Contract & Completion', 6, 'PDU',     'CC_DECISION', 'COMPLETION')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `default_responsible_party` = VALUES(`default_responsible_party`),
  `anchors_from_code` = VALUES(`anchors_from_code`),
  `date_role` = VALUES(`date_role`);

-- Only the two accountable bodies. Every stage and every existing procurement
-- is attributed to one of them, so the lookup stays this small and the UI's
-- party dropdown cannot offer a committee that owns nothing.
INSERT INTO `responsible_parties` (`name`) VALUES
  ('PDU'),
  ('Depoint')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- The old free-text column carried committee names ("Evaluation Committee",
-- "Contracts Committee", "Accounting Officer", "User Department", "Depoint
-- Procuring Entity"). Those roles are not separate parties: they are all part
-- of the process PDU runs, and "Depoint Procuring Entity" is just Depoint.
-- Map them onto the two bodies so the back-fill below resolves every row,
-- rather than seeding seven parties and leaving five of them unused.
--
-- On a re-run the text column has already been dropped, so only rewrite it
-- while it still exists.
DROP PROCEDURE IF EXISTS `pdu_harvest_parties`;
DELIMITER $$
CREATE PROCEDURE `pdu_harvest_parties`()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'procurement_stages' AND COLUMN_NAME = 'responsible_party'
  ) THEN
    UPDATE `procurement_stages` SET `responsible_party` = 'PDU'
    WHERE `responsible_party` IS NULL OR `responsible_party` = ''
       OR `responsible_party` NOT IN ('PDU', 'Depoint');
  END IF;
END$$
DELIMITER ;
CALL `pdu_harvest_parties`();

INSERT INTO `public_holidays` (`mmdd`, `description`) VALUES
  ('01-01', 'New Year'),
  ('01-26', 'Nrmea celebrations'),
  ('03-08', 'International Womens Day'),
  ('04-01', 'Easter Monday'),
  ('04-18', 'Easter Monday'),
  ('04-19', 'Uganda Martyrs Day'),
  ('05-01', 'Labour Day'),
  ('06-03', 'Martyrs Day'),
  ('06-09', 'National Heroes Day'),
  ('09-09', 'Independence Day'),
  ('10-09', 'Nrmea celebrations'),
  ('12-25', 'Christmas Day'),
  ('12-26', 'Boxing Day')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- The live database may be utf8mb4_0900_ai_ci (MySQL 8 default) while
-- database.sql declares utf8mb4 (general_ci). Align the tables this migration
-- joins on so string comparisons do not raise collation conflicts. One statement
-- per table, because the client cannot split a multi-statement PREPARE.
DROP PROCEDURE IF EXISTS `pdu_align_collation`;
DELIMITER $$
CREATE PROCEDURE `pdu_align_collation`()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE tbl VARCHAR(64);
  DECLARE cur CURSOR FOR
    SELECT TABLE_NAME FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
      AND TABLE_COLLATION <> 'utf8mb4_general_ci';
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;
  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO tbl;
    IF done = 1 THEN LEAVE read_loop; END IF;
    SET @ddl = CONCAT('ALTER TABLE `', tbl, '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;
CALL `pdu_align_collation`();
DROP PROCEDURE IF EXISTS `pdu_align_collation`;

-- -------------------------------------------
-- 3. STATUTORY RULES: wide -> normalised
-- -------------------------------------------
-- The old table is renamed (not dropped) so the migration is reversible and the
-- legacy data stays available for comparison.

-- Guard: only rename if the table still has the old wide shape.
SET @is_wide := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @pdu_schema AND TABLE_NAME = 'statutory_rules'
    AND COLUMN_NAME = 'bid_days'
);

-- 3a. Create the normalised parent table under a temporary name if the old
--     table is still occupying the `statutory_rules` name.
SET @need_rename := IF(@is_wide > 0, 1, 0);

SET @s = IF(@need_rename = 1,
  'RENAME TABLE `statutory_rules` TO `statutory_rules_legacy`',
  'DO 0');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `statutory_rules` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `procurement_type_id`   TINYINT UNSIGNED NOT NULL,
  `procurement_method_id` TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sr_type_method` (`procurement_type_id`, `procurement_method_id`),
  KEY `fk_sr_type`   (`procurement_type_id`),
  KEY `fk_sr_method` (`procurement_method_id`),
  CONSTRAINT `fk_sr_type`   FOREIGN KEY (`procurement_type_id`)   REFERENCES `procurement_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sr_method` FOREIGN KEY (`procurement_method_id`) REFERENCES `procurement_methods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `statutory_periods` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id`     INT UNSIGNED NOT NULL,
  `stage_id`    TINYINT UNSIGNED NOT NULL,
  `period_mode` ENUM('MANDATORY','NO_MINIMUM','NOT_APPLICABLE') NOT NULL DEFAULT 'MANDATORY',
  `days`        INT UNSIGNED DEFAULT NULL,
  `note`        VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sp_rule_stage` (`rule_id`, `stage_id`),
  KEY `fk_sp_rule`  (`rule_id`),
  KEY `fk_sp_stage` (`stage_id`),
  CONSTRAINT `fk_sp_rule`  FOREIGN KEY (`rule_id`)  REFERENCES `statutory_rules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sp_stage` FOREIGN KEY (`stage_id`) REFERENCES `process_stages` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `statutory_period_variants` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `period_id`    INT UNSIGNED NOT NULL,
  `variant_code` VARCHAR(30)  NOT NULL,
  `variant_name` VARCHAR(100) NOT NULL,
  `variant_kind` ENUM('ALTERNATIVE','SEQUENTIAL') NOT NULL DEFAULT 'ALTERNATIVE',
  `ordinal`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `days`         INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_spv_period_code` (`period_id`, `variant_code`),
  KEY `fk_spv_period` (`period_id`),
  CONSTRAINT `fk_spv_period` FOREIGN KEY (`period_id`) REFERENCES `statutory_periods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3b. Carry over any type/method pairs that existed before but are not in the
--     new statutory set, so nothing is silently dropped. COLLATE is explicit
--     because the legacy table may keep its original collation.
DROP PROCEDURE IF EXISTS `pdu_carry_legacy_rules`;
DELIMITER $$
CREATE PROCEDURE `pdu_carry_legacy_rules`()
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'statutory_rules_legacy') > 0 THEN
    INSERT INTO statutory_rules (procurement_type_id, procurement_method_id)
    SELECT t.id, m.id
    FROM statutory_rules_legacy lr
    JOIN procurement_types   t ON TRIM(t.name) COLLATE utf8mb4_general_ci = TRIM(lr.procurement_type) COLLATE utf8mb4_general_ci
    JOIN procurement_methods m ON UPPER(TRIM(m.code)) = UPPER(TRIM(lr.method_code))
    ON DUPLICATE KEY UPDATE procurement_method_id = procurement_method_id;
  END IF;
END$$
DELIMITER ;
CALL `pdu_carry_legacy_rules`();

-- -------------------------------------------
-- 4. STATUTORY SEED (from the source spreadsheet)
-- -------------------------------------------
INSERT INTO `statutory_rules` (`procurement_type_id`, `procurement_method_id`)
SELECT t.`id`, m.`id`
FROM (
  SELECT 'SUPPLIES' AS tc, 'ODB' AS mc
UNION ALL SELECT 'SUPPLIES', 'OIB'
UNION ALL SELECT 'SUPPLIES', 'RDB'
UNION ALL SELECT 'SUPPLIES', 'RIB'
UNION ALL SELECT 'SUPPLIES', 'RFQ'
UNION ALL SELECT 'SUPPLIES', 'PREQUAL'
UNION ALL SELECT 'SUPPLIES', 'MICRO'
UNION ALL SELECT 'SUPPLIES', 'DIRECT'
UNION ALL SELECT 'WORKS', 'ODB'
UNION ALL SELECT 'WORKS', 'OIB'
UNION ALL SELECT 'WORKS', 'RDB'
UNION ALL SELECT 'WORKS', 'RIB'
UNION ALL SELECT 'WORKS', 'RFQ'
UNION ALL SELECT 'WORKS', 'MICRO'
UNION ALL SELECT 'WORKS', 'DIRECT'
UNION ALL SELECT 'CONSULTANCIES', 'ODB'
UNION ALL SELECT 'CONSULTANCIES', 'OIB'
UNION ALL SELECT 'CONSULTANCIES', 'RDB'
UNION ALL SELECT 'CONSULTANCIES', 'RIB'
UNION ALL SELECT 'CONSULTANCIES', 'RFQ'
UNION ALL SELECT 'CONSULTANCIES', 'EOI_UG'
UNION ALL SELECT 'CONSULTANCIES', 'EOI_INT'
) AS x
JOIN `procurement_types`   t ON t.`code` = x.tc
JOIN `procurement_methods` m ON m.`code` = x.mc
ON DUPLICATE KEY UPDATE `procurement_method_id` = `procurement_method_id`;

INSERT INTO `statutory_periods` (`rule_id`, `stage_id`, `period_mode`, `days`, `note`)
SELECT r.`id`, s.`id`, x.pm, x.d, x.nt
FROM (
  SELECT 'SUPPLIES' AS tc, 'ODB' AS mc, 'BID_DRAFT' AS sc, 'MANDATORY' AS pm, 5 AS d, NULL AS nt
UNION ALL SELECT 'SUPPLIES', 'ODB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'SUPPLIES', 'ODB', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL SELECT 'SUPPLIES', 'ODB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'ODB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL SELECT 'SUPPLIES', 'ODB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'ODB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'OIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'OIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'SUPPLIES', 'OIB', 'BIDDING', 'MANDATORY', 20, NULL
UNION ALL SELECT 'SUPPLIES', 'OIB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'OIB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL SELECT 'SUPPLIES', 'OIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'OIB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'RDB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'RDB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'SUPPLIES', 'RDB', 'BIDDING', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'RDB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'RDB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL SELECT 'SUPPLIES', 'RDB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'RDB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'SUPPLIES', 'RIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'RIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'SUPPLIES', 'RIB', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL SELECT 'SUPPLIES', 'RIB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'RIB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL SELECT 'SUPPLIES', 'RIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'RIB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'SUPPLIES', 'RFQ', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'SUPPLIES', 'RFQ', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL SELECT 'SUPPLIES', 'RFQ', 'BIDDING', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'RFQ', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'RFQ', 'CC_DECISION', 'MANDATORY', 1, NULL
UNION ALL SELECT 'SUPPLIES', 'RFQ', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'RFQ', 'DRAFT_CONTRACT', 'MANDATORY', 2, NULL
UNION ALL SELECT 'SUPPLIES', 'PREQUAL', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'SUPPLIES', 'PREQUAL', 'BIDDING', 'MANDATORY', 10, NULL
UNION ALL SELECT 'SUPPLIES', 'PREQUAL', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'PREQUAL', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'MICRO', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'SUPPLIES', 'MICRO', 'BIDDING', 'NO_MINIMUM', NULL, NULL
UNION ALL SELECT 'SUPPLIES', 'MICRO', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'MICRO', 'DRAFT_CONTRACT', 'NOT_APPLICABLE', NULL, NULL
UNION ALL SELECT 'SUPPLIES', 'DIRECT', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'SUPPLIES', 'DIRECT', 'BIDDING', 'NOT_APPLICABLE', NULL, NULL
UNION ALL SELECT 'SUPPLIES', 'DIRECT', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL SELECT 'SUPPLIES', 'DIRECT', 'DRAFT_CONTRACT', 'NOT_APPLICABLE', NULL, NULL
UNION ALL SELECT 'WORKS', 'ODB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'ODB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'WORKS', 'ODB', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL SELECT 'WORKS', 'ODB', 'EVALUATION', 'MANDATORY', 15, NULL
UNION ALL SELECT 'WORKS', 'ODB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'ODB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'WORKS', 'ODB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'OIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'OIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'WORKS', 'OIB', 'BIDDING', 'MANDATORY', 20, NULL
UNION ALL SELECT 'WORKS', 'OIB', 'EVALUATION', 'MANDATORY', 15, NULL
UNION ALL SELECT 'WORKS', 'OIB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'OIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'WORKS', 'OIB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'RDB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'RDB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'WORKS', 'RDB', 'BIDDING', 'MANDATORY', 10, NULL
UNION ALL SELECT 'WORKS', 'RDB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL SELECT 'WORKS', 'RDB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL SELECT 'WORKS', 'RDB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'WORKS', 'RDB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'RIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'RIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'WORKS', 'RIB', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL SELECT 'WORKS', 'RIB', 'EVALUATION', 'MANDATORY', 15, NULL
UNION ALL SELECT 'WORKS', 'RIB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL SELECT 'WORKS', 'RIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'WORKS', 'RIB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'RFQ', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'RFQ', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL SELECT 'WORKS', 'RFQ', 'BIDDING', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'RFQ', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'RFQ', 'CC_DECISION', 'MANDATORY', 1, NULL
UNION ALL SELECT 'WORKS', 'RFQ', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'WORKS', 'RFQ', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'MICRO', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'MICRO', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL SELECT 'WORKS', 'MICRO', 'BIDDING', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'MICRO', 'EVALUATION', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'MICRO', 'CC_DECISION', 'MANDATORY', 1, NULL
UNION ALL SELECT 'WORKS', 'MICRO', 'BEB_DISPLAY', 'MANDATORY', 0, NULL
UNION ALL SELECT 'WORKS', 'MICRO', 'DRAFT_CONTRACT', 'NOT_APPLICABLE', NULL, NULL
UNION ALL SELECT 'WORKS', 'DIRECT', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'DIRECT', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL SELECT 'WORKS', 'DIRECT', 'BIDDING', 'MANDATORY', 5, NULL
UNION ALL SELECT 'WORKS', 'DIRECT', 'EVALUATION', 'MANDATORY', 3, NULL
UNION ALL SELECT 'WORKS', 'DIRECT', 'CC_DECISION', 'MANDATORY', 1, NULL
UNION ALL SELECT 'WORKS', 'DIRECT', 'BEB_DISPLAY', 'MANDATORY', 0, NULL
UNION ALL SELECT 'WORKS', 'DIRECT', 'DRAFT_CONTRACT', 'NOT_APPLICABLE', NULL, NULL
UNION ALL SELECT 'CONSULTANCIES', 'ODB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'CONSULTANCIES', 'ODB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'CONSULTANCIES', 'ODB', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL SELECT 'CONSULTANCIES', 'ODB', 'EVALUATION', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL SELECT 'CONSULTANCIES', 'ODB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL SELECT 'CONSULTANCIES', 'ODB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'CONSULTANCIES', 'ODB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'CONSULTANCIES', 'OIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'CONSULTANCIES', 'OIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'CONSULTANCIES', 'OIB', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL SELECT 'CONSULTANCIES', 'OIB', 'EVALUATION', 'MANDATORY', 20, NULL
UNION ALL SELECT 'CONSULTANCIES', 'OIB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL SELECT 'CONSULTANCIES', 'OIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'CONSULTANCIES', 'OIB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RDB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RDB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RDB', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL SELECT 'CONSULTANCIES', 'RDB', 'EVALUATION', 'MANDATORY', 20, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RDB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RDB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RDB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RIB', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL SELECT 'CONSULTANCIES', 'RIB', 'EVALUATION', 'MANDATORY', 20, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RIB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RIB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RFQ', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RFQ', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RFQ', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL SELECT 'CONSULTANCIES', 'RFQ', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RFQ', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RFQ', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'CONSULTANCIES', 'RFQ', 'DRAFT_CONTRACT', 'MANDATORY', 2, NULL
UNION ALL SELECT 'CONSULTANCIES', 'EOI_UG', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'CONSULTANCIES', 'EOI_UG', 'BIDDING', 'MANDATORY', 10, NULL
UNION ALL SELECT 'CONSULTANCIES', 'EOI_UG', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL SELECT 'CONSULTANCIES', 'EOI_INT', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL SELECT 'CONSULTANCIES', 'EOI_INT', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL SELECT 'CONSULTANCIES', 'EOI_INT', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
) AS x
JOIN `procurement_types`   t ON t.`code` = x.tc
JOIN `procurement_methods` m ON m.`code` = x.mc
JOIN `statutory_rules`     r ON r.`procurement_type_id` = t.`id` AND r.`procurement_method_id` = m.`id`
JOIN `process_stages`      s ON s.`code` = x.sc
ON DUPLICATE KEY UPDATE
  `period_mode` = VALUES(`period_mode`),
  `days`        = VALUES(`days`),
  `note`        = VALUES(`note`);

-- 6c. Variants for the multi-valued spreadsheet cells.
INSERT INTO `statutory_period_variants` (`period_id`, `variant_code`, `variant_name`, `variant_kind`, `ordinal`, `days`)
SELECT p.`id`, 'TECHNICAL', 'Technical evaluation (from opening of technical proposals)', 'SEQUENTIAL', 1, 15
FROM `statutory_periods` p
JOIN `statutory_rules`     r ON r.`id` = p.`rule_id`
JOIN `procurement_types`   t ON t.`id` = r.`procurement_type_id`
JOIN `procurement_methods` m ON m.`id` = r.`procurement_method_id`
JOIN `process_stages`      s ON s.`id` = p.`stage_id`
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'ODB' AND s.`code` = 'EVALUATION'
ON DUPLICATE KEY UPDATE
  `variant_name` = VALUES(`variant_name`),
  `variant_kind` = VALUES(`variant_kind`),
  `ordinal`      = VALUES(`ordinal`),
  `days`         = VALUES(`days`);

INSERT INTO `statutory_period_variants` (`period_id`, `variant_code`, `variant_name`, `variant_kind`, `ordinal`, `days`)
SELECT p.`id`, 'FINANCIAL', 'Financial evaluation (from opening of financial proposals)', 'SEQUENTIAL', 2, 2
FROM `statutory_periods` p
JOIN `statutory_rules`     r ON r.`id` = p.`rule_id`
JOIN `procurement_types`   t ON t.`id` = r.`procurement_type_id`
JOIN `procurement_methods` m ON m.`id` = r.`procurement_method_id`
JOIN `process_stages`      s ON s.`id` = p.`stage_id`
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'ODB' AND s.`code` = 'EVALUATION'
ON DUPLICATE KEY UPDATE
  `variant_name` = VALUES(`variant_name`),
  `variant_kind` = VALUES(`variant_kind`),
  `ordinal`      = VALUES(`ordinal`),
  `days`         = VALUES(`days`);

INSERT INTO `statutory_period_variants` (`period_id`, `variant_code`, `variant_name`, `variant_kind`, `ordinal`, `days`)
SELECT p.`id`, 'INDIVIDUAL', 'Individuals', 'ALTERNATIVE', 1, 10
FROM `statutory_periods` p
JOIN `statutory_rules`     r ON r.`id` = p.`rule_id`
JOIN `procurement_types`   t ON t.`id` = r.`procurement_type_id`
JOIN `procurement_methods` m ON m.`id` = r.`procurement_method_id`
JOIN `process_stages`      s ON s.`id` = p.`stage_id`
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` IN ('ODB','OIB','RDB','RIB','RFQ') AND s.`code` = 'BIDDING'
ON DUPLICATE KEY UPDATE
  `variant_name` = VALUES(`variant_name`),
  `variant_kind` = VALUES(`variant_kind`),
  `ordinal`      = VALUES(`ordinal`),
  `days`         = VALUES(`days`);

INSERT INTO `statutory_period_variants` (`period_id`, `variant_code`, `variant_name`, `variant_kind`, `ordinal`, `days`)
SELECT p.`id`, 'FIRM', 'Firms', 'ALTERNATIVE', 1, 15
FROM `statutory_periods` p
JOIN `statutory_rules`     r ON r.`id` = p.`rule_id`
JOIN `procurement_types`   t ON t.`id` = r.`procurement_type_id`
JOIN `procurement_methods` m ON m.`id` = r.`procurement_method_id`
JOIN `process_stages`      s ON s.`id` = p.`stage_id`
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` IN ('ODB','OIB','RDB','RIB','RFQ') AND s.`code` = 'BIDDING'
ON DUPLICATE KEY UPDATE
  `variant_name` = VALUES(`variant_name`),
  `variant_kind` = VALUES(`variant_kind`),
  `ordinal`      = VALUES(`ordinal`),
  `days`         = VALUES(`days`);

-- -------------------------------------------
-- 5. PROCUREMENTS: ENUM / VARCHAR -> FK
-- -------------------------------------------

-- 5a. Add the new foreign key columns.
CALL `pdu_add_column`('procurements', 'procurement_type_id',   'TINYINT UNSIGNED NULL DEFAULT NULL AFTER `ref_no`');
CALL `pdu_add_column`('procurements', 'procurement_method_id', 'TINYINT UNSIGNED NULL DEFAULT NULL AFTER `procurement_type_id`');

-- 5b. Back-fill from the legacy ENUM / VARCHAR columns. Guarded, because on a
-- re-run the text columns have already been dropped.
DROP PROCEDURE IF EXISTS `pdu_backfill_procurements`;
DELIMITER $$
CREATE PROCEDURE `pdu_backfill_procurements`()
BEGIN
  DECLARE has_type   INT DEFAULT 0;
  DECLARE has_method INT DEFAULT 0;
  SELECT COUNT(*) INTO has_type FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'procurements' AND COLUMN_NAME = 'type';
  SELECT COUNT(*) INTO has_method FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'procurements' AND COLUMN_NAME = 'method';

  IF has_type > 0 THEN
    UPDATE `procurements` p
    JOIN `procurement_types` t
      ON (p.`type` = 'Works'         AND t.`code` = 'WORKS')
      OR (p.`type` = 'Consultancies' AND t.`code` = 'CONSULTANCIES')
      OR (p.`type` LIKE 'Supplies%'  AND t.`code` = 'SUPPLIES')
    SET p.`procurement_type_id` = t.`id`
    WHERE p.`procurement_type_id` IS NULL;
  END IF;

  IF has_method > 0 THEN
    UPDATE `procurements` p
    JOIN `procurement_methods` m ON UPPER(TRIM(m.`code`)) = UPPER(TRIM(p.`method`))
    SET p.`procurement_method_id` = m.`id`
    WHERE p.`procurement_method_id` IS NULL;
  END IF;
END$$
DELIMITER ;
CALL `pdu_backfill_procurements`();

-- 5c. Make the foreign keys mandatory. A row that could not be matched to a
--     lookup is reported by the check query at the end of this script and must
--     be corrected before the text columns are dropped, so this is guarded.
SET @unmatched := (SELECT COUNT(*) FROM `procurements` WHERE `procurement_type_id` IS NULL OR `procurement_method_id` IS NULL);

DROP PROCEDURE IF EXISTS `pdu_drop_column`;
DELIMITER $$
CREATE PROCEDURE `pdu_drop_column`(IN p_table VARCHAR(64), IN p_column VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', p_table, '` DROP COLUMN `', p_column, '`');
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

DROP PROCEDURE IF EXISTS `pdu_add_constraint`;
DELIMITER $$
CREATE PROCEDURE `pdu_add_constraint`(
  IN p_table VARCHAR(64), IN p_name VARCHAR(64), IN p_column VARCHAR(64), IN p_ref_table VARCHAR(64)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND CONSTRAINT_NAME = p_name
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_name,
                      '` FOREIGN KEY (`', p_column, '`) REFERENCES `', p_ref_table, '` (`id`)');
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END$$
DELIMITER ;

-- Enforcing the NOT NULL and dropping the old text columns is only safe when
-- every row matched a lookup, so it is wrapped in a guarded procedure.
DROP PROCEDURE IF EXISTS `pdu_enforce_procurement_fks`;
DELIMITER $$
CREATE PROCEDURE `pdu_enforce_procurement_fks`()
BEGIN
  DECLARE unmatched INT DEFAULT 0;
  SELECT COUNT(*) INTO unmatched FROM `procurements`
    WHERE `procurement_type_id` IS NULL OR `procurement_method_id` IS NULL;
  IF unmatched = 0 THEN
    ALTER TABLE `procurements`
      MODIFY COLUMN `procurement_type_id`   TINYINT UNSIGNED NOT NULL,
      MODIFY COLUMN `procurement_method_id` TINYINT UNSIGNED NOT NULL;
    CALL `pdu_drop_column`('procurements', 'type');
    CALL `pdu_drop_column`('procurements', 'method');
    CALL `pdu_add_constraint`('procurements', 'fk_proc_type',   'procurement_type_id',   'procurement_types');
    CALL `pdu_add_constraint`('procurements', 'fk_proc_method', 'procurement_method_id', 'procurement_methods');
  ELSE
    SELECT 'WARNING: procurements rows could not be matched to a lookup; the type/method foreign keys were NOT enforced. Fix the listed rows and re-run.' AS migration_warning;
  END IF;
END$$
DELIMITER ;
CALL `pdu_enforce_procurement_fks`();

-- -------------------------------------------
-- 6. PROCUREMENT STAGES: VARCHAR name / party -> FK
-- -------------------------------------------

CALL `pdu_add_column`('procurement_stages', 'process_stage_id',     'TINYINT UNSIGNED   NULL DEFAULT NULL AFTER `procurement_id`');
CALL `pdu_add_column`('procurement_stages', 'responsible_party_id', 'SMALLINT UNSIGNED NULL DEFAULT NULL AFTER `target_days`');

-- Back-fill from the legacy text columns. Guarded, because on a re-run the
-- columns have already been dropped and the ids are already in place.
DROP PROCEDURE IF EXISTS `pdu_backfill_stages`;
DELIMITER $$
CREATE PROCEDURE `pdu_backfill_stages`()
BEGIN
  DECLARE has_name INT DEFAULT 0;
  DECLARE has_party INT DEFAULT 0;
  SELECT COUNT(*) INTO has_name FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'procurement_stages' AND COLUMN_NAME = 'stage_name';
  SELECT COUNT(*) INTO has_party FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'procurement_stages' AND COLUMN_NAME = 'responsible_party';

  IF has_name > 0 THEN
    -- Match the legacy stage_name against the catalogue.
    UPDATE `procurement_stages` s
    JOIN `process_stages` ps ON ps.`name` = s.`stage_name`
    SET s.`process_stage_id` = ps.`id`
    WHERE s.`process_stage_id` IS NULL;

    -- Any stage whose name is not in the catalogue (a locally edited name) is
    -- kept as a bespoke catalogue entry so no history is lost.
    INSERT INTO `process_stages` (`code`, `name`, `stage_order`, `default_responsible_party`)
    SELECT CONCAT('LEGACY_', g.`stage_order`), g.`stage_name`, 100 + g.`stage_order`, g.`responsible_party`
    FROM (
      SELECT `stage_order`, `stage_name`, MAX(`responsible_party`) AS `responsible_party`
      FROM `procurement_stages`
      WHERE `process_stage_id` IS NULL AND `stage_name` <> ''
      GROUP BY `stage_order`, `stage_name`
    ) g
    ON DUPLICATE KEY UPDATE
      `name` = VALUES(`name`),
      `default_responsible_party` = VALUES(`default_responsible_party`);

    UPDATE `procurement_stages` s
    JOIN `process_stages` ps ON ps.`name` = s.`stage_name` AND ps.`code` LIKE 'LEGACY_%'
    SET s.`process_stage_id` = ps.`id`
    WHERE s.`process_stage_id` IS NULL;
  END IF;

  IF has_party > 0 THEN
    UPDATE `procurement_stages` s
    JOIN `responsible_parties` rp ON rp.`name` = s.`responsible_party`
    SET s.`responsible_party_id` = rp.`id`
    WHERE s.`responsible_party_id` IS NULL;
  END IF;
END$$
DELIMITER ;
CALL `pdu_backfill_stages`();

-- 6b. Insert the BID_ISSUANCE stage that the 7-stage model adds.
--
-- Existing procurements were created against the old 6-stage list, so each one
-- is missing stage_order 1. The stage sits at the same point in the sequence it
-- always did, so every later stage shifts down by one and the recorded progress
-- marker has to move with it, otherwise a procurement that was "on stage 3"
-- would silently jump forwards a stage.
--
-- Runs before the unique key on (procurement_id, stage_order) is added, because
-- shifting rows would otherwise trip over it.
DROP PROCEDURE IF EXISTS `pdu_insert_issuance_stage`;
DELIMITER $$
CREATE PROCEDURE `pdu_insert_issuance_stage`()
BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_procurement INT;
  DECLARE v_stage       INT;
  DECLARE v_days        INT DEFAULT 0;
  DECLARE v_stage_name  VARCHAR(100) DEFAULT NULL;
  DECLARE v_party_name  VARCHAR(100) DEFAULT NULL;
  DECLARE v_party_id    SMALLINT UNSIGNED DEFAULT NULL;
  -- Only procurements whose own statutory rule actually defines a BID_ISSUANCE
  -- period get the stage. The spreadsheet leaves that cell blank for EOI_UG and
  -- EOI_INT, so for those the stage is not part of the process at all and
  -- inserting a zero-day stage would put a step in their timeline that the law
  -- does not describe.
  DECLARE cur CURSOR FOR
    SELECT p.`id`
    FROM `procurements` p
    WHERE NOT EXISTS (
      SELECT 1 FROM `procurement_stages` ps
      JOIN `process_stages` cat ON cat.`id` = ps.`process_stage_id`
      WHERE ps.`procurement_id` = p.`id` AND cat.`code` = 'BID_ISSUANCE'
    )
      AND EXISTS (
      SELECT 1 FROM `statutory_periods` per
      JOIN `statutory_rules` r ON r.`id` = per.`rule_id`
      JOIN `process_stages` cat ON cat.`id` = per.`stage_id`
      WHERE p.`procurement_type_id`   = r.`procurement_type_id`
        AND p.`procurement_method_id` = r.`procurement_method_id`
        AND cat.`code` = 'BID_ISSUANCE'
    );
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  -- The catalogue is seeded by this script, so the issuance stage is present;
  -- if it is not, there is nothing sensible to insert.
  SELECT `id` INTO v_stage FROM `process_stages` WHERE `code` = 'BID_ISSUANCE' LIMIT 1;

  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_procurement;
    IF done = 1 THEN LEAVE read_loop; END IF;

    -- Shift the stages that follow the new one, and only those, so a
    -- procurement that is missing several stages is not corrupted.
    UPDATE `procurement_stages` SET `stage_order` = `stage_order` + 1
    WHERE `procurement_id` = v_procurement AND `stage_order` >= 1;

    -- Default the length to the statutory period for this procurement's own
    -- type and method combination.
    SELECT COALESCE(MAX(per.`days`), 0) INTO v_days
    FROM `statutory_periods` per
    JOIN `statutory_rules`      r ON r.`id` = per.`rule_id`
    JOIN `procurements`         p ON p.`procurement_type_id`   = r.`procurement_type_id`
                               AND p.`procurement_method_id` = r.`procurement_method_id`
    WHERE p.`id` = v_procurement AND per.`stage_id` = v_stage;

    -- The legacy text columns are still NOT NULL at this point (they are
    -- dropped in 6c), so they have to be populated for the insert to succeed.
    -- On a re-run the cursor matches nothing, so this never runs again.
    SET v_party_name = NULL;
    SELECT cat.`default_responsible_party` INTO v_party_name
    FROM `process_stages` cat WHERE cat.`id` = v_stage LIMIT 1;

    SET v_stage_name = NULL;
    SELECT cat.`name` INTO v_stage_name
    FROM `process_stages` cat WHERE cat.`id` = v_stage LIMIT 1;

    SET v_party_id = NULL;
    SELECT rp.`id` INTO v_party_id
    FROM `process_stages` cat
    JOIN `responsible_parties` rp ON rp.`name` = cat.`default_responsible_party`
    WHERE cat.`id` = v_stage LIMIT 1;

    INSERT INTO `procurement_stages`
      (`procurement_id`, `process_stage_id`, `stage_order`, `target_days`, `responsible_party_id`,
       `stage_name`, `responsible_party`)
    VALUES (v_procurement, v_stage, 1, v_days, v_party_id, v_stage_name, v_party_name);

    -- Keep the progress marker pointing at the same stage it did before.
    UPDATE `procurements`
    SET `current_stage_index` = `current_stage_index` + 1
    WHERE `id` = v_procurement AND `current_stage_index` >= 1;
  END LOOP;
  CLOSE cur;
END$$
DELIMITER ;
CALL `pdu_insert_issuance_stage`();

-- 6c. The same guard for stage names: a stage that matched no catalogue entry
--     is left with a NULL process_stage_id and the FK is not enforced.
DROP PROCEDURE IF EXISTS `pdu_enforce_stage_fks`;
DELIMITER $$
CREATE PROCEDURE `pdu_enforce_stage_fks`()
BEGIN
  DECLARE unmatched INT DEFAULT 0;
  SELECT COUNT(*) INTO unmatched FROM `procurement_stages` WHERE `process_stage_id` IS NULL;
  IF unmatched = 0 THEN
    ALTER TABLE `procurement_stages` MODIFY COLUMN `process_stage_id` TINYINT UNSIGNED NOT NULL;
    CALL `pdu_drop_column`('procurement_stages', 'stage_name');
    CALL `pdu_drop_column`('procurement_stages', 'responsible_party');
    CALL `pdu_add_constraint`('procurement_stages', 'fk_stage_stage', 'process_stage_id', 'process_stages');
    IF NOT EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'procurement_stages'
        AND INDEX_NAME = 'uq_stage_proc_order'
    ) THEN
      ALTER TABLE `procurement_stages` ADD UNIQUE KEY `uq_stage_proc_order` (`procurement_id`, `stage_order`);
    END IF;
    IF NOT EXISTS (
      SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'procurement_stages'
        AND CONSTRAINT_NAME = 'fk_stage_party'
    ) THEN
      ALTER TABLE `procurement_stages`
        ADD CONSTRAINT `fk_stage_party` FOREIGN KEY (`responsible_party_id`)
        REFERENCES `responsible_parties` (`id`) ON DELETE SET NULL;
    END IF;
  ELSE
    SELECT 'WARNING: procurement_stages rows could not be matched to a process stage; the stage foreign keys were NOT enforced. Fix the listed rows and re-run.' AS migration_warning;
  END IF;
END$$
DELIMITER ;
CALL `pdu_enforce_stage_fks`();

-- -------------------------------------------
-- 7. READ VIEWS
-- -------------------------------------------
-- 7a. Rebuilds the old wide shape from the normalised tables so the frontend
-- and any external reporting keep working without three joins per row.
CREATE OR REPLACE VIEW `statutory_rules_view` AS
SELECT
  t.code                AS procurement_type_code,
  t.name                AS procurement_type,
  m.code                AS method_code,
  m.name                AS method_name,
  MAX(CASE WHEN s.code = 'BID_DRAFT'      THEN COALESCE(p.days, v.resolved) END) AS bid_draft,
  MAX(CASE WHEN s.code = 'BID_ISSUANCE'   THEN COALESCE(p.days, v.resolved) END) AS bid_issuance,
  MAX(CASE WHEN s.code = 'BIDDING'        THEN COALESCE(p.days, v.resolved) END) AS bid_days,
  MAX(CASE WHEN s.code = 'EVALUATION'     THEN COALESCE(p.days, v.resolved) END) AS eval_days,
  MAX(CASE WHEN s.code = 'CC_DECISION'    THEN COALESCE(p.days, v.resolved) END) AS cc_decision_days,
  MAX(CASE WHEN s.code = 'BEB_DISPLAY'    THEN COALESCE(p.days, v.resolved) END) AS beb_days,
  MAX(CASE WHEN s.code = 'DRAFT_CONTRACT' THEN COALESCE(p.days, v.resolved) END) AS draft_contract_days,
  -- Upper bound for the two stages the spreadsheet states as a choice rather
  -- than a sequence, so the UI can offer the larger option without re-joining.
  MAX(CASE WHEN s.code = 'BIDDING'    THEN v.highest END) AS bid_days_max,
  MAX(CASE WHEN s.code = 'EVALUATION' THEN v.highest END) AS eval_days_max,
  GROUP_CONCAT(DISTINCT CONCAT(s.code, ':', p.period_mode) ORDER BY s.stage_order SEPARATOR ',') AS period_summary
FROM statutory_rules r
JOIN procurement_types   t ON t.id = r.procurement_type_id
JOIN procurement_methods m ON m.id = r.procurement_method_id
LEFT JOIN statutory_periods p ON p.rule_id = r.id
LEFT JOIN process_stages  s ON s.id = p.stage_id
LEFT JOIN (
  SELECT
    period_id,
    MAX(days) AS highest,
    -- SEQUENTIAL variants are one after another, so they add up. ALTERNATIVE
    -- variants are a choice, so only one applies and the shortest is the
    -- default; adding them together (10 + 15 = 25) would be meaningless.
    CASE WHEN COUNT(DISTINCT variant_kind) = 1 AND MIN(variant_kind) = 'ALTERNATIVE'
         THEN MIN(days)
         ELSE SUM(days)
    END AS resolved
  FROM statutory_period_variants
  GROUP BY period_id
) v ON v.period_id = p.id
GROUP BY t.code, t.name, m.code, m.name, t.sort_order, m.sort_order
ORDER BY t.sort_order, m.sort_order;

-- 7b. The worksheet, in long form. One row per type / method / stage,
--     including the stages a method does not use, so every cell of the source
--     sheet is represented and a cell the sheet leaves blank is a blank cell
--     rather than a missing row.
CREATE OR REPLACE VIEW `statutory_process_matrix` AS
SELECT
  t.`sort_order`                AS type_order,
  t.`name`                      AS procurement_type,
  t.`code`                      AS procurement_type_code,
  m.`sort_order`                AS method_order,
  m.`name`                      AS method,
  m.`code`                      AS method_code,
  s.`stage_order`,
  s.`code`                      AS stage_code,
  s.`name`                      AS stage,
  CASE s.`code`
    WHEN 'BID_DRAFT'      THEN 'bid draft and approval'
    WHEN 'BID_ISSUANCE'   THEN 'Issuance of the Bid'
    WHEN 'BIDDING'        THEN 'Bidding period'
    WHEN 'EVALUATION'     THEN 'Evaluation period (from date of opening)'
    WHEN 'CC_DECISION'    THEN 'Contracts committee decision period'
    WHEN 'BEB_DISPLAY'    THEN 'BEB Display'
    WHEN 'DRAFT_CONTRACT' THEN 'Submission of draft contract (From display of BEB)'
  END                           AS worksheet_column,
  -- The stage is either used with a fixed period, used with no fixed minimum,
  -- explicitly not applicable, or not part of the process at all.
  CASE
    WHEN p.`id` IS NULL                 THEN 'NOT_IN_PROCESS'
    ELSE p.`period_mode`
  END                           AS period_mode,
  CASE p.`period_mode`
    WHEN 'NO_MINIMUM'     THEN NULL
    WHEN 'NOT_APPLICABLE'  THEN NULL
    ELSE p.`days`
  END                           AS days,
  v.variant_count,
  v.variant_text,
  s.`date_role`,
  s.`anchors_from_code`,
  -- The cell exactly as it is written in the worksheet.
  -- The explicit COLLATE is required: this CASE mixes the variant text with
  -- CAST(days AS CHAR), which carries a binary collation, and the grid view
  -- cannot take MAX() over the mixture without it.
  CASE
    WHEN v.variant_text IS NOT NULL      THEN v.variant_text
    WHEN p.`period_mode` = 'NO_MINIMUM'    THEN 'No minimum'
    WHEN p.`period_mode` = 'NOT_APPLICABLE' THEN 'NA'
    WHEN p.`period_mode` = 'MANDATORY'     THEN CAST(p.`days` AS CHAR)
    ELSE ''
  END COLLATE utf8mb4_general_ci AS worksheet_value
FROM `statutory_rules` r
JOIN `procurement_types`   t ON t.`id` = r.`procurement_type_id`
JOIN `procurement_methods` m ON m.`id` = r.`procurement_method_id`
-- Cross join the stage catalogue so every rule is shown against all seven
-- stages. Joining the periods first would hide the stages a method omits.
CROSS JOIN `process_stages` s
LEFT JOIN `statutory_periods` p
       ON p.`rule_id` = r.`id`
      AND p.`stage_id` = s.`id`
LEFT JOIN (
  SELECT
    `period_id`,
    COUNT(*) AS variant_count,
    GROUP_CONCAT(
      CASE `variant_kind`
        WHEN 'ALTERNATIVE' THEN CONCAT(`days`, ' (', LOWER(`variant_name`), ')')
        ELSE CONCAT(`days`, ' ', LOWER(`variant_name`))
      END
      -- Alternatives share ordinal 1, so ordering by ordinal then by days
      -- puts the shortest option first, matching the sheet; sequential
      -- variants are already ordered by their ordinal.
      ORDER BY `ordinal`, `days` SEPARATOR ' / '
    ) AS variant_text
  FROM `statutory_period_variants`
  GROUP BY `period_id`
) v ON v.`period_id` = p.`id`;

-- 7c. The worksheet pivoted back into its original shape: 22 rows by 7 stage
--     columns, which is what the sheet looks like when opened.
CREATE OR REPLACE VIEW `statutory_reference_grid` AS
SELECT
  g.procurement_type,
  g.procurement_type_code,
  g.method,
  g.method_code,
  MAX(CASE WHEN g.stage_code = 'BID_DRAFT'      THEN g.worksheet_value END) AS bid_draft_and_approval,
  MAX(CASE WHEN g.stage_code = 'BID_ISSUANCE'   THEN g.worksheet_value END) AS issuance_of_the_bid,
  MAX(CASE WHEN g.stage_code = 'BIDDING'        THEN g.worksheet_value END) AS bidding_period,
  MAX(CASE WHEN g.stage_code = 'EVALUATION'     THEN g.worksheet_value END) AS evaluation_period,
  MAX(CASE WHEN g.stage_code = 'CC_DECISION'    THEN g.worksheet_value END) AS contracts_committee_decision,
  MAX(CASE WHEN g.stage_code = 'BEB_DISPLAY'    THEN g.worksheet_value END) AS beb_display,
  MAX(CASE WHEN g.stage_code = 'DRAFT_CONTRACT' THEN g.worksheet_value END) AS submission_of_draft_contract
FROM `statutory_process_matrix` g
GROUP BY g.type_order, g.procurement_type, g.procurement_type_code,
         g.method_order, g.method, g.method_code
ORDER BY g.type_order, g.method_order;

-- -------------------------------------------
-- 8. RESPONSIBLE PARTIES: PDU and Depoint only
-- -------------------------------------------
-- Any party outside the two accountable bodies is folded onto PDU, which runs the
-- process, and the now-unused rows are removed. The repoint has to happen before
-- the delete: the foreign key is ON DELETE SET NULL, so deleting first would
-- silently blank the party on those stages instead of failing.
UPDATE `procurement_stages` ps
JOIN `responsible_parties` rp ON rp.`id` = ps.`responsible_party_id`
SET ps.`responsible_party_id` = (SELECT `id` FROM `responsible_parties` WHERE `name` = 'PDU' LIMIT 1)
WHERE rp.`name` NOT IN ('PDU', 'Depoint');

DELETE FROM `responsible_parties` WHERE `name` NOT IN ('PDU', 'Depoint');

-- The stage catalogue stores the party as free text, so align it too.
UPDATE `process_stages` SET `default_responsible_party` = 'PDU'
WHERE `default_responsible_party` IS NOT NULL
  AND `default_responsible_party` NOT IN ('PDU', 'Depoint');

-- -------------------------------------------
-- 9. CLEANUP + POST-MIGRATION CHECKS
-- -------------------------------------------
DROP PROCEDURE IF EXISTS `pdu_carry_legacy_rules`;
DROP PROCEDURE IF EXISTS `pdu_align_collation`;
DROP PROCEDURE IF EXISTS `pdu_add_column`;
DROP PROCEDURE IF EXISTS `pdu_drop_column`;
DROP PROCEDURE IF EXISTS `pdu_add_constraint`;
DROP PROCEDURE IF EXISTS `pdu_enforce_procurement_fks`;
DROP PROCEDURE IF EXISTS `pdu_enforce_stage_fks`;
DROP PROCEDURE IF EXISTS `pdu_backfill_stages`;
DROP PROCEDURE IF EXISTS `pdu_backfill_procurements`;
DROP PROCEDURE IF EXISTS `pdu_insert_issuance_stage`;
DROP PROCEDURE IF EXISTS `pdu_harvest_parties`;
DROP PROCEDURE IF EXISTS `pdu_add_index`;

-- Any procurement that could not be matched to a lookup, if the guards above
-- skipped the foreign keys.
SELECT p.`id`, p.`ref_no`, p.`procurement_type_id`, p.`procurement_method_id`
FROM `procurements` p
WHERE p.`procurement_type_id` IS NULL OR p.`procurement_method_id` IS NULL;

SELECT 'statutory_rules'      AS table_name, COUNT(*) AS rows_now FROM `statutory_rules`
UNION ALL SELECT 'statutory_periods',     COUNT(*) FROM `statutory_periods`
UNION ALL SELECT 'statutory_period_variants', COUNT(*) FROM `statutory_period_variants`
UNION ALL SELECT 'process_stages',        COUNT(*) FROM `process_stages`
UNION ALL SELECT 'procurements',          COUNT(*) FROM `procurements`
UNION ALL SELECT 'procurement_stages',    COUNT(*) FROM `procurement_stages`
UNION ALL SELECT 'users',                 COUNT(*) FROM `users`;
