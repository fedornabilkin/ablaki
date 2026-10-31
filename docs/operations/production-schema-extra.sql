-- Only SELECT / SHOW. Run in the PhpStorm production data source, database ablaki.
-- Check this first result before executing the remaining statements.
-- Save each result with column headers. No writes or explicit table locks.
SELECT DATABASE() AS database_name, @@hostname AS server_host,
       VERSION() AS server_version, NOW() AS captured_at;

SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
       SUB_PART, COLLATION, INDEX_TYPE, NULLABLE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME,
       k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
       r.UPDATE_RULE, r.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE AS k
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS AS r
  ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
 AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
WHERE k.TABLE_SCHEMA = DATABASE()
ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION;

SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
ORDER BY TRIGGER_NAME;

SELECT TABLE_NAME, VIEW_DEFINITION, CHECK_OPTION, IS_UPDATABLE
FROM information_schema.VIEWS
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

-- Aggregate data checks: no passwords, tokens, usernames or message contents.
SELECT COUNT(*) AS duplicate_profile_owners
FROM (SELECT user_id FROM profile WHERE user_id IS NOT NULL GROUP BY user_id HAVING COUNT(*) > 1) AS duplicates;
SELECT COUNT(*) AS orphan_or_null_profile_owners
FROM profile AS p LEFT JOIN `user` AS u ON u.id = p.user_id WHERE u.id IS NULL;
SELECT COUNT(*) AS duplicate_person_owners
FROM (SELECT user_id FROM persone GROUP BY user_id HAVING COUNT(*) > 1) AS duplicates;
SELECT COUNT(*) AS orphan_person_owners
FROM persone AS p LEFT JOIN `user` AS u ON u.id = p.user_id WHERE u.id IS NULL;
SELECT COUNT(*) AS duplicate_usernames
FROM (SELECT username FROM `user` WHERE username IS NOT NULL GROUP BY username HAVING COUNT(*) > 1) AS duplicates;
SELECT COUNT(*) AS null_or_empty_usernames FROM `user` WHERE username IS NULL OR TRIM(username) = '';
SELECT COUNT(*) AS duplicate_gift_pairs
FROM (SELECT comment_id, user_id FROM forum_comment_gift GROUP BY comment_id, user_id HAVING COUNT(*) > 1) AS duplicates;
SELECT COUNT(*) AS orphan_gifts
FROM forum_comment_gift AS g
LEFT JOIN forum_comment AS c ON c.id = g.comment_id
LEFT JOIN `user` AS donor ON donor.id = g.user_id
LEFT JOIN `user` AS recipient ON recipient.id = g.recipient_id
WHERE c.id IS NULL OR donor.id IS NULL OR recipient.id IS NULL;

-- Original DDL follows. SHOW CREATE includes indexes/FKs and is read-only.
SHOW CREATE TABLE `advertising`;
SHOW CREATE TABLE `auth_assignment`;
SHOW CREATE TABLE `auth_item`;
SHOW CREATE TABLE `auth_item_child`;
SHOW CREATE TABLE `auth_rule`;
SHOW CREATE TABLE `bind_binds`;
SHOW CREATE TABLE `bind_catalog`;
SHOW CREATE TABLE `bind_seo`;
SHOW CREATE TABLE `bind_uids`;
SHOW CREATE TABLE `bonus`;
SHOW CREATE TABLE `comission`;
SHOW CREATE TABLE `craft_capacity`;
SHOW CREATE TABLE `craft_category`;
SHOW CREATE TABLE `craft_command`;
SHOW CREATE TABLE `craft_container`;
SHOW CREATE TABLE `craft_dependency`;
SHOW CREATE TABLE `craft_event`;
SHOW CREATE TABLE `craft_history`;
SHOW CREATE TABLE `craft_inventory`;
SHOW CREATE TABLE `craft_item`;
SHOW CREATE TABLE `craft_known`;
SHOW CREATE TABLE `craft_meta`;
SHOW CREATE TABLE `craft_recipe`;
SHOW CREATE TABLE `craft_recipe_item`;
SHOW CREATE TABLE `craft_recipe_tool`;
SHOW CREATE TABLE `craft_skill`;
SHOW CREATE TABLE `craft_slot_lease`;
SHOW CREATE TABLE `craft_station`;
SHOW CREATE TABLE `craft_tool_wear`;
SHOW CREATE TABLE `credit_exchange`;
SHOW CREATE TABLE `credit_transfer`;
SHOW CREATE TABLE `fact`;
SHOW CREATE TABLE `forum_awards`;
SHOW CREATE TABLE `forum_chat`;
SHOW CREATE TABLE `forum_comment`;
SHOW CREATE TABLE `forum_comment_gift`;
SHOW CREATE TABLE `forum_theme`;
SHOW CREATE TABLE `game_duel`;
SHOW CREATE TABLE `game_five`;
SHOW CREATE TABLE `game_five_hod`;
SHOW CREATE TABLE `game_orel`;
SHOW CREATE TABLE `game_saper`;
SHOW CREATE TABLE `history_balance`;
SHOW CREATE TABLE `history_link`;
SHOW CREATE TABLE `history_rating`;
SHOW CREATE TABLE `konkurs`;
SHOW CREATE TABLE `mailer`;
SHOW CREATE TABLE `migration`;
SHOW CREATE TABLE `notice_mail`;
SHOW CREATE TABLE `payments`;
SHOW CREATE TABLE `persone`;
SHOW CREATE TABLE `profile`;
SHOW CREATE TABLE `redirect`;
SHOW CREATE TABLE `redirect_visits`;
SHOW CREATE TABLE `seif`;
SHOW CREATE TABLE `set`;
SHOW CREATE TABLE `settings`;
SHOW CREATE TABLE `slider`;
SHOW CREATE TABLE `temp`;
SHOW CREATE TABLE `todo`;
SHOW CREATE TABLE `user`;
SHOW CREATE TABLE `wiki_categories`;
SHOW CREATE TABLE `wiki_copy`;
SHOW CREATE TABLE `wiki_pages`;
