-- MOS-GOV Phase 5 — minimal ChurchCRM group tables for REAL patch verification.
-- SYNTHETIC ONLY. Executed against the isolated 127.0.0.1:3407 test instance.
-- These are a reduced shape of the 7.8.0 schema, containing exactly the columns
-- touched by the three methods under test.

DROP TABLE IF EXISTS groupprop_master;
DROP TABLE IF EXISTS person2group2role_p2g2r;
DROP TABLE IF EXISTS list_lst;
DROP TABLE IF EXISTS group_grp;

CREATE TABLE group_grp (
    grp_ID               MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
    grp_RoleListID       MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    grp_DefaultRole      MEDIUMINT UNSIGNED NOT NULL DEFAULT 1,
    grp_hasSpecialProps  TINYINT(1) NOT NULL DEFAULT 0,
    grp_Name             VARCHAR(190) NOT NULL DEFAULT 'SYNTHETIC GROUP',
    PRIMARY KEY (grp_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE list_lst (
    lst_ID              MEDIUMINT UNSIGNED NOT NULL,
    lst_OptionID        MEDIUMINT UNSIGNED NOT NULL,
    lst_OptionName      VARCHAR(190) NOT NULL DEFAULT 'SYNTHETIC ROLE',
    lst_OptionSequence  MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (lst_ID, lst_OptionID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE person2group2role_p2g2r (
    p2g2r_per_ID  MEDIUMINT UNSIGNED NOT NULL,
    p2g2r_grp_ID  MEDIUMINT UNSIGNED NOT NULL,
    p2g2r_rle_ID  MEDIUMINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (p2g2r_per_ID, p2g2r_grp_ID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE groupprop_master (
    grp_ID        MEDIUMINT UNSIGNED NOT NULL,
    prop_Field    VARCHAR(190) NOT NULL DEFAULT 'SYNTHETIC',
    PRIMARY KEY (grp_ID, prop_Field)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Synthetic fixtures: group 12 with three roles, group 1 for property toggles.
INSERT INTO group_grp (grp_ID, grp_RoleListID, grp_DefaultRole, grp_hasSpecialProps) VALUES
    (12, 12, 1, 0),
    (1,  1,  1, 0);
INSERT INTO list_lst (lst_ID, lst_OptionID, lst_OptionName, lst_OptionSequence) VALUES
    (12, 1, 'SYNTHETIC Member',  1),
    (12, 2, 'SYNTHETIC Leader',  2),
    (12, 3, 'SYNTHETIC Helper',  3),
    (1,  1, 'SYNTHETIC Member',  1);
INSERT INTO person2group2role_p2g2r (p2g2r_per_ID, p2g2r_grp_ID, p2g2r_rle_ID) VALUES
    (9001, 12, 2),
    (9002, 12, 3);
