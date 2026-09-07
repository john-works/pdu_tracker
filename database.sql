-- ============================================
-- PPDA & JJK Procurement Management System
-- Database: pdu
-- Server:   WAMP (MySQL/MariaDB)
-- ============================================

CREATE DATABASE IF NOT EXISTS `pdu`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `pdu`;

-- -------------------------------------------
-- 1. USERS
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(50)  NOT NULL,
  `phone`      VARCHAR(20)  NOT NULL,
  `email`      VARCHAR(100) DEFAULT NULL,
  `password`   VARCHAR(255) NOT NULL,
  `role`       ENUM('PPDA','JJK') NOT NULL DEFAULT 'JJK',
  `entity`     VARCHAR(100) NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `uq_phone`    (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------
-- 2. PROCUREMENTS
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `procurements` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref_no`              VARCHAR(50)  NOT NULL,
  `type`                ENUM('Supplies & Non-Consultancy','Works','Consultancies') NOT NULL,
  `entity`              VARCHAR(100) NOT NULL,
  `title`               VARCHAR(255) NOT NULL,
  `method`              VARCHAR(20)  NOT NULL,
  `start_date`          DATE         NOT NULL,
  `beb_date`            DATE         DEFAULT NULL,
  `completion_date`     DATE         DEFAULT NULL,
  `current_stage_index` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`          INT UNSIGNED DEFAULT NULL,
  `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ref_no` (`ref_no`),
  KEY `fk_proc_user` (`created_by`),
  CONSTRAINT `fk_proc_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------
-- 3. PROCUREMENT STAGES
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `procurement_stages` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `procurement_id`   INT UNSIGNED NOT NULL,
  `stage_name`       VARCHAR(100) NOT NULL,
  `stage_order`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `target_days`      INT UNSIGNED NOT NULL DEFAULT 0,
  `responsible_party` VARCHAR(100) DEFAULT NULL,
  `target_date`      DATE         DEFAULT NULL,
  `comment`          TEXT         DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_stage_proc` (`procurement_id`),
  CONSTRAINT `fk_stage_proc` FOREIGN KEY (`procurement_id`) REFERENCES `procurements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------
-- 4. STATUTORY RULES (configurable lookup)
-- -------------------------------------------
CREATE TABLE IF NOT EXISTS `statutory_rules` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `procurement_type`   VARCHAR(50)  NOT NULL,
  `method_code`        VARCHAR(20)  NOT NULL,
  `method_name`        VARCHAR(100) NOT NULL,
  `bid_draft`          INT NOT NULL DEFAULT 0,
  `bid_days`           INT UNSIGNED NOT NULL DEFAULT 0,
  `eval_days`          INT UNSIGNED NOT NULL DEFAULT 0,
  `cc_decision_days`   INT UNSIGNED NOT NULL DEFAULT 0,
  `beb_days`           INT UNSIGNED NOT NULL DEFAULT 0,
  `draft_contract_days` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_type_method` (`procurement_type`, `method_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -------------------------------------------
-- SEED DATA: Default Users
-- -------------------------------------------
INSERT INTO `users` (`id`, `username`, `phone`, `email`, `password`, `role`, `entity`) VALUES
(1, 'ppda_admin', '+256700000001', 'admin@ppda.go.ug', '$2y$12$0btQXfGjIvGSRxU0dL/4j.8NU4KVjQ/C2d8q8sTS2C8CDmEoMds.2', 'PPDA', 'PPDA Regulator'),
(2, 'jjk_officer', '+256700000002', 'info@jjk.go.ug', '$2y$12$0btQXfGjIvGSRxU0dL/4j.8NU4KVjQ/C2d8q8sTS2C8CDmEoMds.2', 'JJK', 'JJK Procuring Entity')
ON DUPLICATE KEY UPDATE `username` = VALUES(`username`);

-- -------------------------------------------
-- SEED DATA: Statutory Rules
-- -------------------------------------------
INSERT INTO `statutory_rules` (`procurement_type`, `method_code`, `method_name`, `bid_draft`, `bid_days`, `eval_days`, `cc_decision_days`, `beb_days`, `draft_contract_days`) VALUES
-- Supplies & Non-Consultancy
('Supplies & Non-Consultancy', 'ODB',  'Open Domestic Bidding',              5, 10, 5, 10, 5, 3),
('Supplies & Non-Consultancy', 'OIB',  'Open International Bidding',         5, 20, 5, 10, 5, 3),
('Supplies & Non-Consultancy', 'RDB',  'Restricted Domestic Bidding',         3,  5, 5, 10, 3, 2),
('Supplies & Non-Consultancy', 'RIB',  'Restricted International Bidding',   5, 15, 5, 10, 3, 2),
('Supplies & Non-Consultancy', 'RFQ',  'Request for Quotations',             3,  5, 5, 10, 3, 1),
('Supplies & Non-Consultancy', 'PREQUAL','Prequalification (Open)',          5, 10, 5,  0, 5, 0),
('Supplies & Non-Consultancy', 'MICRO','Micro Procurement',                   0,  5, 0,  0, 0, 0),
('Supplies & Non-Consultancy', 'DIRECT','Direct Procurement',                 0,  5, 0,  0, 0, 0),
-- Works
('Works', 'ODB',  'Open Domestic Bidding',              5, 15, 10, 10, 5, 3),
('Works', 'OIB',  'Open International Bidding',         5, 20, 10, 10, 5, 3),
('Works', 'RDB',  'Restricted Domestic Bidding',        5, 10, 10, 10, 3, 2),
('Works', 'RIB',  'Restricted International Bidding',   5, 15, 10, 10, 3, 2),
('Works', 'RFQ',  'Request for Quotations',              3,  5, 10, 10, 3, 1),
('Works', 'MICRO','Micro Procurement',                    0,  5,  0,  0, 0, 0),
('Works', 'DIRECT','Direct Procurement',                  0,  5,  0,  0, 0, 5),
-- Consultancies
('Consultancies', 'ODB',     'Open Domestic Bidding',                5, 15, 17, 10, 0, 3),
('Consultancies', 'OIB',     'Open International Bidding',           5, 15, 17, 10, 0, 3),
('Consultancies', 'RDB',     'Restricted Domestic Bidding',          5, 15, 17, 10, 0, 2),
('Consultancies', 'RIB',     'Restricted International Bidding',     5, 15, 17, 10, 0, 3),
('Consultancies', 'RFQ',     'Request for Quotations',               5, 15, 17, 10, 0, 3),
('Consultancies', 'EOI_UG',  'Expression of Interest (Uganda)',      5, 10,  0, 10, 0, 0),
('Consultancies', 'EOI_INT', 'Expression of Interest (International)',5, 15,  0, 10, 0, 0)
ON DUPLICATE KEY UPDATE `bid_days` = VALUES(`bid_days`);

-- -------------------------------------------
-- SEED DATA: Sample Procurement
-- -------------------------------------------
INSERT INTO `procurements` (`id`, `ref_no`, `type`, `entity`, `title`, `method`, `start_date`, `beb_date`, `completion_date`, `current_stage_index`, `created_by`) VALUES
(1, 'JJK/WORKS/2026/001', 'Works', 'JJK Procuring Entity', 'Renovation of Admin Block', 'ODB', '2026-07-01', '2026-08-10', '2026-08-13', 1, 2)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

INSERT INTO `procurement_stages` (`procurement_id`, `stage_name`, `stage_order`, `target_days`, `responsible_party`, `target_date`) VALUES
(1, 'Bid Draft Preparation',                0,  5, 'JJK Procuring Entity',   '2026-07-08'),
(1, 'Bidding / Proposal Submission Period',  1, 15, 'JJK Procuring Entity',   '2026-07-16'),
(1, 'Evaluation Period',                     2, 10, 'Evaluation Committee',    '2026-07-26'),
(1, 'Contracts Committee Decision',          3, 10, 'Contracts Committee',     '2026-08-05'),
(1, 'BEB Display Window',                    4,  5, 'Accounting Officer',      '2026-08-10'),
(1, 'Submission of Draft Contract & Completion', 5, 3, 'User Department',       '2026-08-13');
