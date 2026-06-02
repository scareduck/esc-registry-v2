# ESCR Registry v2 rewrite

In conjunction with the v2 registry, also rationalize the extant MariaDB schema, now in RegistryDB. The new schema will live in a database named escr2, in keeping with convention used in the public-facing website at https://escr2.scareduck.com.

## Goals

* Tables that can be combined should be. Particularly, Dog is split between Dog and DogDetail owing to contemporaneous (2000-2001) MySQL column limits.
* Helper tables should be eliminated where this makes sense. The worst cases are the Sex, YesNo, SpayNeuterIntact, and Mdr1GeneticMutationResult tables, which could be handled by ENUMs or CHECKs on VARCHAR values throughout. If you see other opportunities (marginal cases like CerfResult and Tail), flag them so we can discuss.
* ID columns should retain the same name across tables for ease of query writing (USING() should be possible) and consistency, e.g. dogs.breeding_id and breedings.breeding_id, breedings.litter_id and litters.litter_id, etc.
* Column and table names should use underscores for word separators, and all lowercase by convention. Table names will always be plural.
* There will need to be a migration script converting the extant registry database to the new, more compact schema.

## Evolution

Despite the README.md, the mandate is no longer for a read-only version of the registry. The registry code you have written to date will be converted to use this schema. (You talked me out of a schema rewrite prior to this effort, which I now want to get fixed. That can come later.)

## Schema

A copy of the current ESC registry schema may be found in esc_schema_2026-05-21.sql.

## Database invocation

Use "mariadb" instead of "mysql" on the command line, as MariaDB's CLI gripes about a depricated name.
