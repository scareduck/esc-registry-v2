-- Migration: RegistryDB → escr2
-- Run after escr2_schema.sql has been applied.
-- Requires both databases to be accessible on the same server.
--
-- Usage:  mariadb -u root < migrate_registry.sql
--
-- NULL handling:
--   All non-FK data columns in escr2 are NOT NULL with explicit defaults.
--   Since MariaDB enforces NOT NULL strictly, source NULLs are converted
--   explicitly here rather than relying on sql_mode behaviour:
--     strings  → COALESCE(col, '')
--     ints     → COALESCE(col, 0)
--     dates    → COALESCE(col, '0000-00-00')
--     datetimes → COALESCE(col, '0000-00-00 00:00:00')
--     YesNo FK → IF(col = 3, 1, 0)   (3=Yes→1, anything else including Unknown/No/NULL→0)
--     ENUM CASE → ELSE 'Unknown'
--
-- Geographic natural keys:
--   RegistryDB.Country.text = ISO 3166-1 alpha-2 (e.g. 'US')
--   RegistryDB.State.text   = postal abbreviation (e.g. 'OR'), TRIM()d

USE escr2;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Geographic tables
-- ---------------------------------------------------------------------------

INSERT INTO countries (country_code, country_name, menu_order)
  SELECT c.text, COALESCE(c.name, ''), c.menuOrder
  FROM RegistryDB.Country c;

INSERT INTO states (country_code, state_code, state_name, menu_order)
  SELECT c.text, TRIM(s.text), COALESCE(s.name, ''), s.menuOrder
  FROM RegistryDB.State s
  JOIN RegistryDB.Country c ON c.code = s.countrycode;

-- ---------------------------------------------------------------------------
-- Other lookup tables
-- ---------------------------------------------------------------------------

