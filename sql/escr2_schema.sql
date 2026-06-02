-- ESC Registry v2 schema
-- Database: escr2
-- Converted from RegistryDB; see migrate_registry.sql for data migration.
--
-- Key changes from RegistryDB:
--   - Dog + DogDetail merged into dogs
--   - Trivial lookup tables (Sex, YesNo, SpayNeuterIntact, etc.) replaced with ENUMs/booleans
--   - DogDetail flat column groups normalized: dog_photos, external_registrations, dog_titles
--   - snake_case plural table names; FK columns use {singular}_id for USING() compatibility
--   - countries and states use natural keys (ISO 3166 alpha-2, postal abbreviation);
--     states PK is composite (country_code, state_code)
--   - No NULL values in data columns; sensible defaults throughout
--     (numerics = 0, strings = '', dates = '0000-00-00', datetimes = '0000-00-00 00:00:00')
--   - ENUMs default to 'Unknown' (first value); boolean TINYINTs default to 0
--   - Optional FK columns remain nullable (they represent absent relationships, not absent data)
--   - Hibernate `version` columns dropped; charset upgraded to utf8mb4

CREATE DATABASE IF NOT EXISTS `escr2`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `escr2`;

-- ---------------------------------------------------------------------------
-- Geographic reference tables (natural keys — joins optional)
-- ---------------------------------------------------------------------------

