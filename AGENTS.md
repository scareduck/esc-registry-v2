# Codex mandate: ESCR Registry Explorer / Registry v2

## Mission

Build a modern, read-only ESCR Registry Explorer as a low-risk first step toward eventual registry replacement or COTS evaluation.

The immediate goal is not to replace the registry. The immediate goal is to let Rob and Rebecca validate ESCR-specific search, pedigree, litter, and registrar-facing discovery workflows against a local copy of the legacy data without risking production writes.

## Domain context

The existing registry is legacy Java / Tomcat 5.5-era software backed by a MariaDB/MySQL-style schema with historical baggage. The current search is poor, and registrar workflows sometimes require direct SQL for edge cases. The data is valuable and must be treated as the source of truth for discovery and migration analysis.

First human audience: Rebecca, ESC registrar.

## Hard safety boundaries

- V1 is read-only.
- Use a local MariaDB copy only.
- Do not connect to production.
- Do not create production writes, registrar editing, payments, authentication, account management, or mutation workflows in V1.
- Do not commit database dumps, credentials, private member data, generated exports containing real registry data, or local `.env` files.
- Prefer fixtures, synthetic sample data, or tiny hand-authored examples for tests.
- Treat public/private display as a future requirement, not as solved in V1.

## Production schema rule

For `RegistryDB`, only tables whose names start with an uppercase letter are production schema tables.

Lowercase tables are local-only or non-production and must not be treated as authoritative production schema. In particular:

- `coi` and `coi_old` are local-only tables created by Rob.
- They may be useful as optional prototype-derived data.
- They are not part of the production registry schema.
- Any COI displayed from those tables must be labeled as locally calculated / prototype-only unless a future production source is identified.

`AuthorizationDB` is included in the schema dump for completeness but is not required for V1 migration or Explorer work.

## Key legacy relationships to preserve

Important core relationships inferred from the schema:

- `Dog.details -> DogDetail.id`
- `Dog.breeding -> Breeding.id`
- `Breeding.litter -> Litter.id`
- `Breeding.sire -> Dog.id`
- `Litter.dam -> Dog.id`
- `Dog.owner`, `Dog.previousOwner`, and `Dog.beneficiary -> Person.id`
- `Person.kennel -> Kennel.id`
- `Litter.breeder`, `Litter.ownerOfDam`, and `Litter.ownerOfSire -> Person.id`

The parentage chain is:

```text
dog -> Dog.breeding -> Breeding.sire
dog -> Dog.breeding -> Breeding.litter -> Litter.dam
```

Do not assume a litter has exactly one sire. The legacy schema can represent more than one `Breeding` row for a single `Litter`, and each puppy points to a specific `Breeding`. This is important because future replacement must support dual-sired litters and puppy-level sire assignment.

## V1 product scope

Build toward these read-only capabilities:

1. Unified search across:
   - dogs
   - people
   - kennel names
   - litters

2. Dog detail pages showing, as available:
   - registered name
   - registration number
   - sex
   - registration type
   - owner / previous owner / beneficiary
   - whelp date
   - sire
   - dam
   - litter
   - COI, if locally available and clearly labeled
   - littermates
   - offspring
   - relevant `DogDetail` fields and registrar notes, with future public/private separation in mind

3. Litter pages showing, as available:
   - litter number
   - date of whelp
   - dam
   - sire or sires
   - breeder
   - kennel
   - owner of dam
   - owner of sire
   - puppies grouped by sire/breeding assignment
   - reported litter counts
   - actual registered puppy rows
   - average COI, if locally available and clearly labeled
   - registrar comments

4. Basic pedigree / ancestor view:
   - start with 3 generations
   - use the parentage chain above
   - prefer correctness and clarity over visual cleverness

5. Trend dashboards:
   - litters per year
   - puppies per year
   - active sires per year
   - active dams per year
   - COI by year only if a trustworthy local calculation/source is available and clearly labeled

## Data quality reports to prioritize

The Explorer should expose data realities, not hide them. Prioritize reports such as:

- dogs with no breeding / no parentage
- dogs whose breeding points to a missing litter
- breedings with no sire
- litters with no dam
- litters with no breeding rows
- litters with multiple sire rows
- litter count fields that disagree with registered puppy rows
- duplicate registration numbers
- dogs missing `DogDetail`
- unreferenced `DogDetail` rows
- people with suspicious duplicate names or emails
- kennels with no associated people or litters

## Architectural preference

Prefer a boring, inspectable, local-first architecture:

- MariaDB local copy as the source database
- a read-only database user for `RegistryDB`
- optional separate local read-model schema/database, e.g. `RegistryRead`
- SQL views or query modules that hide legacy joins behind stable read models
- server-rendered pages unless there is a clear reason for heavier frontend machinery
- minimal JavaScript for V1
- tests around query behavior and relationship assumptions

Do not spread raw legacy joins throughout the UI. Create an adapter/read-model layer first.

Recommended early read models:

- dog parentage view/query
- litter puppies view/query
- litter summary view/query
- search document builder/query
- dashboard aggregation queries

## Suggested initial implementation sequence

1. Create project scaffolding.
2. Add local configuration conventions without committing secrets.
3. Add database connection code that can run read-only against local MariaDB.
4. Add schema-aware read queries for dogs, litters, parentage, and search.
5. Add minimal server-rendered pages:
   - home/search
   - dog detail
   - litter detail
   - simple pedigree
   - dashboards
6. Add data-quality reports.
7. Add tests using synthetic fixtures or SQL-level query tests.
8. Document how Rob can run it locally.
9. Keep a running `docs/domain-notes.md` or issue list for domain questions discovered during implementation.

## Future replacement requirements to keep visible

Do not prematurely design the full replacement, but do not paint the project into a corner. Future evaluation must support:

- dual-sired litters
- puppy-level sire assignment
- auditability and correction history
- public/private views
- registrar workflows that do not require direct SQL
- migration from historical messy data
- full data export / no vendor lock-in
- COTS comparison against actual ESCR domain needs, not generic registry demos

## COTS evaluation lens

Any COTS registry system should be judged against ESCR-specific needs. At minimum, ask:

- Can it represent dual-sired litters?
- Can it assign sire at the puppy level?
- Can it preserve correction history?
- Can it import incomplete or historically messy records without lying?
- Can registrar-only notes be separated from public data?
- Can Rebecca handle edge cases without SQL?
- Can the system export all data cleanly?
- Can pedigree and COI behavior be inspected and explained?

## Coding style and delivery expectations

- Make small, reviewable changes.
- Prefer clarity over cleverness.
- Keep domain assumptions explicit in comments or docs.
- When unsure about the schema, write a data-quality query or TODO rather than guessing.
- Include exact commands for setup, running, testing, and loading local schema.
- Do not require Rob to adopt a complex toolchain before the prototype proves value.
- Optimize for making Rebecca’s first review session useful.

## Definition of done for V1 prototype

V1 is successful when Rebecca can use the local Explorer to find dogs, people, kennels, and litters faster than in the current registry, and when Rob has a concrete list of real ESCR domain requirements for replacement or COTS evaluation.

Replacement readiness is not required for V1. Learning and workflow validation are required.