INSERT INTO coat_colors (coat_color_id, coat_color_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.CoatColor;

INSERT INTO breeds (breed_id, breed_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.Breed;

INSERT INTO occupations (occupation_id, occupation_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.DogsJob;

INSERT INTO health_problems (health_problem_id, health_problem_name, menu_order)
  SELECT code, COALESCE(text, ''), COALESCE(menuOrder, 0) FROM RegistryDB.HealthProblem;

INSERT INTO other_markings (marking_id, marking_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.OtherMarkingsOrColors;

INSERT INTO microchip_registries (microchip_registry_id, registry_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.MicrochipRegistry;

INSERT INTO microchip_types (microchip_type_id, microchip_type_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.MicrochipType;

INSERT INTO causes_of_death (cause_of_death_id, cause_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.CauseOfDeath;

INSERT INTO user_roles (user_role_id, role_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.UserRole;

INSERT INTO email_address_roles (email_address_role_id, role_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.EmailAddressRole;

INSERT INTO postal_address_roles (postal_address_role_id, role_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.PostalAddressRole;

INSERT INTO telephone_number_roles (telephone_number_role_id, role_name, menu_order)
  SELECT code, COALESCE(text, ''), menuOrder FROM RegistryDB.TelephoneNumberRole;

-- ---------------------------------------------------------------------------
-- kennels
-- ---------------------------------------------------------------------------

INSERT INTO kennels (kennel_id, kennel_name)
  SELECT id, COALESCE(name, '') FROM RegistryDB.Kennel;

-- ---------------------------------------------------------------------------
-- people
--   is_breeder: YesNo FK → IF(col = 3, 1, 0)
--   alive:      ENUM CASE → ELSE 'Unknown'
-- ---------------------------------------------------------------------------

INSERT INTO people (
  person_id, family_name, given_name, username,
  user_role_id, publish_contact_info, is_breeder, alive,
  kennel_id, comments, registrars_comments, is_accepted
)
SELECT
  p.id,
  COALESCE(p.familyName, ''),
  COALESCE(p.givenName, ''),
  COALESCE(p.username, ''),
  p.role,                              -- nullable FK
  COALESCE(p.publishContactInfo, 0),
  IF(p.isBreeder = 3, 1, 0),
  CASE p.alive WHEN 2 THEN 'Alive' WHEN 3 THEN 'Deceased' ELSE 'Unknown' END,
  p.kennel,                            -- nullable FK
  COALESCE(p.comments, ''),
  COALESCE(p.registrarsComments, ''),
  COALESCE(p.isAccepted, '')
FROM RegistryDB.Person p;

-- ---------------------------------------------------------------------------
-- litters
--   YesNo whelping booleans → IF(col = 3, 1, 0)
--   state: LEFT JOIN to resolve natural keys (stays nullable if absent)
-- ---------------------------------------------------------------------------

INSERT INTO litters (
  litter_id, dam_id, date_of_whelp,
  breeder_id, kennel_id, owner_of_dam_id, owner_of_sire_id,
  litter_number, user_entered,
  natural_whelping, planned_caesarian, emergency_caesarian,
  oxytocin_pitocin_before_last_whelp,
  males_born_live, females_born_live,
  males_stillborn, females_stillborn,
  males_surviving, females_surviving,
  surviving_with_defects, died_accidentally, died_natural_causes, euthanized,
  descriptions_of_defects, description_of_defects_in_stillborn,
  description_of_accidental_deaths, description_died_natural_causes,
  reason_for_euthanasia, city, country_code, state_code, registrar_comment
)
SELECT
  l.id,
  l.dam,                               -- nullable FK
  COALESCE(l.dateOfWhelp, '0000-00-00'),
  l.breeder,                           -- nullable FK
  l.kennel,                            -- nullable FK
  l.ownerOfDam,                        -- nullable FK
  l.ownerOfSire,                       -- nullable FK
  COALESCE(l.litterNumber, ''),
  COALESCE(l.userEntered, 0),
  IF(l.naturalWhelping              = 3, 1, 0),
  IF(l.plannedCaesarian             = 3, 1, 0),
  IF(l.emergencyCaesarian           = 3, 1, 0),
  IF(l.oxytocinPitocinBeforeLastWhelp = 3, 1, 0),
  COALESCE(l.numberOfMalesBornLive,    0),
  COALESCE(l.numberOfFemalesBornLive,  0),
  COALESCE(l.numberOfMalesStillborn,   0),
  COALESCE(l.numberOfFemalesStillborn, 0),
  COALESCE(l.numberOfMalesSurviving,   0),
  COALESCE(l.numberOfFemalesSurviving, 0),
  COALESCE(l.numberSurvivingWithDefects, 0),
  COALESCE(l.numberDiedAccidently,     0),
  COALESCE(l.numberDiedNaturalCauses,  0),
  COALESCE(l.numberEuthanized,         0),
  COALESCE(l.descriptionsOfDefects,            ''),
  COALESCE(l.descriptionOfDefectsInStillborn,  ''),
  COALESCE(l.descriptionOfAccidentalDeaths,    ''),
  COALESCE(l.descriptionDiedNaturalCauses,     ''),
  COALESCE(l.reasonForEuthanasia,              ''),
  COALESCE(l.city, ''),
  c.text,           -- country_code: nullable FK, NULL if no state recorded
  TRIM(s.text),     -- state_code:   nullable FK, NULL if no state recorded
  COALESCE(l.registrarComment, '')
FROM RegistryDB.Litter l
LEFT JOIN RegistryDB.State   s ON s.code = l.state
LEFT JOIN RegistryDB.Country c ON c.code = s.countrycode;

-- ---------------------------------------------------------------------------
-- breedings
--   breeding_method: ENUM CASE → ELSE 'Unknown'
-- ---------------------------------------------------------------------------

INSERT INTO breedings (
  breeding_id, date_of_breeding, description_of_mating, description_of_paternity,
  sire_id, litter_id, breeding_method,
  sire_owner_witnessed, dam_owner_witnessed, city, country_code, state_code
)
SELECT
  b.id,
  COALESCE(b.dateOfBreeding, '0000-00-00'),
  COALESCE(b.descriptionOfMating,    ''),
  COALESCE(b.descriptionOfPaternity, ''),
  b.sire,    -- nullable FK
  b.litter,  -- nullable FK
  CASE b.breedingMethod
    WHEN 2  THEN 'Natural Breeding'
    WHEN 3  THEN 'On-site AI'
    WHEN 4  THEN 'Fresh-chilled AI'
    WHEN 5  THEN 'Frozen Semen AI'
    WHEN 6  THEN 'On-site Surgical Insemination'
    WHEN 7  THEN 'Fresh-chilled Semen Surgical Insemination'
    WHEN 8  THEN 'Frozen Semen Surgical Insemination'
    WHEN 99 THEN 'Other'
    ELSE 'Unknown'
  END,
  COALESCE(b.sireOwnerWitnessedBreeding, 0),
  COALESCE(b.damOwnerWitnessedBreeding,  0),
  COALESCE(b.city, ''),
  c.text,        -- country_code: nullable FK
  TRIM(s.text)   -- state_code:   nullable FK
FROM RegistryDB.Breeding b
LEFT JOIN RegistryDB.State   s ON s.code = b.state
LEFT JOIN RegistryDB.Country c ON c.code = s.countrycode;

-- ---------------------------------------------------------------------------
-- dogs  (Dog LEFT JOIN DogDetail)
--
-- coat_color_id: DogDetail wins; falls back to Dog.coatColor.
--   NOTE: Coat color may differ between Dog and DogDetail for some records.
--   To find disagreements after migration:
--     SELECT d.id FROM RegistryDB.Dog d
--     JOIN RegistryDB.DogDetail dd ON dd.id = d.details
--     WHERE d.coatColor IS NOT NULL AND dd.coatColor IS NOT NULL
--       AND d.coatColor != dd.coatColor;
--
-- previous_owner_id: Dog.previousOwner is used; DogDetail also had one.
--   To find disagreements after migration:
--     SELECT d.id FROM RegistryDB.Dog d
--     JOIN RegistryDB.DogDetail dd ON dd.id = d.details
--     WHERE d.previousOwner IS NOT NULL AND dd.previousOwner IS NOT NULL
--       AND d.previousOwner != dd.previousOwner;
--
-- ENUM mappings:
--   sex:                1=Female  2=Male  else=Unknown
--   registration_type:  1=Full  2=S1  3=S2  4=S3  else=Unknown
--   spay_neuter_intact: 1=Unknown 2=Intact 3=Spayed 4=Neutered 99=Other
--   mdr1_result:        1=Unknown 2=Normal/Normal 3=Mutant/Normal 4=Mutant/Mutant
--   cerf_result:        1=Unknown 2=Clear 3–8=Category A–F 99=Other
--   tail:               1=Unknown 2=Full Length 3=Full Length-Docked
--                       4=Natural Bobtail 5=Natural Bobtail-Docked 99=Other
--   ofa_hips_result:    1=Unknown 2=Excellent 3=Good 4=Fair 5=Borderline
--                       6=Mild HD 7=Moderate HD 8=Severe HD 99=Other
--   ofa_elbows_result:  1=Unknown 2=Normal 3=Grade I 4=Grade II 5=Grade III 99=Other
--   white_markings:     1=Unknown 2=Solid 3=Minor White 4=Irish White 5=Piebald 99=Other
--
-- YesNo FK booleans → IF(col = 3, 1, 0)
-- ---------------------------------------------------------------------------

INSERT INTO dogs (
  dog_id, dog_name, registration_number, registration_type, registration_type_comment,
  descriptor, puppy_letter, display_order, date_registered,
  registered_by_id, ancestor_count, previous_ancestor_count,
  breeding_id,
  sire_comment, dam_comment, littermates_comment,
  sex, coat_color_id, coat_color_comment, predominant_white_markings,
  tail, blue_eyes, rear_dew_claws,
  adult_height, adult_height_age_months, adult_weight, adult_weight_age_months,
  adult_weight_height_comment,
  microchip_number, microchip_number_comment,
  microchip_registry_id, microchip_type_id,
  tattoo_number, tattoo_number_comment, tattoo_registry,
  call_names, call_names_comment, name_comment,
  spay_neuter_intact, spay_neuter_age_months,
  cerf_result, cerf_age_months,
  mdr1_result, mdr1_age_months,
  ofa_hips_result, ofa_hips_age_months,
  ofa_elbows_result, ofa_elbows_age_months,
  gdc_hips_result, gdc_hips_age_months,
  pennhip_age_months,
  pennhip_cavitation_left, pennhip_cavitation_right,
  pennhip_di_left, pennhip_di_right,
  pennhip_djd_left, pennhip_djd_right,
  other_radiographic_hips_result, other_radiographic_hips_comment,
  other_radiographic_hips_age_months,
  other_health_information, other_health_information_comment,
  farm_or_ranch_dog,
  beef_cattle, dairy_cattle, goats, hogs, horses, poultry, sheep,
  livestock_numbers_comment, occupations_comment,
  owner_id, previous_owner_id, beneficiary_id,
  date_acquired, owner_comment, previous_owner_comment, owners_description,
  age_at_death_months, age_at_death_comment,
  cause_of_death_id, cause_of_death_comment, other_cause_of_death,
  date_of_whelp_comment, ukc_purple_ribbon,
  registrars_comment, breeder_comment, step_in_report
)
SELECT
  d.id,
  COALESCE(d.name, ''),
  COALESCE(d.registrationNumber, ''),
  CASE d.registrationType
    WHEN 1 THEN 'Full' WHEN 2 THEN 'S1' WHEN 3 THEN 'S2' WHEN 4 THEN 'S3'
    ELSE 'Unknown'
  END,
  COALESCE(dd.registrationTypeComment, ''),
  COALESCE(d.descriptor, ''),
  COALESCE(d.puppyLetter, ''),
  COALESCE(d.displayOrder, 0),
  COALESCE(d.dateRegistered, '0000-00-00 00:00:00'),
  d.registeredBy,                      -- nullable FK
  COALESCE(d.ancestorCount, 0),
  COALESCE(d.previousAncestorCount, 0),
  d.breeding,                          -- nullable FK
  COALESCE(dd.sireComment, ''),
  COALESCE(dd.damComment, ''),
  COALESCE(dd.littermatesComment, ''),
  CASE d.sex WHEN 1 THEN 'Female' WHEN 2 THEN 'Male' ELSE 'Unknown' END,
  COALESCE(dd.coatColor, d.coatColor), -- nullable FK
  COALESCE(dd.coatColorComment, ''),
  CASE dd.predominantWhiteMarkings
    WHEN 2  THEN 'Solid (No White)'
    WHEN 3  THEN 'Minor White on Chest/Feet'
    WHEN 4  THEN 'Irish White Pattern'
    WHEN 5  THEN 'Piebald/Excessive White'
    WHEN 99 THEN 'Other'
    ELSE 'Unknown'
  END,
  CASE dd.tail
    WHEN 2  THEN 'Full Length'
    WHEN 3  THEN 'Full Length - Docked'
    WHEN 4  THEN 'Natural Bobtail'
    WHEN 5  THEN 'Natural Bobtail - Docked'
    WHEN 99 THEN 'Other'
    ELSE 'Unknown'
  END,
  IF(dd.blueEyes    = 3, 1, 0),
  IF(dd.rearDewClaws = 3, 1, 0),
  COALESCE(dd.adultHeight,            0),
  COALESCE(dd.adultHeightAgeInMonths, 0),
  COALESCE(dd.adultWeight,            0),
  COALESCE(dd.adultWeightAgeInMonths, 0),
  COALESCE(dd.adultWeightHeightComment, ''),
  COALESCE(dd.microchipNumber,        ''),
  COALESCE(dd.microchipNumberComment, ''),
  dd.microchipRegistry,                -- nullable FK
  dd.microchipType,                    -- nullable FK
  COALESCE(dd.tattooNumber,           ''),
  COALESCE(dd.tattooNumberComment,    ''),
  COALESCE(dd.tattooRegistry,         ''),
  COALESCE(dd.callNames,              ''),
  COALESCE(dd.callNamesComment,       ''),
  COALESCE(dd.nameComment,            ''),
  CASE dd.spayNeuterIntact
    WHEN 2  THEN 'Intact'   WHEN 3  THEN 'Spayed'
    WHEN 4  THEN 'Neutered' WHEN 99 THEN 'Other'
    ELSE 'Unknown'
  END,
  COALESCE(dd.spayNeuterAgeInMonths, 0),
  CASE dd.cerfResult
    WHEN 2  THEN 'Clear'
    WHEN 3  THEN 'Category A' WHEN 4  THEN 'Category B'
    WHEN 5  THEN 'Category C' WHEN 6  THEN 'Category D'
    WHEN 7  THEN 'Category E' WHEN 8  THEN 'Category F'
    WHEN 99 THEN 'Other'
    ELSE 'Unknown'
  END,
  COALESCE(dd.cerfAgeInMonths, 0),
  CASE dd.mdr1GeneticMutationResult
    WHEN 2 THEN 'Normal/Normal' WHEN 3 THEN 'Mutant/Normal'
    WHEN 4 THEN 'Mutant/Mutant'
    ELSE 'Unknown'
  END,
  COALESCE(dd.mdr1GeneticMutationAgeInMonths, 0),
  CASE dd.ofaHipsResult
    WHEN 2  THEN 'Excellent'   WHEN 3  THEN 'Good'
    WHEN 4  THEN 'Fair'        WHEN 5  THEN 'Borderline'
    WHEN 6  THEN 'Mild HD'     WHEN 7  THEN 'Moderate HD'
    WHEN 8  THEN 'Severe HD'   WHEN 99 THEN 'Other'
    ELSE 'Unknown'
  END,
  COALESCE(dd.ofaHipsAgeInMonths, 0),
  CASE dd.ofaElbowsResult
    WHEN 2  THEN 'Normal'
    WHEN 3  THEN 'Grade I Elbow Dysplasia'
    WHEN 4  THEN 'Grade II Elbow Dysplasia'
    WHEN 5  THEN 'Grade III Elbow Dysplasia'
    WHEN 99 THEN 'Other'
    ELSE 'Unknown'
  END,
  COALESCE(dd.ofaElbowsAgeInMonths, 0),
  COALESCE(dd.gdcHipsResult,       ''),
  COALESCE(dd.gdcHipsAgeInMonths,   0),
  COALESCE(dd.pennHIPAgeInMonths,   0),
  IF(dd.pennHIPCavitationLeft  = 3, 1, 0),
  IF(dd.pennHIPCavitationRight = 3, 1, 0),
  COALESCE(dd.pennHIPDILeft,  ''),
  COALESCE(dd.pennHIPDIRight, ''),
  IF(dd.pennHIPDJDLeft  = 3, 1, 0),
  IF(dd.pennHIPDJDRight = 3, 1, 0),
  COALESCE(dd.otherRadiographicHipsResult,        ''),
  COALESCE(dd.otherRadiographicHipsResultComment, ''),
  COALESCE(dd.otherRadiographicHipsAgeInMonths,    0),
  COALESCE(dd.otherHealthInformation,             ''),
  COALESCE(dd.otherHealthInformationComment,      ''),
  IF(dd.farmOrRanchDog = 3, 1, 0),
  COALESCE(dd.beefCattle,  0),
  COALESCE(dd.dairyCattle, 0),
  COALESCE(dd.goats,       0),
  COALESCE(dd.hogs,        0),
  COALESCE(dd.horses,      0),
  COALESCE(dd.poultry,     0),
  COALESCE(dd.sheep,       0),
  COALESCE(dd.livestockNumbersComment, ''),
  COALESCE(dd.occupationsComment,      ''),
  d.owner,                             -- nullable FK
  d.previousOwner,                     -- nullable FK
  d.beneficiary,                       -- nullable FK
  COALESCE(dd.dateAcquired, '0000-00-00'),
  COALESCE(dd.ownerComment,         ''),
  COALESCE(dd.previousOwnerComment, ''),
  COALESCE(dd.ownersDescription,    ''),
  COALESCE(dd.ageAtDeathInMonths, 0),
  COALESCE(dd.ageAtDeathComment,  ''),
  dd.causeOfDeath,                     -- nullable FK
  COALESCE(dd.causeOfDeathComment,  ''),
  COALESCE(dd.otherCauseOfDeath,    ''),
  COALESCE(dd.dateOfWhelpComment,   ''),
  IF(dd.ukcPurpleRibbon = 3, 1, 0),
  COALESCE(dd.registrarsComment, ''),
  COALESCE(dd.breederComment,    ''),
  COALESCE(dd.stepInReport,      '')
FROM RegistryDB.Dog d
LEFT JOIN RegistryDB.DogDetail dd ON dd.id = d.details;

-- ---------------------------------------------------------------------------
-- dog_photos  (only rows with a non-empty caption)
-- ---------------------------------------------------------------------------

INSERT INTO dog_photos (dog_id, photo_index, caption)
SELECT d.id, 0, dd.photoCaption0
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption0 IS NOT NULL AND dd.photoCaption0 != ''
UNION ALL
SELECT d.id, 1, dd.photoCaption1
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption1 IS NOT NULL AND dd.photoCaption1 != ''
UNION ALL
SELECT d.id, 2, dd.photoCaption2
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption2 IS NOT NULL AND dd.photoCaption2 != ''
UNION ALL
SELECT d.id, 3, dd.photoCaption3
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption3 IS NOT NULL AND dd.photoCaption3 != ''
UNION ALL
SELECT d.id, 4, dd.photoCaption4
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption4 IS NOT NULL AND dd.photoCaption4 != ''
UNION ALL
SELECT d.id, 5, dd.photoCaption5
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption5 IS NOT NULL AND dd.photoCaption5 != ''
UNION ALL
SELECT d.id, 6, dd.photoCaption6
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption6 IS NOT NULL AND dd.photoCaption6 != ''
UNION ALL
SELECT d.id, 7, dd.photoCaption7
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption7 IS NOT NULL AND dd.photoCaption7 != ''
UNION ALL
SELECT d.id, 8, dd.photoCaption8
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption8 IS NOT NULL AND dd.photoCaption8 != ''
UNION ALL
SELECT d.id, 9, dd.photoCaption9
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.photoCaption9 IS NOT NULL AND dd.photoCaption9 != '';

-- ---------------------------------------------------------------------------
-- external_registrations  (only rows with a non-empty registration number)
-- ---------------------------------------------------------------------------

INSERT INTO external_registrations (dog_id, registry, registered_name, registration_number, comment)
SELECT d.id, 'ARF',
       COALESCE(dd.arfRegisteredName, ''),
       dd.arfRegistrationNumber,
       COALESCE(dd.arfRegistrationNumberComment, '')
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.arfRegistrationNumber IS NOT NULL AND dd.arfRegistrationNumber != ''
UNION ALL
SELECT d.id, 'UKC',
       COALESCE(dd.ukcRegisteredName, ''),
       dd.ukcRegistrationNumber,
       COALESCE(dd.ukcRegistrationNumberComment, '')
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.ukcRegistrationNumber IS NOT NULL AND dd.ukcRegistrationNumber != ''
UNION ALL
SELECT d.id, 'IESR',
       COALESCE(dd.iesrRegisteredName, ''),
       dd.iesrRegistrationNumber,
       COALESCE(dd.iesrRegistrationNumberComment, '')
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.iesrRegistrationNumber IS NOT NULL AND dd.iesrRegistrationNumber != ''
UNION ALL
SELECT d.id, 'NKC',
       COALESCE(dd.nkcRegisteredName, ''),
       dd.nkcRegistrationNumber,
       ''
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.nkcRegistrationNumber IS NOT NULL AND dd.nkcRegistrationNumber != ''
UNION ALL
SELECT d.id, 'Other',
       COALESCE(dd.otherRegisteredName, ''),
       dd.otherRegistrationNumber,
       COALESCE(dd.otherRegistrationNumberComment, '')
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.otherRegistrationNumber IS NOT NULL AND dd.otherRegistrationNumber != '';

-- ---------------------------------------------------------------------------
-- dog_titles  (only rows where the title field is non-empty)
-- ---------------------------------------------------------------------------

INSERT INTO dog_titles (dog_id, discipline, titles)
SELECT d.id, 'Agility',         dd.agilityTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.agilityTitles IS NOT NULL AND dd.agilityTitles != ''
UNION ALL
SELECT d.id, 'AWFA',            dd.awfaTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.awfaTitles IS NOT NULL AND dd.awfaTitles != ''
UNION ALL
SELECT d.id, 'CGC/CGN',         dd.cgcCgnTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.cgcCgnTitles IS NOT NULL AND dd.cgcCgnTitles != ''
UNION ALL
SELECT d.id, 'Flyball',         dd.flyballTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.flyballTitles IS NOT NULL AND dd.flyballTitles != ''
UNION ALL
SELECT d.id, 'Freestyle',       dd.freestyleTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.freestyleTitles IS NOT NULL AND dd.freestyleTitles != ''
UNION ALL
SELECT d.id, 'Herding',         dd.herdingTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.herdingTitles IS NOT NULL AND dd.herdingTitles != ''
UNION ALL
SELECT d.id, 'Obedience',       dd.obedienceTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.obedienceTitles IS NOT NULL AND dd.obedienceTitles != ''
UNION ALL
SELECT d.id, 'Rally Obedience', dd.rallyObedienceTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.rallyObedienceTitles IS NOT NULL AND dd.rallyObedienceTitles != ''
UNION ALL
SELECT d.id, 'Protection',      dd.protectionTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.protectionTitles IS NOT NULL AND dd.protectionTitles != ''
UNION ALL
SELECT d.id, 'SAR',             dd.sarTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.sarTitles IS NOT NULL AND dd.sarTitles != ''
UNION ALL
SELECT d.id, 'Service',         dd.serviceTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.serviceTitles IS NOT NULL AND dd.serviceTitles != ''
UNION ALL
SELECT d.id, 'Temperament',     dd.temperamentTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.temperamentTitles IS NOT NULL AND dd.temperamentTitles != ''
UNION ALL
SELECT d.id, 'Therapy',         dd.therapyTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.therapyTitles IS NOT NULL AND dd.therapyTitles != ''
UNION ALL
SELECT d.id, 'Other',           dd.otherTitles
  FROM RegistryDB.Dog d JOIN RegistryDB.DogDetail dd ON dd.id = d.details
  WHERE dd.otherTitles IS NOT NULL AND dd.otherTitles != '';

-- ---------------------------------------------------------------------------
-- Junction tables
-- Dog_DogsJob, Dog_HealthProblem, Dog_OtherMarkingsOrColors reference
-- DogDetail.id; resolve through Dog.details.
-- ---------------------------------------------------------------------------

INSERT INTO dog_breeds (dog_id, breed_id, sixteenths)
  SELECT db.dog, db.breed, COALESCE(db.sixteenths, 0)
  FROM RegistryDB.Dog_Breed db;

INSERT INTO dog_occupations (dog_id, occupation_id)
  SELECT d.id, j.code
  FROM RegistryDB.Dog_DogsJob j
  JOIN RegistryDB.Dog d ON d.details = j.id;

INSERT INTO dog_health_problems (dog_id, health_problem_id)
  SELECT d.id, hp.code
  FROM RegistryDB.Dog_HealthProblem hp
  JOIN RegistryDB.Dog d ON d.details = hp.id;

INSERT INTO dog_markings (dog_id, marking_id)
  SELECT d.id, m.code
  FROM RegistryDB.Dog_OtherMarkingsOrColors m
  JOIN RegistryDB.Dog d ON d.details = m.id;

-- ---------------------------------------------------------------------------
-- Contact tables
-- ---------------------------------------------------------------------------

INSERT INTO email_addresses (email_address_id, person_id, email_address, email_address_role_id, note)
  SELECT id, person, COALESCE(emailAddress, ''), role, COALESCE(note, '')
  FROM RegistryDB.EmailAddress;

INSERT INTO postal_addresses (
  postal_address_id, person_id, street_address1, street_address2,
  city, country_code, state_code, postal_code, postal_address_role_id, note
)
  SELECT pa.id, pa.person,
         COALESCE(pa.streetAddress1, ''),
         COALESCE(pa.streetAddress2, ''),
         COALESCE(pa.city, ''),
         c.text,        -- nullable FK
         TRIM(s.text),  -- nullable FK
         COALESCE(pa.postalCode, ''),
         pa.role,       -- nullable FK
         COALESCE(pa.note, '')
  FROM RegistryDB.PostalAddress pa
  LEFT JOIN RegistryDB.State   s ON s.code = pa.state
  LEFT JOIN RegistryDB.Country c ON c.code = s.countrycode;

INSERT INTO telephone_numbers (telephone_number_id, person_id, number, telephone_number_role_id, note)
  SELECT id, person, COALESCE(number, ''), role, COALESCE(note, '')
  FROM RegistryDB.TelephoneNumber;

-- ---------------------------------------------------------------------------
-- user_requests
-- ---------------------------------------------------------------------------

INSERT INTO user_requests (
  user_request_id, family_name, given_name, username, email_address,
  telephone_number, person_id, street_address1, street_address2,
  city, country_code, state_code, postal_code, is_denied, rejection_reason,
  verification_id, verification_sent, verification_received,
  notification_sent, publish_contact_info, comments
)
  SELECT
    ur.id,
    COALESCE(ur.familyName,    ''),
    COALESCE(ur.givenName,     ''),
    COALESCE(ur.username,      ''),
    COALESCE(ur.emailAddress,  ''),
    COALESCE(ur.telephoneNumber, ''),
    ur.person,                         -- nullable FK
    COALESCE(ur.streetAddress1, ''),
    COALESCE(ur.streetAddress2, ''),
    COALESCE(ur.city, ''),
    c.text,       -- nullable FK
    TRIM(s.text), -- nullable FK
    COALESCE(ur.postalCode, ''),
    COALESCE(ur.isDenied, 0),
    COALESCE(ur.rejectionReason,   ''),
    COALESCE(ur.verificationId,    ''),
    COALESCE(ur.verificationSent,     '0000-00-00 00:00:00'),
    COALESCE(ur.verificationReceived, '0000-00-00 00:00:00'),
    COALESCE(ur.notificationSent,     '0000-00-00 00:00:00'),
    ur.publishContactInfo,
    COALESCE(ur.comments, '')
  FROM RegistryDB.UserRequest ur
  LEFT JOIN RegistryDB.State   s ON s.code = ur.state
  LEFT JOIN RegistryDB.Country c ON c.code = s.countrycode;

-- ---------------------------------------------------------------------------
-- Re-enable FK checks
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Verify row counts against expected RegistryDB values
-- ---------------------------------------------------------------------------
-- SELECT 'dogs' t, COUNT(*) n FROM dogs             -- expect ~23145
-- UNION ALL SELECT 'breedings', COUNT(*) FROM breedings  -- expect ~6037
-- UNION ALL SELECT 'litters',   COUNT(*) FROM litters    -- expect ~6025
-- UNION ALL SELECT 'people',    COUNT(*) FROM people;    -- expect ~13884