CREATE TABLE countries (
  country_code VARCHAR(2)   NOT NULL,
  country_name VARCHAR(255) NOT NULL DEFAULT '',
  menu_order   INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE states (
  country_code VARCHAR(2)   NOT NULL,
  state_code   VARCHAR(10)  NOT NULL,
  state_name   VARCHAR(255) NOT NULL DEFAULT '',
  menu_order   INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (country_code, state_code),
  CONSTRAINT fk_states_country FOREIGN KEY (country_code) REFERENCES countries (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Other lookup tables
-- ---------------------------------------------------------------------------

CREATE TABLE coat_colors (
  coat_color_id   BIGINT UNSIGNED NOT NULL,
  coat_color_name VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order      INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (coat_color_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE breeds (
  breed_id   BIGINT       NOT NULL,
  breed_name VARCHAR(255) NOT NULL DEFAULT '',
  menu_order INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (breed_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE occupations (
  occupation_id   BIGINT UNSIGNED NOT NULL,
  occupation_name VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order      INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (occupation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE health_problems (
  health_problem_id   BIGINT UNSIGNED NOT NULL,
  health_problem_name VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order          INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (health_problem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE other_markings (
  marking_id   BIGINT UNSIGNED NOT NULL,
  marking_name VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order   INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (marking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE microchip_registries (
  microchip_registry_id BIGINT UNSIGNED NOT NULL,
  registry_name         VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order            INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (microchip_registry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE microchip_types (
  microchip_type_id   BIGINT UNSIGNED NOT NULL,
  microchip_type_name VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order          INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (microchip_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE causes_of_death (
  cause_of_death_id BIGINT UNSIGNED NOT NULL,
  cause_name        VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order        INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (cause_of_death_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_roles (
  user_role_id BIGINT UNSIGNED NOT NULL,
  role_name    VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order   INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (user_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_address_roles (
  email_address_role_id BIGINT UNSIGNED NOT NULL,
  role_name             VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order            INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (email_address_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE postal_address_roles (
  postal_address_role_id BIGINT UNSIGNED NOT NULL,
  role_name              VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order             INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (postal_address_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE telephone_number_roles (
  telephone_number_role_id BIGINT UNSIGNED NOT NULL,
  role_name                VARCHAR(255)    NOT NULL DEFAULT '',
  menu_order               INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (telephone_number_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Core entity tables
-- ---------------------------------------------------------------------------

CREATE TABLE kennels (
  kennel_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kennel_name VARCHAR(255)    NOT NULL DEFAULT '',
  PRIMARY KEY (kennel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE people (
  person_id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  family_name          VARCHAR(255)    NOT NULL DEFAULT '',
  given_name           VARCHAR(255)    NOT NULL DEFAULT '',
  username             VARCHAR(255)    NOT NULL DEFAULT '',
  user_role_id         BIGINT UNSIGNED DEFAULT NULL,          -- optional FK
  publish_contact_info TINYINT(1)      NOT NULL DEFAULT 0,
  is_breeder           TINYINT(1)      NOT NULL DEFAULT 0,
  alive                ENUM('Unknown','Alive','Deceased') NOT NULL DEFAULT 'Unknown',
  kennel_id            BIGINT UNSIGNED DEFAULT NULL,          -- optional FK
  comments             TEXT            NOT NULL DEFAULT '',
  registrars_comments  TEXT            NOT NULL DEFAULT '',
  is_accepted          VARCHAR(5)      NOT NULL DEFAULT '',
  PRIMARY KEY (person_id),
  KEY fk_people_user_role (user_role_id),
  KEY fk_people_kennel (kennel_id),
  CONSTRAINT fk_people_user_role FOREIGN KEY (user_role_id) REFERENCES user_roles (user_role_id),
  CONSTRAINT fk_people_kennel    FOREIGN KEY (kennel_id)    REFERENCES kennels (kennel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- litters.dam_id → dogs and breedings.sire_id → dogs are added via ALTER TABLE
-- after dogs is created (mutual reference).
CREATE TABLE litters (
  litter_id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dam_id                             BIGINT UNSIGNED DEFAULT NULL,  -- optional FK, added below
  date_of_whelp                      DATE            NOT NULL DEFAULT '0000-00-00',
  breeder_id                         BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  kennel_id                          BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  owner_of_dam_id                    BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  owner_of_sire_id                   BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  litter_number                      VARCHAR(255)    NOT NULL DEFAULT '',
  user_entered                       TINYINT(1)      NOT NULL DEFAULT 0,
  natural_whelping                   TINYINT(1)      NOT NULL DEFAULT 0,
  planned_caesarian                  TINYINT(1)      NOT NULL DEFAULT 0,
  emergency_caesarian                TINYINT(1)      NOT NULL DEFAULT 0,
  oxytocin_pitocin_before_last_whelp TINYINT(1)      NOT NULL DEFAULT 0,
  males_born_live                    INT             NOT NULL DEFAULT 0,
  females_born_live                  INT             NOT NULL DEFAULT 0,
  males_stillborn                    INT             NOT NULL DEFAULT 0,
  females_stillborn                  INT             NOT NULL DEFAULT 0,
  males_surviving                    INT             NOT NULL DEFAULT 0,
  females_surviving                  INT             NOT NULL DEFAULT 0,
  surviving_with_defects             INT             NOT NULL DEFAULT 0,
  died_accidentally                  INT             NOT NULL DEFAULT 0,
  died_natural_causes                INT             NOT NULL DEFAULT 0,
  euthanized                         INT             NOT NULL DEFAULT 0,
  descriptions_of_defects            TEXT            NOT NULL DEFAULT '',
  description_of_defects_in_stillborn TEXT           NOT NULL DEFAULT '',
  description_of_accidental_deaths   TEXT            NOT NULL DEFAULT '',
  description_died_natural_causes    TEXT            NOT NULL DEFAULT '',
  reason_for_euthanasia              TEXT            NOT NULL DEFAULT '',
  city                               VARCHAR(255)    NOT NULL DEFAULT '',
  country_code                       VARCHAR(2)      DEFAULT NULL,  -- optional FK
  state_code                         VARCHAR(10)     DEFAULT NULL,  -- optional FK
  registrar_comment                  TEXT            NOT NULL DEFAULT '',
  PRIMARY KEY (litter_id),
  KEY fk_litters_breeder (breeder_id),
  KEY fk_litters_kennel (kennel_id),
  KEY fk_litters_owner_of_dam (owner_of_dam_id),
  KEY fk_litters_owner_of_sire (owner_of_sire_id),
  KEY fk_litters_state (country_code, state_code),
  CONSTRAINT fk_litters_breeder       FOREIGN KEY (breeder_id)               REFERENCES people (person_id),
  CONSTRAINT fk_litters_kennel        FOREIGN KEY (kennel_id)                REFERENCES kennels (kennel_id),
  CONSTRAINT fk_litters_owner_of_dam  FOREIGN KEY (owner_of_dam_id)          REFERENCES people (person_id),
  CONSTRAINT fk_litters_owner_of_sire FOREIGN KEY (owner_of_sire_id)         REFERENCES people (person_id),
  CONSTRAINT fk_litters_state         FOREIGN KEY (country_code, state_code) REFERENCES states (country_code, state_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE breedings (
  breeding_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  date_of_breeding         DATE            NOT NULL DEFAULT '0000-00-00',
  description_of_mating    TEXT            NOT NULL DEFAULT '',
  description_of_paternity TEXT            NOT NULL DEFAULT '',
  sire_id                  BIGINT UNSIGNED DEFAULT NULL,  -- optional FK, added below
  litter_id                BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  breeding_method          ENUM(
                             'Unknown',
                             'Natural Breeding',
                             'On-site AI',
                             'Fresh-chilled AI',
                             'Frozen Semen AI',
                             'On-site Surgical Insemination',
                             'Fresh-chilled Semen Surgical Insemination',
                             'Frozen Semen Surgical Insemination',
                             'Other'
                           ) NOT NULL DEFAULT 'Unknown',
  sire_owner_witnessed     TINYINT(1)      NOT NULL DEFAULT 0,
  dam_owner_witnessed      TINYINT(1)      NOT NULL DEFAULT 0,
  city                     VARCHAR(255)    NOT NULL DEFAULT '',
  country_code             VARCHAR(2)      DEFAULT NULL,  -- optional FK
  state_code               VARCHAR(10)     DEFAULT NULL,  -- optional FK
  PRIMARY KEY (breeding_id),
  KEY fk_breedings_litter (litter_id),
  KEY fk_breedings_state (country_code, state_code),
  CONSTRAINT fk_breedings_litter FOREIGN KEY (litter_id)
    REFERENCES litters (litter_id),
  CONSTRAINT fk_breedings_state  FOREIGN KEY (country_code, state_code)
    REFERENCES states (country_code, state_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dogs (
  -- identity / registration
  dog_id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dog_name                  VARCHAR(255)    NOT NULL DEFAULT '',
  registration_number       VARCHAR(255)    NOT NULL DEFAULT '',
  registration_type         ENUM('Unknown','Full','S1','S2','S3') NOT NULL DEFAULT 'Unknown',
  registration_type_comment TEXT            NOT NULL DEFAULT '',
  descriptor                VARCHAR(255)    NOT NULL DEFAULT '',
  puppy_letter              CHAR(1)         NOT NULL DEFAULT '',
  display_order             INT UNSIGNED    NOT NULL DEFAULT 0,
  date_registered           DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
  registered_by_id          BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  ancestor_count            INT             NOT NULL DEFAULT 0,
  previous_ancestor_count   INT             NOT NULL DEFAULT 0,

  -- parentage / breeding
  breeding_id               BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  sire_comment              TEXT            NOT NULL DEFAULT '',
  dam_comment               TEXT            NOT NULL DEFAULT '',
  littermates_comment       TEXT            NOT NULL DEFAULT '',

  -- physical characteristics
  sex                       ENUM('Unknown','Female','Male') NOT NULL DEFAULT 'Unknown',
  coat_color_id             BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  coat_color_comment        TEXT            NOT NULL DEFAULT '',
  predominant_white_markings ENUM(
                                'Unknown',
                                'Solid (No White)',
                                'Minor White on Chest/Feet',
                                'Irish White Pattern',
                                'Piebald/Excessive White',
                                'Other'
                              ) NOT NULL DEFAULT 'Unknown',
  tail                      ENUM(
                                'Unknown',
                                'Full Length',
                                'Full Length - Docked',
                                'Natural Bobtail',
                                'Natural Bobtail - Docked',
                                'Other'
                              ) NOT NULL DEFAULT 'Unknown',
  blue_eyes                 TINYINT(1)      NOT NULL DEFAULT 0,
  rear_dew_claws            TINYINT(1)      NOT NULL DEFAULT 0,
  adult_height              INT             NOT NULL DEFAULT 0,
  adult_height_age_months   INT             NOT NULL DEFAULT 0,
  adult_weight              INT             NOT NULL DEFAULT 0,
  adult_weight_age_months   INT             NOT NULL DEFAULT 0,
  adult_weight_height_comment TEXT          NOT NULL DEFAULT '',

  -- identification
  microchip_number          VARCHAR(255)    NOT NULL DEFAULT '',
  microchip_number_comment  TEXT            NOT NULL DEFAULT '',
  microchip_registry_id     BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  microchip_type_id         BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  tattoo_number             VARCHAR(255)    NOT NULL DEFAULT '',
  tattoo_number_comment     TEXT            NOT NULL DEFAULT '',
  tattoo_registry           VARCHAR(255)    NOT NULL DEFAULT '',
  call_names                VARCHAR(255)    NOT NULL DEFAULT '',
  call_names_comment        TEXT            NOT NULL DEFAULT '',
  name_comment              TEXT            NOT NULL DEFAULT '',

  -- health
  spay_neuter_intact        ENUM('Unknown','Intact','Spayed','Neutered','Other') NOT NULL DEFAULT 'Unknown',
  spay_neuter_age_months    INT             NOT NULL DEFAULT 0,
  cerf_result               ENUM(
                                'Unknown','Clear',
                                'Category A','Category B','Category C',
                                'Category D','Category E','Category F',
                                'Other'
                              ) NOT NULL DEFAULT 'Unknown',
  cerf_age_months           INT             NOT NULL DEFAULT 0,
  mdr1_result               ENUM('Unknown','Normal/Normal','Mutant/Normal','Mutant/Mutant') NOT NULL DEFAULT 'Unknown',
  mdr1_age_months           INT             NOT NULL DEFAULT 0,
  ofa_hips_result           ENUM(
                                'Unknown','Excellent','Good','Fair','Borderline',
                                'Mild HD','Moderate HD','Severe HD','Other'
                              ) NOT NULL DEFAULT 'Unknown',
  ofa_hips_age_months       INT             NOT NULL DEFAULT 0,
  ofa_elbows_result         ENUM(
                                'Unknown','Normal',
                                'Grade I Elbow Dysplasia',
                                'Grade II Elbow Dysplasia',
                                'Grade III Elbow Dysplasia',
                                'Other'
                              ) NOT NULL DEFAULT 'Unknown',
  ofa_elbows_age_months     INT             NOT NULL DEFAULT 0,
  gdc_hips_result           VARCHAR(255)    NOT NULL DEFAULT '',
  gdc_hips_age_months       INT             NOT NULL DEFAULT 0,
  pennhip_age_months        INT             NOT NULL DEFAULT 0,
  pennhip_cavitation_left   TINYINT(1)      NOT NULL DEFAULT 0,
  pennhip_cavitation_right  TINYINT(1)      NOT NULL DEFAULT 0,
  pennhip_di_left           VARCHAR(255)    NOT NULL DEFAULT '',
  pennhip_di_right          VARCHAR(255)    NOT NULL DEFAULT '',
  pennhip_djd_left          TINYINT(1)      NOT NULL DEFAULT 0,
  pennhip_djd_right         TINYINT(1)      NOT NULL DEFAULT 0,
  other_radiographic_hips_result   VARCHAR(255) NOT NULL DEFAULT '',
  other_radiographic_hips_comment  TEXT         NOT NULL DEFAULT '',
  other_radiographic_hips_age_months INT        NOT NULL DEFAULT 0,
  other_health_information         TEXT         NOT NULL DEFAULT '',
  other_health_information_comment TEXT         NOT NULL DEFAULT '',

  -- livestock working (INT meaning unclear; migrated as-is from RegistryDB.DogDetail)
  farm_or_ranch_dog         TINYINT(1)      NOT NULL DEFAULT 0,
  beef_cattle               INT             NOT NULL DEFAULT 0,
  dairy_cattle              INT             NOT NULL DEFAULT 0,
  goats                     INT             NOT NULL DEFAULT 0,
  hogs                      INT             NOT NULL DEFAULT 0,
  horses                    INT             NOT NULL DEFAULT 0,
  poultry                   INT             NOT NULL DEFAULT 0,
  sheep                     INT             NOT NULL DEFAULT 0,
  livestock_numbers_comment TEXT            NOT NULL DEFAULT '',
  occupations_comment       TEXT            NOT NULL DEFAULT '',

  -- ownership
  owner_id                  BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  previous_owner_id         BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  beneficiary_id            BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  date_acquired             DATE            NOT NULL DEFAULT '0000-00-00',
  owner_comment             TEXT            NOT NULL DEFAULT '',
  previous_owner_comment    TEXT            NOT NULL DEFAULT '',
  owners_description        TEXT            NOT NULL DEFAULT '',

  -- death
  age_at_death_months       INT             NOT NULL DEFAULT 0,
  age_at_death_comment      TEXT            NOT NULL DEFAULT '',
  cause_of_death_id         BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  cause_of_death_comment    TEXT            NOT NULL DEFAULT '',
  other_cause_of_death      VARCHAR(255)    NOT NULL DEFAULT '',

  -- miscellaneous
  date_of_whelp_comment     TEXT            NOT NULL DEFAULT '',
  ukc_purple_ribbon         TINYINT(1)      NOT NULL DEFAULT 0,
  registrars_comment        TEXT            NOT NULL DEFAULT '',
  breeder_comment           TEXT            NOT NULL DEFAULT '',
  step_in_report            TEXT            NOT NULL DEFAULT '',

  PRIMARY KEY (dog_id),
  KEY fk_dogs_breeding (breeding_id),
  KEY fk_dogs_coat_color (coat_color_id),
  KEY fk_dogs_owner (owner_id),
  KEY fk_dogs_previous_owner (previous_owner_id),
  KEY fk_dogs_beneficiary (beneficiary_id),
  KEY fk_dogs_registered_by (registered_by_id),
  KEY fk_dogs_microchip_registry (microchip_registry_id),
  KEY fk_dogs_microchip_type (microchip_type_id),
  KEY fk_dogs_cause_of_death (cause_of_death_id),
  CONSTRAINT fk_dogs_breeding           FOREIGN KEY (breeding_id)           REFERENCES breedings (breeding_id),
  CONSTRAINT fk_dogs_coat_color         FOREIGN KEY (coat_color_id)         REFERENCES coat_colors (coat_color_id),
  CONSTRAINT fk_dogs_owner              FOREIGN KEY (owner_id)              REFERENCES people (person_id),
  CONSTRAINT fk_dogs_previous_owner     FOREIGN KEY (previous_owner_id)     REFERENCES people (person_id),
  CONSTRAINT fk_dogs_beneficiary        FOREIGN KEY (beneficiary_id)        REFERENCES people (person_id),
  CONSTRAINT fk_dogs_registered_by      FOREIGN KEY (registered_by_id)      REFERENCES people (person_id),
  CONSTRAINT fk_dogs_microchip_registry FOREIGN KEY (microchip_registry_id) REFERENCES microchip_registries (microchip_registry_id),
  CONSTRAINT fk_dogs_microchip_type     FOREIGN KEY (microchip_type_id)     REFERENCES microchip_types (microchip_type_id),
  CONSTRAINT fk_dogs_cause_of_death     FOREIGN KEY (cause_of_death_id)     REFERENCES causes_of_death (cause_of_death_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE litters
  ADD CONSTRAINT fk_litters_dam  FOREIGN KEY (dam_id)  REFERENCES dogs (dog_id);

ALTER TABLE breedings
  ADD CONSTRAINT fk_breedings_sire FOREIGN KEY (sire_id) REFERENCES dogs (dog_id);

-- ---------------------------------------------------------------------------
-- Normalized DogDetail sub-tables
-- ---------------------------------------------------------------------------

CREATE TABLE dog_photos (
  dog_id      BIGINT UNSIGNED  NOT NULL,
  photo_index TINYINT UNSIGNED NOT NULL,  -- 0–9
  caption     VARCHAR(255)     NOT NULL DEFAULT '',
  PRIMARY KEY (dog_id, photo_index),
  CONSTRAINT fk_dog_photos_dog FOREIGN KEY (dog_id) REFERENCES dogs (dog_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE external_registrations (
  dog_id              BIGINT UNSIGNED NOT NULL,
  registry            ENUM('ARF','UKC','IESR','NKC','Other') NOT NULL,
  registered_name     VARCHAR(255) NOT NULL DEFAULT '',
  registration_number VARCHAR(255) NOT NULL DEFAULT '',
  comment             TEXT         NOT NULL DEFAULT '',
  PRIMARY KEY (dog_id, registry),
  CONSTRAINT fk_ext_reg_dog FOREIGN KEY (dog_id) REFERENCES dogs (dog_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dog_titles (
  dog_id     BIGINT UNSIGNED NOT NULL,
  discipline ENUM(
               'Agility','AWFA','CGC/CGN','Flyball','Freestyle','Herding',
               'Obedience','Rally Obedience','Protection','SAR','Service',
               'Temperament','Therapy','Other'
             ) NOT NULL,
  titles     TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (dog_id, discipline),
  CONSTRAINT fk_dog_titles_dog FOREIGN KEY (dog_id) REFERENCES dogs (dog_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Junction tables
-- ---------------------------------------------------------------------------

CREATE TABLE dog_breeds (
  dog_id     BIGINT UNSIGNED NOT NULL,
  breed_id   BIGINT          NOT NULL,
  sixteenths INT             NOT NULL DEFAULT 0,
  PRIMARY KEY (dog_id, breed_id),
  CONSTRAINT fk_dog_breeds_dog   FOREIGN KEY (dog_id)   REFERENCES dogs (dog_id),
  CONSTRAINT fk_dog_breeds_breed FOREIGN KEY (breed_id) REFERENCES breeds (breed_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dog_occupations (
  dog_id        BIGINT UNSIGNED NOT NULL,
  occupation_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (dog_id, occupation_id),
  CONSTRAINT fk_dog_occupations_dog        FOREIGN KEY (dog_id)        REFERENCES dogs (dog_id),
  CONSTRAINT fk_dog_occupations_occupation FOREIGN KEY (occupation_id) REFERENCES occupations (occupation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dog_health_problems (
  dog_id            BIGINT UNSIGNED NOT NULL,
  health_problem_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (dog_id, health_problem_id),
  CONSTRAINT fk_dog_hp_dog FOREIGN KEY (dog_id)            REFERENCES dogs (dog_id),
  CONSTRAINT fk_dog_hp_hp  FOREIGN KEY (health_problem_id) REFERENCES health_problems (health_problem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE dog_markings (
  dog_id     BIGINT UNSIGNED NOT NULL,
  marking_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (dog_id, marking_id),
  CONSTRAINT fk_dog_markings_dog     FOREIGN KEY (dog_id)     REFERENCES dogs (dog_id),
  CONSTRAINT fk_dog_markings_marking FOREIGN KEY (marking_id) REFERENCES other_markings (marking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Contact tables
-- ---------------------------------------------------------------------------

CREATE TABLE email_addresses (
  email_address_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id             BIGINT UNSIGNED NOT NULL,
  email_address         VARCHAR(255)    NOT NULL DEFAULT '',
  email_address_role_id BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  note                  TEXT            NOT NULL DEFAULT '',
  PRIMARY KEY (email_address_id),
  KEY fk_email_person (person_id),
  KEY fk_email_role (email_address_role_id),
  CONSTRAINT fk_email_person FOREIGN KEY (person_id)             REFERENCES people (person_id),
  CONSTRAINT fk_email_role   FOREIGN KEY (email_address_role_id) REFERENCES email_address_roles (email_address_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE postal_addresses (
  postal_address_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id              BIGINT UNSIGNED NOT NULL,
  street_address1        VARCHAR(255)    NOT NULL DEFAULT '',
  street_address2        VARCHAR(255)    NOT NULL DEFAULT '',
  city                   VARCHAR(255)    NOT NULL DEFAULT '',
  country_code           VARCHAR(2)      DEFAULT NULL,  -- optional FK
  state_code             VARCHAR(10)     DEFAULT NULL,  -- optional FK
  postal_code            VARCHAR(255)    NOT NULL DEFAULT '',
  postal_address_role_id BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  note                   TEXT            NOT NULL DEFAULT '',
  PRIMARY KEY (postal_address_id),
  KEY fk_postal_person (person_id),
  KEY fk_postal_state (country_code, state_code),
  KEY fk_postal_role (postal_address_role_id),
  CONSTRAINT fk_postal_person FOREIGN KEY (person_id)               REFERENCES people (person_id),
  CONSTRAINT fk_postal_state  FOREIGN KEY (country_code, state_code) REFERENCES states (country_code, state_code),
  CONSTRAINT fk_postal_role   FOREIGN KEY (postal_address_role_id)  REFERENCES postal_address_roles (postal_address_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE telephone_numbers (
  telephone_number_id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  person_id                BIGINT UNSIGNED NOT NULL,
  number                   VARCHAR(32)     NOT NULL DEFAULT '',
  telephone_number_role_id BIGINT UNSIGNED DEFAULT NULL,  -- optional FK
  note                     TEXT            NOT NULL DEFAULT '',
  PRIMARY KEY (telephone_number_id),
  KEY fk_phone_person (person_id),
  KEY fk_phone_role (telephone_number_role_id),
  CONSTRAINT fk_phone_person FOREIGN KEY (person_id)                REFERENCES people (person_id),
  CONSTRAINT fk_phone_role   FOREIGN KEY (telephone_number_role_id) REFERENCES telephone_number_roles (telephone_number_role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- User requests
-- ---------------------------------------------------------------------------

CREATE TABLE user_requests (
  user_request_id       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  family_name           VARCHAR(255)    NOT NULL DEFAULT '',
  given_name            VARCHAR(255)    NOT NULL DEFAULT '',
  username              VARCHAR(255)    NOT NULL DEFAULT '',
  email_address         VARCHAR(255)    NOT NULL DEFAULT '',
  telephone_number      VARCHAR(255)    NOT NULL DEFAULT '',
  person_id             BIGINT UNSIGNED DEFAULT NULL,   -- optional FK
  street_address1       VARCHAR(255)    NOT NULL DEFAULT '',
  street_address2       VARCHAR(255)    NOT NULL DEFAULT '',
  city                  VARCHAR(255)    NOT NULL DEFAULT '',
  country_code          VARCHAR(2)      DEFAULT NULL,   -- optional FK
  state_code            VARCHAR(10)     DEFAULT NULL,   -- optional FK
  postal_code           VARCHAR(255)    NOT NULL DEFAULT '',
  is_denied             TINYINT(1)      NOT NULL DEFAULT 0,
  rejection_reason      VARCHAR(255)    NOT NULL DEFAULT '',
  verification_id       VARCHAR(255)    NOT NULL DEFAULT '',
  verification_sent     DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
  verification_received DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
  notification_sent     DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
  publish_contact_info  TINYINT(1)      NOT NULL DEFAULT 0,
  comments              TEXT            NOT NULL DEFAULT '',
  PRIMARY KEY (user_request_id),
  KEY fk_user_requests_state (country_code, state_code),
  CONSTRAINT fk_user_requests_state FOREIGN KEY (country_code, state_code)
    REFERENCES states (country_code, state_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- COI table (local computed data, not migrated from RegistryDB)
-- ---------------------------------------------------------------------------

CREATE TABLE coi (
  dog_id BIGINT UNSIGNED NOT NULL,
  coi    FLOAT           NOT NULL DEFAULT 0,
  PRIMARY KEY (dog_id),
  CONSTRAINT fk_coi_dog FOREIGN KEY (dog_id) REFERENCES dogs (dog_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
