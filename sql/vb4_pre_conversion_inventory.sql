-- ============================================================================
-- vBulletin 4 -> phpBB pre-conversion content inventory
-- ============================================================================
--
-- Run this in phpMyAdmin against your VBULLETIN 4 source database (NOT the
-- empty phpBB destination database) before kicking off the converter.
-- It prints a summary of how much content the converter is about to
-- process, so you can sanity-check the numbers and have a realistic sense
-- of how long the conversion will take.
--
-- ============================================================================
--   STEP 1 - SET YOUR TABLE PREFIX
-- ============================================================================
--
-- vBulletin's default install prefix is `vb_`.  Many self-hosted boards
-- use no prefix at all (so the user table is just `user`), or a custom
-- prefix.  Set it on the next line.  Examples:
--
--      SET @p := 'vb_';            -- default vBulletin install
--      SET @p := '';               -- no prefix at all
--      SET @p := 'vbulletin_';     -- custom prefix
--      SET @p := 'forums_';        -- another custom prefix
--
-- If you don't know your prefix, run "STEP 0" below first.
--
SET @p := 'vb_';
--
-- ============================================================================
--   STEP 0 (optional) - DETECT YOUR PREFIX
-- ============================================================================
--
-- Don't know your prefix?  Run these lines (uncomment them first):
--
--   SHOW TABLES;
--
--   SELECT table_name FROM information_schema.tables
--    WHERE table_schema = DATABASE()
--      AND (table_name LIKE '%user' OR table_name LIKE '%post'
--           OR table_name LIKE '%thread' OR table_name LIKE '%forum')
--    ORDER BY table_name;
--
-- The prefix is whatever comes BEFORE "user", "post", "thread", "forum"
-- in the table names.  Set @p above accordingly.
--
-- ============================================================================
--   STEP 2 - RUN THE INVENTORY
-- ============================================================================
-- Highlight everything from here to end-of-file and execute as one query.
-- Result is a single table, one row per content category.
-- Tables that don't exist on your install show "table not found"
-- (some vB features are optional, e.g. infractions).

DROP TEMPORARY TABLE IF EXISTS _vb_inventory;
CREATE TEMPORARY TABLE _vb_inventory (
    sort_order  INT,
    item        VARCHAR(80),
    count_n     VARCHAR(40)
);

DROP PROCEDURE IF EXISTS _vb_count;
DELIMITER //
CREATE PROCEDURE _vb_count(
    IN p_ord    INT,
    IN p_label  VARCHAR(80),
    IN p_table  VARCHAR(80),
    IN p_where  VARCHAR(255),
    IN p_select VARCHAR(80)        -- '' for COUNT(*), or e.g. 'SUM(filesize)'
)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    DECLARE v_value  VARCHAR(40)  DEFAULT 'n/a';
    DECLARE v_select VARCHAR(80);

    -- Only the prefix-stripped table name is parameterised; @p is read
    -- from the session variable so it stays in one place.
    SELECT COUNT(*) INTO v_exists
      FROM information_schema.tables
     WHERE table_schema = DATABASE()
       AND table_name   = CONCAT(@p, p_table);

    IF v_exists = 0 THEN
        INSERT INTO _vb_inventory VALUES (p_ord, p_label, 'table not found');
    ELSE
        SET v_select = IF(p_select = '' OR p_select IS NULL, 'COUNT(*)', p_select);
        SET @sql := CONCAT(
            'INSERT INTO _vb_inventory ',
            'SELECT ', p_ord, ', ', QUOTE(p_label), ', ',
                IF(p_select LIKE 'SUM%',
                    CONCAT('CONCAT(FORMAT(IFNULL(', v_select, ',0)/1048576, 1), '' MB'')'),
                    CONCAT('FORMAT(', v_select, ', 0)')
                ),
            ' FROM `', @p, p_table, '`',
            IF(p_where = '' OR p_where IS NULL, '', CONCAT(' WHERE ', p_where))
        );
        PREPARE s FROM @sql;
        EXECUTE s;
        DEALLOCATE PREPARE s;
    END IF;
