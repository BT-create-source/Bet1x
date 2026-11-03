-- =================================================================================================
-- bet1x — OPTIONAL MySQL triggers: sub-admin financial records are permanent
-- =================================================================================================
--
-- Run AFTER schema-mysql.sql. The MySQL twin of the subadmin_protect_records() triggers in
-- migration-018-subadmins-postgres.sql: the coin ledger can never be edited or deleted, and cash
-- records, allocations, deposits, withdrawals and sub-admin accounts can never be deleted. Mistakes
-- are corrected by recording a reversal, never by removing history.
--
-- The application never deletes these rows, so the site works the same without this file; it is a
-- second line of defence against someone with database access. Some shared hosts refuse CREATE
-- TRIGGER (it can need the SUPER privilege when binary logging is on). If the import fails with a
-- privilege error, skip this file.
--
-- Maintenance escape hatch (the test suite uses it): in the SAME connection, first run
--     SET @bet1x_allow_ledger_delete = 'on';
--
-- phpMyAdmin understands the DELIMITER lines below; paste the whole file into the SQL tab.
-- =================================================================================================

DROP TRIGGER IF EXISTS `trg_subadminledger_protect_upd`;
DROP TRIGGER IF EXISTS `trg_subadminledger_protect_del`;
DROP TRIGGER IF EXISTS `trg_subadmincash_protect`;
DROP TRIGGER IF EXISTS `trg_subadminallocation_protect`;
DROP TRIGGER IF EXISTS `trg_subadmindeposit_protect`;
DROP TRIGGER IF EXISTS `trg_subadminwithdrawal_protect`;
DROP TRIGGER IF EXISTS `trg_subadmin_protect`;

DELIMITER $$

CREATE TRIGGER `trg_subadminledger_protect_upd` BEFORE UPDATE ON `SubAdminLedger` FOR EACH ROW
BEGIN
  IF COALESCE(@bet1x_allow_ledger_delete, '') <> 'on' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sub-admin financial records are permanent: UPDATE on SubAdminLedger is not allowed (record a reversal instead)';
  END IF;
END$$

CREATE TRIGGER `trg_subadminledger_protect_del` BEFORE DELETE ON `SubAdminLedger` FOR EACH ROW
BEGIN
  IF COALESCE(@bet1x_allow_ledger_delete, '') <> 'on' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sub-admin financial records are permanent: DELETE on SubAdminLedger is not allowed (record a reversal instead)';
  END IF;
END$$

CREATE TRIGGER `trg_subadmincash_protect` BEFORE DELETE ON `SubAdminCash` FOR EACH ROW
BEGIN
  IF COALESCE(@bet1x_allow_ledger_delete, '') <> 'on' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sub-admin financial records are permanent: DELETE on SubAdminCash is not allowed (record a reversal instead)';
  END IF;
END$$

CREATE TRIGGER `trg_subadminallocation_protect` BEFORE DELETE ON `SubAdminAllocation` FOR EACH ROW
BEGIN
  IF COALESCE(@bet1x_allow_ledger_delete, '') <> 'on' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sub-admin financial records are permanent: DELETE on SubAdminAllocation is not allowed (record a reversal instead)';
  END IF;
END$$

CREATE TRIGGER `trg_subadmindeposit_protect` BEFORE DELETE ON `SubAdminDeposit` FOR EACH ROW
BEGIN
  IF COALESCE(@bet1x_allow_ledger_delete, '') <> 'on' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sub-admin financial records are permanent: DELETE on SubAdminDeposit is not allowed (record a reversal instead)';
  END IF;
END$$

CREATE TRIGGER `trg_subadminwithdrawal_protect` BEFORE DELETE ON `SubAdminWithdrawal` FOR EACH ROW
BEGIN
  IF COALESCE(@bet1x_allow_ledger_delete, '') <> 'on' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sub-admin financial records are permanent: DELETE on SubAdminWithdrawal is not allowed (record a reversal instead)';
  END IF;
END$$

CREATE TRIGGER `trg_subadmin_protect` BEFORE DELETE ON `SubAdmin` FOR EACH ROW
BEGIN
  IF COALESCE(@bet1x_allow_ledger_delete, '') <> 'on' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sub-admin financial records are permanent: DELETE on SubAdmin is not allowed (record a reversal instead)';
  END IF;
END$$

DELIMITER ;
