-- ESCR Registry Explorer read-model views.
-- Intended for local prototype use against a local RegistryDB copy.
-- Lowercase tables are excluded; they are not production schema.

CREATE DATABASE IF NOT EXISTS `RegistryRead`;

CREATE OR REPLACE
VIEW `RegistryRead`.`v_dog_parentage` AS
SELECT
    d.`id` AS dog_id,
    d.`name` AS dog_name,
    d.`registrationNumber` AS registration_number,
    d.`sex` AS sex_code,
    d.`registrationType` AS registration_type_code,
    d.`displayOrder` AS display_order,
    d.`puppyLetter` AS puppy_letter,
    d.`dateRegistered` AS date_registered,
    d.`details` AS dog_detail_id,
    d.`owner` AS owner_id,
    d.`previousOwner` AS previous_owner_id,
    d.`beneficiary` AS beneficiary_id,
    d.`breeding` AS breeding_id,
    b.`dateOfBreeding` AS date_of_breeding,
    b.`breedingMethod` AS breeding_method_code,
    b.`litter` AS litter_id,
    sire.`id` AS sire_id,
    sire.`name` AS sire_name,
    sire.`registrationNumber` AS sire_registration_number,
    l.`litterNumber` AS litter_number,
    l.`dateOfWhelp` AS date_of_whelp,
    dam.`id` AS dam_id,
    dam.`name` AS dam_name,
    dam.`registrationNumber` AS dam_registration_number
FROM `RegistryDB`.`Dog` d
LEFT JOIN `RegistryDB`.`Breeding` b ON b.`id` = d.`breeding`
LEFT JOIN `RegistryDB`.`Dog` sire ON sire.`id` = b.`sire`
LEFT JOIN `RegistryDB`.`Litter` l ON l.`id` = b.`litter`
LEFT JOIN `RegistryDB`.`Dog` dam ON dam.`id` = l.`dam`;

CREATE OR REPLACE
VIEW `RegistryRead`.`v_litter_puppies` AS
SELECT
    l.`id` AS litter_id,
    l.`litterNumber` AS litter_number,
    l.`dateOfWhelp` AS date_of_whelp,
    l.`breeder` AS breeder_id,
    l.`kennel` AS kennel_id,
    l.`ownerOfDam` AS owner_of_dam_id,
    l.`ownerOfSire` AS owner_of_sire_id,
    l.`registrarComment` AS registrar_comment,
    l.`dam` AS dam_id,
    dam.`name` AS dam_name,
    dam.`registrationNumber` AS dam_registration_number,
    b.`id` AS breeding_id,
    b.`dateOfBreeding` AS date_of_breeding,
    b.`breedingMethod` AS breeding_method_code,
    b.`sire` AS sire_id,
    sire.`name` AS sire_name,
    sire.`registrationNumber` AS sire_registration_number,
    p.`id` AS puppy_id,
    p.`name` AS puppy_name,
    p.`registrationNumber` AS puppy_registration_number,
    p.`sex` AS puppy_sex_code,
    p.`displayOrder` AS puppy_display_order,
    p.`puppyLetter` AS puppy_letter,
    p.`dateRegistered` AS puppy_date_registered,
    p.`details` AS puppy_detail_id,
    p.`owner` AS puppy_owner_id,
    p.`previousOwner` AS puppy_previous_owner_id,
    p.`beneficiary` AS puppy_beneficiary_id
FROM `RegistryDB`.`Litter` l
LEFT JOIN `RegistryDB`.`Dog` dam ON dam.`id` = l.`dam`
LEFT JOIN `RegistryDB`.`Breeding` b ON b.`litter` = l.`id`
LEFT JOIN `RegistryDB`.`Dog` sire ON sire.`id` = b.`sire`
LEFT JOIN `RegistryDB`.`Dog` p ON p.`breeding` = b.`id`;