END //
DELIMITER ;

-- ----------------------------------------------------------------------------
-- Sanity check: does the prefix actually point at a vBulletin database?
-- We require the `user` table to exist; if it doesn't, just emit a single
-- helpful row and skip the rest.
-- ----------------------------------------------------------------------------
SELECT COUNT(*) INTO @ok
  FROM information_schema.tables
 WHERE table_schema = DATABASE()
   AND table_name   = CONCAT(@p, 'user');

INSERT INTO _vb_inventory
SELECT 0,
       CONCAT('Prefix `', @p, '` does NOT match this DB - edit @p at the top'),
       'aborted'
  FROM dual
 WHERE @ok = 0;

-- ----------------------------------------------------------------------------
-- The actual inventory (only runs if the prefix sanity check passed).
-- Each row: (sort_order, label, table, optional WHERE, optional SELECT_expr).
-- ----------------------------------------------------------------------------
-- Use a stored procedure call per row to avoid 24 copies of boilerplate.
-- A single block of CALL statements:

CALL _vb_count( 10, 'Users (total)',                       'user',           '',                          '');
CALL _vb_count( 20, 'Users (active, not banned/awaiting)', 'user',           'usergroupid NOT IN (3,4)',  '');
CALL _vb_count( 30, 'Categories + forums (vB nodes)',      'forum',          '',                          '');
CALL _vb_count( 35, '  forums only (parentid > -1)',       'forum',          'parentid > -1',             '');
CALL _vb_count( 40, 'Topics (excluding moved stubs)',      'thread',         'open <> 10',                '');
CALL _vb_count( 45, '  of which sticky',                   'thread',         'sticky = 1 AND open <> 10', '');
CALL _vb_count( 50, 'Redirect / moved-thread stubs',       'thread',         'open = 10',                 '');
CALL _vb_count( 60, 'Posts',                               'post',           '',                          '');
CALL _vb_count( 70, 'Polls',                               'poll',           '',                          '');
CALL _vb_count( 75, 'Poll votes',                          'pollvote',       '',                          '');
CALL _vb_count( 80, 'Private messages (recipient rows)',   'pm',             '',                          '');
CALL _vb_count( 85, 'Private message bodies',              'pmtext',         '',                          '');
CALL _vb_count( 90, 'Attachments (count)',                 'attachment',     '',                          '');
CALL _vb_count( 95, 'Attachments total size (in-DB only)', 'attachment',     '',                          'SUM(filesize)');
CALL _vb_count(100, 'Custom avatars',                      'customavatar',   '',                          '');
CALL _vb_count(110, 'Profile fields',                      'profilefield',   '',                          '');
CALL _vb_count(120, 'Subscriptions (threads)',             'subscribethread','',                          '');
CALL _vb_count(125, 'Subscriptions (forums)',              'subscribeforum', '',                          '');
CALL _vb_count(130, 'Usergroups',                          'usergroup',      '',                          '');
CALL _vb_count(140, 'Smilies',                             'smilie',         '',                          '');
CALL _vb_count(150, 'Censored words',                      'word',           '',                          '');
CALL _vb_count(160, 'Reputation entries',                  'reputation',     '',                          '');
CALL _vb_count(170, 'Infraction records',                  'infraction',     '',                          '');
CALL _vb_count(180, 'Banned users',                        'userban',        '',                          '');

-- ----------------------------------------------------------------------------
-- Final result.  Header row at the top, then the categories in display order.
-- ----------------------------------------------------------------------------
SELECT item, count_n
  FROM (
    SELECT 0  AS sort_order,
           CONCAT('Source DB: ', DATABASE(),
                  '   |   Prefix: ', IF(@p = '', '(none)', CONCAT('`', @p, '`'))) AS item,
           ''                                                                     AS count_n
    UNION ALL
    SELECT sort_order, item, count_n FROM _vb_inventory
  ) AS result
 ORDER BY sort_order;

-- Cleanup.
DROP PROCEDURE IF EXISTS _vb_count;
DROP TEMPORARY TABLE IF EXISTS _vb_inventory;
