-- Destructive pre-migration reset: truncates all escr2 tables.
-- Run this before migrate_registry.sql to start from a clean slate.
--
-- Usage:
--   mariadb -u root < sql/migrate_fresh.sql
--   mariadb -u root < sql/migrate_registry.sql

USE escr2;

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE breedings;
TRUNCATE TABLE breeds;
TRUNCATE TABLE causes_of_death;
TRUNCATE TABLE coat_colors;
TRUNCATE TABLE coi;
TRUNCATE TABLE countries;
TRUNCATE TABLE dog_breeds;
TRUNCATE TABLE dog_health_problems;
TRUNCATE TABLE dog_markings;
TRUNCATE TABLE dog_occupations;
TRUNCATE TABLE dog_photos;
TRUNCATE TABLE dog_titles;
TRUNCATE TABLE dogs;
TRUNCATE TABLE email_address_roles;
TRUNCATE TABLE email_addresses;
TRUNCATE TABLE external_registrations;
TRUNCATE TABLE health_problems;
TRUNCATE TABLE kennels;
TRUNCATE TABLE litters;
TRUNCATE TABLE microchip_registries;
TRUNCATE TABLE microchip_types;
TRUNCATE TABLE occupations;
TRUNCATE TABLE other_markings;
TRUNCATE TABLE people;
TRUNCATE TABLE postal_address_roles;
TRUNCATE TABLE postal_addresses;
TRUNCATE TABLE states;
TRUNCATE TABLE telephone_number_roles;
TRUNCATE TABLE telephone_numbers;
TRUNCATE TABLE user_requests;
TRUNCATE TABLE user_roles;
TRUNCATE TABLE password_resets;

SET FOREIGN_KEY_CHECKS = 1;
