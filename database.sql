-- ============================================
-- PDU & Depoint Procurement Management System
-- Database: pdu
-- Server:   WAMP (MySQL 8.x)
--
-- NORMALISED SCHEMA (3NF)
--
-- Source of statutory periods:
--   "Procurement Process System Periods.xlsx" (Sheet1)
--
-- Changes vs. the previous wide-table design:
--   1. procurement_type / method free text  -> procurement_types + procurement_methods lookups
--   2. stage names repeated per row / hardcoded in PHP -> process_stages lookup
--   3. responsible_party free text         -> responsible_parties lookup
--   4. public holidays hardcoded in two PHP files -> public_holidays lookup
--   5. statutory_rules wide row (bid_days, eval_days, ...) split into
--      statutory_rules + statutory_periods (+ statutory_period_variants)
--   6. "Issuance of the Bid" period added (present in the source sheet,
--      previously unrepresented)
--   7. Multi-valued cells such as "10 (individuals)/15(firms)" are no longer
--      crammed into one column - they are rows in statutory_period_variants
--   8. "No minimum" / "NA" / "Not applicable" are explicit period_mode values
--      instead of magic zeros
--
-- This script is idempotent: it is safe to run repeatedly.
-- ============================================

-- The schema is whatever the client selected, so the caller always chooses the
-- target and this script can never switch to the live database on its own:
--   mysql -u root pdu        < database.sql     # the live database
--   mysql -u root pdu_fresh  < database.sql     # a throwaway copy
-- A hardcoded `USE pdu` here would ignore that choice and rebuild `pdu` even
-- when another schema was requested, and neither PREPARE nor a stored program
-- can run USE, so there is deliberately no schema switch in this file.
SET @pdu_schema := IFNULL(DATABASE(), 'pdu');

SET @ddl := CONCAT('CREATE DATABASE IF NOT EXISTS `', @pdu_schema, '`',
                   ' CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
PREPARE ddl FROM @ddl; EXECUTE ddl; DEALLOCATE PREPARE ddl;

-- -------------------------------------------
-- 1. LOOKUPS
-- -------------------------------------------

-- 1a. Procurement types (was: procurements.type ENUM + stray procurement_types table)
CREATE TABLE IF NOT EXISTS `procurement_types` (
  `id`         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pt_code` (`code`),
  UNIQUE KEY `uq_pt_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1b. Procurement methods (was: statutory_rules.method_code + method_name per row)
CREATE TABLE IF NOT EXISTS `procurement_methods` (
  `id`         TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pm_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1c. Process stage catalogue (was: hardcoded DEFAULT_STAGE_NAMES in index.php)
--     `anchors_from_code` replaces the hardcoded index anchors in the JS timeline:
--       the draft contract is measured from the Contracts Committee decision date.
--     `date_role` replaces the hardcoded dates[4] / dates[5] Beb/completion lookups.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1d. Responsible parties (was: procurement_stages.responsible_party VARCHAR)
CREATE TABLE IF NOT EXISTS `responsible_parties` (
  `id`   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rp_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1e. Public holidays (was: duplicated array in index.php + deadline_reminder_service.php)
CREATE TABLE IF NOT EXISTS `public_holidays` (
  `id`         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mmdd`       CHAR(5) NOT NULL,
  `description` VARCHAR(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ph_mmdd` (`mmdd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------
-- 2. USERS
-- -------------------------------------------
-- NOTE: role values are 'admin' | 'user' to match the running application.
-- The previous database.sql declared 'PDU' | 'Depoint', which did not match
-- index.php (currentUser.role === 'admin').
CREATE TABLE IF NOT EXISTS `users` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`     VARCHAR(50)  NOT NULL,
  `display_name` VARCHAR(100) DEFAULT NULL,
  `phone`        VARCHAR(20)  NOT NULL,
  `email`        VARCHAR(100) DEFAULT NULL,
  `password`     VARCHAR(255) NOT NULL,
  `role`         ENUM('admin','user') NOT NULL DEFAULT 'user',
  `entity`       VARCHAR(100) NOT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_phone`    (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------
-- 3. PROCUREMENTS
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `procurements` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_no`              VARCHAR(50)  NOT NULL,
  `procurement_type_id` TINYINT UNSIGNED NOT NULL,
  `procurement_method_id` TINYINT UNSIGNED NOT NULL,
  `entity`              VARCHAR(100) NOT NULL,
  `title`               VARCHAR(255) NOT NULL,
  `start_date`          DATE         NOT NULL,
  `beb_date`            DATE         DEFAULT NULL,
  `completion_date`     DATE         DEFAULT NULL,
  `current_stage_index` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`          INT UNSIGNED DEFAULT NULL,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ref_no` (`ref_no`),
  KEY `fk_proc_type`   (`procurement_type_id`),
  KEY `fk_proc_method` (`procurement_method_id`),
  KEY `fk_proc_user`   (`created_by`),
  CONSTRAINT `fk_proc_type`   FOREIGN KEY (`procurement_type_id`)   REFERENCES `procurement_types` (`id`),
  CONSTRAINT `fk_proc_method` FOREIGN KEY (`procurement_method_id`) REFERENCES `procurement_methods` (`id`),
  CONSTRAINT `fk_proc_user`   FOREIGN KEY (`created_by`)            REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------
-- 4. PROCUREMENT STAGES
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `procurement_stages` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `procurement_id`      INT UNSIGNED NOT NULL,
  `process_stage_id`    TINYINT UNSIGNED NOT NULL,
  `stage_order`         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `target_days`         INT UNSIGNED NOT NULL DEFAULT 0,
  `responsible_party_id` SMALLINT UNSIGNED DEFAULT NULL,
  `target_date`         DATE         DEFAULT NULL,
  `completed_at`        DATE         DEFAULT NULL,
  `comment`             TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stage_proc_order` (`procurement_id`, `stage_order`),
  KEY `fk_stage_proc`  (`procurement_id`),
  KEY `fk_stage_stage` (`process_stage_id`),
  KEY `fk_stage_party` (`responsible_party_id`),
  CONSTRAINT `fk_stage_proc`  FOREIGN KEY (`procurement_id`)      REFERENCES `procurements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stage_stage` FOREIGN KEY (`process_stage_id`)    REFERENCES `process_stages` (`id`),
  CONSTRAINT `fk_stage_party` FOREIGN KEY (`responsible_party_id`) REFERENCES `responsible_parties` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------
-- 5. DEADLINE REMINDER DELIVERY LOG
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `deadline_reminder_deliveries` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stage_id`   INT UNSIGNED NOT NULL,
  `target_date` DATE NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `sent_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reminder` (`stage_id`, `target_date`, `user_id`),
  KEY `fk_reminder_stage` (`stage_id`),
  KEY `fk_reminder_user`  (`user_id`),
  CONSTRAINT `fk_reminder_stage` FOREIGN KEY (`stage_id`) REFERENCES `procurement_stages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_reminder_user`  FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------
-- SEED DATA: Lookups
-- -------------------------------------------
INSERT INTO `procurement_types` (`code`, `name`, `sort_order`) VALUES
  ('SUPPLIES',     'Supplies & Non-Consultancy', 1),
  ('WORKS',        'Works',                          2),
  ('CONSULTANCIES','Consultancies',                  3)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `sort_order` = VALUES(`sort_order`);

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

-- 7-stage catalogue. Both the draft contract and BEB display periods are
-- anchored to the Contracts Committee decision date.
-- Only the two accountable bodies are used: PDU runs the process and Depoint is
-- the procuring entity that drafts, issues and receives the bid.
-- Free both final catalogue orders before swapping them under the unique key.
UPDATE `process_stages` SET `stage_order` = 254 WHERE `code` = 'BEB_DISPLAY';
UPDATE `process_stages` SET `stage_order` = 255 WHERE `code` = 'DRAFT_CONTRACT';

INSERT INTO `process_stages` (`code`, `name`, `stage_order`, `default_responsible_party`, `anchors_from_code`, `date_role`) VALUES
  ('BID_DRAFT',      'Bid Draft Preparation',                  0, 'Depoint',    NULL,             NULL),
  ('BID_ISSUANCE',   'Issuance of the Bid',                     1, 'Depoint',    NULL,             NULL),
  ('BIDDING',        'Bidding / Proposal Submission Period',    2, 'Depoint',    NULL,             NULL),
  ('EVALUATION',     'Evaluation Period',                       3, 'PDU',        NULL,             NULL),
  ('CC_DECISION',    'Contracts Committee Decision',            4, 'PDU',        NULL,             NULL),
  ('DRAFT_CONTRACT', 'Submission of Draft Contract & Completion', 5, 'PDU',   'CC_DECISION',    'COMPLETION'),
  ('BEB_DISPLAY',    'BEB Display Window',                      6, 'PDU',   'CC_DECISION',    'BEB')
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`),
  `stage_order` = VALUES(`stage_order`),
  `default_responsible_party` = VALUES(`default_responsible_party`),
  `anchors_from_code` = VALUES(`anchors_from_code`),
  `date_role` = VALUES(`date_role`);

-- Migrate records saved with BEB before Draft Contract. Keep the active stage
-- pointed at the same stage code while swapping its stored display order.
DROP TEMPORARY TABLE IF EXISTS `tmp_procurement_stage_order`;
CREATE TEMPORARY TABLE `tmp_procurement_stage_order` AS
SELECT b.`procurement_id`, b.`stage_order` AS `beb_old_order`,
       d.`stage_order` AS `draft_old_order`,
       CASE
         WHEN p.`current_stage_index` = b.`stage_order` THEN d.`stage_order`
         WHEN p.`current_stage_index` = d.`stage_order` THEN b.`stage_order`
         ELSE p.`current_stage_index`
       END AS `new_current_stage_index`
FROM `procurement_stages` b
JOIN `process_stages` bc ON bc.`id` = b.`process_stage_id` AND bc.`code` = 'BEB_DISPLAY'
JOIN `procurement_stages` d ON d.`procurement_id` = b.`procurement_id`
JOIN `process_stages` dc ON dc.`id` = d.`process_stage_id` AND dc.`code` = 'DRAFT_CONTRACT'
JOIN `procurements` p ON p.`id` = b.`procurement_id`
WHERE b.`stage_order` < d.`stage_order`;

UPDATE `procurements` p
JOIN `tmp_procurement_stage_order` x ON x.`procurement_id` = p.`id`
SET p.`current_stage_index` = x.`new_current_stage_index`;

UPDATE `procurement_stages` s
JOIN `process_stages` c ON c.`id` = s.`process_stage_id`
JOIN `tmp_procurement_stage_order` x ON x.`procurement_id` = s.`procurement_id`
SET s.`stage_order` = s.`stage_order` + 100
WHERE c.`code` IN ('BEB_DISPLAY', 'DRAFT_CONTRACT');

UPDATE `procurement_stages` s
JOIN `process_stages` c ON c.`id` = s.`process_stage_id`
JOIN `tmp_procurement_stage_order` x ON x.`procurement_id` = s.`procurement_id`
SET s.`stage_order` = x.`draft_old_order`
WHERE c.`code` = 'BEB_DISPLAY';

UPDATE `procurement_stages` s
JOIN `process_stages` c ON c.`id` = s.`process_stage_id`
JOIN `tmp_procurement_stage_order` x ON x.`procurement_id` = s.`procurement_id`
SET s.`stage_order` = x.`beb_old_order`
WHERE c.`code` = 'DRAFT_CONTRACT';

DROP TEMPORARY TABLE `tmp_procurement_stage_order`;

-- Only the two accountable bodies. Stages and procurements that referenced the
-- earlier committee names are repointed onto these by the cleanup section below.
INSERT INTO `responsible_parties` (`name`) VALUES
  ('PDU'),
  ('Depoint')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- A fresh install has no procurement data yet, but this script is documented as
-- safe to re-run, so fold any leftover party onto PDU and drop the unused rows.
-- The repoint precedes the delete because the foreign key is ON DELETE SET NULL:
-- deleting first would blank those stages instead of failing.
UPDATE `procurement_stages` ps
JOIN `responsible_parties` rp ON rp.`id` = ps.`responsible_party_id`
SET ps.`responsible_party_id` = (SELECT `id` FROM `responsible_parties` WHERE `name` = 'PDU' LIMIT 1)
WHERE rp.`name` NOT IN ('PDU', 'Depoint');

DELETE FROM `responsible_parties` WHERE `name` NOT IN ('PDU', 'Depoint');

-- The stage catalogue stores the party as free text, so align it too.
UPDATE `process_stages` SET `default_responsible_party` = 'PDU'
WHERE `default_responsible_party` IS NOT NULL
  AND `default_responsible_party` NOT IN ('PDU', 'Depoint');

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
-- 6a. STATUTORY RULES  (procurement type x method)
-- One row per legal type/method combination. Idempotent.
-- ---------------------------------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `statutory_rules` (`procurement_type_id`, `procurement_method_id`)
SELECT t.`id`, m.`id`
FROM (
  SELECT 'SUPPLIES' AS tc, 'ODB' AS mc
UNION ALL
  SELECT 'SUPPLIES', 'OIB'
UNION ALL
  SELECT 'SUPPLIES', 'RDB'
UNION ALL
  SELECT 'SUPPLIES', 'RIB'
UNION ALL
  SELECT 'SUPPLIES', 'RFQ'
UNION ALL
  SELECT 'SUPPLIES', 'PREQUAL'
UNION ALL
  SELECT 'SUPPLIES', 'MICRO'
UNION ALL
  SELECT 'SUPPLIES', 'DIRECT'
UNION ALL
  SELECT 'WORKS', 'ODB'
UNION ALL
  SELECT 'WORKS', 'OIB'
UNION ALL
  SELECT 'WORKS', 'RDB'
UNION ALL
  SELECT 'WORKS', 'RIB'
UNION ALL
  SELECT 'WORKS', 'RFQ'
UNION ALL
  SELECT 'WORKS', 'MICRO'
UNION ALL
  SELECT 'WORKS', 'DIRECT'
UNION ALL
  SELECT 'CONSULTANCIES', 'ODB'
UNION ALL
  SELECT 'CONSULTANCIES', 'OIB'
UNION ALL
  SELECT 'CONSULTANCIES', 'RDB'
UNION ALL
  SELECT 'CONSULTANCIES', 'RIB'
UNION ALL
  SELECT 'CONSULTANCIES', 'RFQ'
UNION ALL
  SELECT 'CONSULTANCIES', 'EOI_UG'
UNION ALL
  SELECT 'CONSULTANCIES', 'EOI_INT'
) AS x
JOIN `procurement_types`   t ON t.`code` = x.tc
JOIN `procurement_methods` m ON m.`code` = x.mc
ON DUPLICATE KEY UPDATE `procurement_method_id` = `procurement_method_id`;

-- 6b. STATUTORY PERIODS  (one row per rule per stage = 1NF)
-- Replaces the wide bid_days / eval_days / ... columns of the old table.
-- period_mode: MANDATORY | NO_MINIMUM | NOT_APPLICABLE
-- days is NULL when the value is context-dependent (see 6c variants).
-- Idempotent via the (rule_id, stage_id) unique key.
-- ---------------------------------------------------------------------
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
  CONSTRAINT `fk_sp_stage` FOREIGN KEY (`stage_id`) REFERENCES `process_stages` (`id`),
  CONSTRAINT `chk_sp_days` CHECK (
    (`period_mode` = 'MANDATORY' AND `days` IS NOT NULL)
    OR (`period_mode` <> 'MANDATORY' AND `days` IS NULL)
    OR (`period_mode` = 'MANDATORY' AND `days` IS NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `statutory_periods` (`rule_id`, `stage_id`, `period_mode`, `days`, `note`)
SELECT r.`id`, s.`id`, x.pm, x.d, x.nt
FROM (
  SELECT 'SUPPLIES' AS tc, 'ODB' AS mc, 'BID_DRAFT' AS sc, 'MANDATORY' AS pm, 5 AS d, NULL AS nt
UNION ALL
  SELECT 'SUPPLIES', 'ODB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'SUPPLIES', 'ODB', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL
  SELECT 'SUPPLIES', 'ODB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'ODB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'SUPPLIES', 'ODB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'ODB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'OIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'OIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'SUPPLIES', 'OIB', 'BIDDING', 'MANDATORY', 20, NULL
UNION ALL
  SELECT 'SUPPLIES', 'OIB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'OIB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'SUPPLIES', 'OIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'OIB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RDB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RDB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RDB', 'BIDDING', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RDB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RDB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RDB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RDB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RIB', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RIB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RIB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RIB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RFQ', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RFQ', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RFQ', 'BIDDING', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RFQ', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RFQ', 'CC_DECISION', 'MANDATORY', 1, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RFQ', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'RFQ', 'DRAFT_CONTRACT', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'SUPPLIES', 'PREQUAL', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'SUPPLIES', 'PREQUAL', 'BIDDING', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'SUPPLIES', 'PREQUAL', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'PREQUAL', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'MICRO', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'SUPPLIES', 'MICRO', 'BIDDING', 'NO_MINIMUM', NULL, NULL
UNION ALL
  SELECT 'SUPPLIES', 'MICRO', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'MICRO', 'DRAFT_CONTRACT', 'NOT_APPLICABLE', NULL, NULL
UNION ALL
  SELECT 'SUPPLIES', 'DIRECT', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'SUPPLIES', 'DIRECT', 'BIDDING', 'NOT_APPLICABLE', NULL, NULL
UNION ALL
  SELECT 'SUPPLIES', 'DIRECT', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'SUPPLIES', 'DIRECT', 'DRAFT_CONTRACT', 'NOT_APPLICABLE', NULL, NULL
UNION ALL
  SELECT 'WORKS', 'ODB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'ODB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'WORKS', 'ODB', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL
  SELECT 'WORKS', 'ODB', 'EVALUATION', 'MANDATORY', 15, NULL
UNION ALL
  SELECT 'WORKS', 'ODB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'ODB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'WORKS', 'ODB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'OIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'OIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'WORKS', 'OIB', 'BIDDING', 'MANDATORY', 20, NULL
UNION ALL
  SELECT 'WORKS', 'OIB', 'EVALUATION', 'MANDATORY', 15, NULL
UNION ALL
  SELECT 'WORKS', 'OIB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'OIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'WORKS', 'OIB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'RDB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'RDB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'WORKS', 'RDB', 'BIDDING', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'WORKS', 'RDB', 'EVALUATION', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'WORKS', 'RDB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'WORKS', 'RDB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'WORKS', 'RDB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'RIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'RIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'WORKS', 'RIB', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL
  SELECT 'WORKS', 'RIB', 'EVALUATION', 'MANDATORY', 15, NULL
UNION ALL
  SELECT 'WORKS', 'RIB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'WORKS', 'RIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'WORKS', 'RIB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'RFQ', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'RFQ', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL
  SELECT 'WORKS', 'RFQ', 'BIDDING', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'RFQ', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'RFQ', 'CC_DECISION', 'MANDATORY', 1, NULL
UNION ALL
  SELECT 'WORKS', 'RFQ', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'WORKS', 'RFQ', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'MICRO', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'MICRO', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL
  SELECT 'WORKS', 'MICRO', 'BIDDING', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'MICRO', 'EVALUATION', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'MICRO', 'CC_DECISION', 'MANDATORY', 1, NULL
UNION ALL
  SELECT 'WORKS', 'MICRO', 'BEB_DISPLAY', 'MANDATORY', 0, NULL
UNION ALL
  SELECT 'WORKS', 'MICRO', 'DRAFT_CONTRACT', 'NOT_APPLICABLE', NULL, NULL
UNION ALL
  SELECT 'WORKS', 'DIRECT', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'DIRECT', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL
  SELECT 'WORKS', 'DIRECT', 'BIDDING', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'WORKS', 'DIRECT', 'EVALUATION', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'WORKS', 'DIRECT', 'CC_DECISION', 'MANDATORY', 1, NULL
UNION ALL
  SELECT 'WORKS', 'DIRECT', 'BEB_DISPLAY', 'MANDATORY', 0, NULL
UNION ALL
  SELECT 'WORKS', 'DIRECT', 'DRAFT_CONTRACT', 'NOT_APPLICABLE', NULL, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'ODB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'ODB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'ODB', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL
  SELECT 'CONSULTANCIES', 'ODB', 'EVALUATION', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL
  SELECT 'CONSULTANCIES', 'ODB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'ODB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'ODB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'OIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'OIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'OIB', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL
  SELECT 'CONSULTANCIES', 'OIB', 'EVALUATION', 'MANDATORY', 20, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'OIB', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'OIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'OIB', 'DRAFT_CONTRACT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RDB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RDB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RDB', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL
  SELECT 'CONSULTANCIES', 'RDB', 'EVALUATION', 'MANDATORY', 20, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RDB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RDB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RDB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RIB', 'BID_DRAFT', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RIB', 'BID_ISSUANCE', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RIB', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL
  SELECT 'CONSULTANCIES', 'RIB', 'EVALUATION', 'MANDATORY', 20, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RIB', 'CC_DECISION', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RIB', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RIB', 'DRAFT_CONTRACT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RFQ', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RFQ', 'BID_ISSUANCE', 'MANDATORY', 1, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RFQ', 'BIDDING', 'MANDATORY', NULL, 'See statutory_period_variants'
UNION ALL
  SELECT 'CONSULTANCIES', 'RFQ', 'EVALUATION', 'MANDATORY', 5, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RFQ', 'CC_DECISION', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RFQ', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'RFQ', 'DRAFT_CONTRACT', 'MANDATORY', 2, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'EOI_UG', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'EOI_UG', 'BIDDING', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'EOI_UG', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'EOI_INT', 'BID_DRAFT', 'MANDATORY', 3, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'EOI_INT', 'BIDDING', 'MANDATORY', 15, NULL
UNION ALL
  SELECT 'CONSULTANCIES', 'EOI_INT', 'BEB_DISPLAY', 'MANDATORY', 10, NULL
) AS x
JOIN `procurement_types`   t ON t.`code` = x.tc
JOIN `procurement_methods` m ON m.`code` = x.mc
JOIN `statutory_rules`     r ON r.`procurement_type_id` = t.`id` AND r.`procurement_method_id` = m.`id`
JOIN `process_stages`      s ON s.`code` = x.sc
ON DUPLICATE KEY UPDATE
  `period_mode` = VALUES(`period_mode`),
  `days`        = VALUES(`days`),
  `note`        = VALUES(`note`);

-- 6c. STATUTORY PERIOD VARIANTS
-- Normalises the multi-valued spreadsheet cells that cannot fit a column:
--   Consultancies BIDDING "10 (individuals)/15(firms)"   -> ALTERNATIVE
--   Consultancies ODB EVAL "15(technical) / 02(financial)" -> SEQUENTIAL
-- Idempotent via the (period_id, variant_code) unique key.
-- ---------------------------------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'ODB' AND s.`code` = 'BIDDING'
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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'ODB' AND s.`code` = 'BIDDING'
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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'OIB' AND s.`code` = 'BIDDING'
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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'OIB' AND s.`code` = 'BIDDING'
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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'RDB' AND s.`code` = 'BIDDING'
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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'RDB' AND s.`code` = 'BIDDING'
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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'RIB' AND s.`code` = 'BIDDING'
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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'RIB' AND s.`code` = 'BIDDING'
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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'RFQ' AND s.`code` = 'BIDDING'
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
WHERE t.`code` = 'CONSULTANCIES' AND m.`code` = 'RFQ' AND s.`code` = 'BIDDING'
ON DUPLICATE KEY UPDATE
  `variant_name` = VALUES(`variant_name`),
  `variant_kind` = VALUES(`variant_kind`),
  `ordinal`      = VALUES(`ordinal`),
  `days`         = VALUES(`days`);

-- -------------------------------------------
-- 7. PROCUREMENT AUDIT HISTORY
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `procurement_audit_log` (
  `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `procurement_id`     INT UNSIGNED NOT NULL,
  `procurement_ref_no` VARCHAR(50) NOT NULL,
  `user_id`            INT UNSIGNED NOT NULL,
  `username`           VARCHAR(50) NOT NULL,
  `action`             VARCHAR(32) NOT NULL,
  `details`            LONGTEXT NOT NULL,
  `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_procurement` (`procurement_id`, `id`),
  KEY `idx_audit_user` (`user_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -------------------------------------------
-- SEED DATA: Default Users
-- -------------------------------------------
INSERT INTO `users` (`username`, `display_name`, `phone`, `email`, `password`, `role`, `entity`) VALUES
  ('+256700000001', 'PDU_admin', '+256700000001', 'admin@PDU.go.ug', '$2y$12$0btQXfGjIvGSRxU0dL/4j.8NU4KVjQ/C2d8q8sTS2C8CDmEoMds.2', 'admin', 'PDU'),
  ('+256700000002', 'Depoint_officer', '+256700000002', 'info@Depoint.go.ug', '$2y$12$0btQXfGjIvGSRxU0dL/4j.8NU4KVjQ/C2d8q8sTS2C8CDmEoMds.2', 'user', 'Depoint')
ON DUPLICATE KEY UPDATE `display_name` = VALUES(`display_name`), `role` = VALUES(`role`);

-- -------------------------------------------
-- Convenient read view: the old wide shape, rebuilt from the normalised tables.
-- The application reads this instead of querying three tables per row.
-- `bidding_days` / `evaluation_days` fall back to the total of any variants
-- so the UI always has a usable number to prefill.
-- -------------------------------------------
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

-- -------------------------------------------
-- 8. WORKSHEET REFERENCE VIEWS
-- -------------------------------------------
-- Two read-only views that present the normalised data the way the source
-- worksheet is laid out, so the rules can be checked against the spreadsheet
-- without reading the joins by hand.

-- 8a. One row per type / method / stage, including the stages a method does not
--     use. This is the long form of the worksheet: every cell of the sheet
--     becomes exactly one row, and a cell the sheet leaves blank comes through
--     as a blank cell rather than as a missing row.
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

-- 8b. One row per type and method, with a column per stage. This is the
--     worksheet itself: 22 rows by 7 stage columns.
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
