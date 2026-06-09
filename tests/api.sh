#!/usr/bin/env bash
# Regression tests for esc-registry-v2 API.
# Run: bash tests/api.sh
# Requires: curl, python3, mysql (uses ~/.my.cnf for cleanup)

set -uo pipefail

BASE="http://localhost/~rlm/escr2/api.php"
TAG="ESCR2TEST_$(date +%s)"

PASS=0; FAIL=0
PERSON_ID=""; DOG_ID=""

c_ok=$'\033[0;32m'; c_fail=$'\033[0;31m'; c_nc=$'\033[0m'

pass() { printf "  ${c_ok}PASS${c_nc}  %s\n" "$1"; ((PASS++)) || true; }
fail() { printf "  ${c_fail}FAIL${c_nc}  %s\n" "$1"; ((FAIL++)) || true; }

expect() {     # expect "label" actual expected
    [[ "$2" == "$3" ]] && pass "$1" || fail "$1  (got '${2}', want '${3}')"; }
nonempty() {   # nonempty "label" actual
    [[ -n "$2" && "$2" != "None" && "$2" != "null" ]] && pass "$1" || fail "$1  (was empty/null)"; }

# Extract a value from JSON on stdin.  Argument is a Python expression on dict d.
jget() { python3 -c "import sys,json; d=json.load(sys.stdin); print($1)" 2>/dev/null || echo ""; }

get()  { curl -s "${BASE}?type=${1}${2:+&${2}}"; }
post() { curl -s -X POST -H 'Content-Type: application/json' -d "$2" "${BASE}?type=${1}"; }

# ── cleanup runs at exit ───────────────────────────────────────────────
cleanup() {
    printf "\n── Cleanup ────────────────────────────────────────────────────────────\n"
    if [[ -n "$PERSON_ID" ]]; then
        mysql escr2 -e "
            DELETE FROM telephone_numbers WHERE person_id=${PERSON_ID};
            DELETE FROM email_addresses   WHERE person_id=${PERSON_ID};
            DELETE FROM postal_addresses  WHERE person_id=${PERSON_ID};
            DELETE FROM people            WHERE person_id=${PERSON_ID};
        " 2>/dev/null && echo "  removed person ${PERSON_ID}" \
                      || echo "  WARNING: could not remove person ${PERSON_ID}"
    fi
    if [[ -n "$DOG_ID" ]]; then
        # Also clean up any litter/breeding the dog-save test might have created
        BREEDING_ID=$(mysql escr2 --skip-column-names -e \
            "SELECT COALESCE(breeding_id,0) FROM dogs WHERE dog_id=${DOG_ID}" 2>/dev/null || echo "0")
        LITTER_ID="0"
        if [[ "$BREEDING_ID" != "0" && -n "$BREEDING_ID" ]]; then
            LITTER_ID=$(mysql escr2 --skip-column-names -e \
                "SELECT COALESCE(litter_id,0) FROM breedings WHERE breeding_id=${BREEDING_ID}" 2>/dev/null || echo "0")
        fi
        mysql escr2 -e "
            DELETE FROM dog_occupations        WHERE dog_id=${DOG_ID};
            DELETE FROM dog_health_problems    WHERE dog_id=${DOG_ID};
            DELETE FROM dog_markings           WHERE dog_id=${DOG_ID};
            DELETE FROM external_registrations WHERE dog_id=${DOG_ID};
            DELETE FROM dog_titles             WHERE dog_id=${DOG_ID};
            DELETE FROM dog_photos             WHERE dog_id=${DOG_ID};
            DELETE FROM dogs                   WHERE dog_id=${DOG_ID};
        " 2>/dev/null
        if [[ "$BREEDING_ID" != "0" && -n "$BREEDING_ID" ]]; then
            mysql escr2 -e "DELETE FROM breedings WHERE breeding_id=${BREEDING_ID}" 2>/dev/null || true
        fi
        if [[ "$LITTER_ID" != "0" && -n "$LITTER_ID" ]]; then
            mysql escr2 -e "DELETE FROM litters WHERE litter_id=${LITTER_ID}" 2>/dev/null || true
        fi
        echo "  removed dog ${DOG_ID}"
    fi
    printf "\n── Results ────────────────────────────────────────────────────────────\n"
    printf "  Passed: %d   Failed: %d\n\n" "$PASS" "$FAIL"
    [[ $FAIL -eq 0 ]] \
        && printf "  ${c_ok}All tests passed.${c_nc}\n\n" \
        || printf "  ${c_fail}${FAIL} test(s) failed.${c_nc}\n\n"
}
trap cleanup EXIT

# ── 1. Sanity: API reachable ───────────────────────────────────────────
printf "── Sanity ─────────────────────────────────────────────────────────────\n"
PING=$(get lookups) || { printf "FATAL: cannot reach %s\n" "$BASE"; exit 1; }
ERR=$(echo "$PING" | jget "d.get('error','')")
[[ -z "$ERR" ]] && pass "API reachable and DB connected" || { fail "API error: $ERR"; exit 1; }

# ── 2. Lookups: all expected keys present and non-empty ───────────────
printf "\n── Lookups ────────────────────────────────────────────────────────────\n"
LOOKUPS="$PING"
for key in sexes coatColors tails microchipTypes microchipRegistries \
           registrationTypes whiteMarkings spayStatus cerfResults \
           mdr1Results ofaHipsResults ofaElbowsResults occupations \
           healthProblems otherMarkings causesOfDeath \
           telephoneRoles emailRoles addressRoles; do
    n=$(echo "$LOOKUPS" | python3 -c \
        "import sys,json; d=json.load(sys.stdin); print(len(d.get('${key}',[])))" 2>/dev/null || echo "0")
    [[ "$n" -gt 0 ]] && pass "lookups['${key}'] present (${n} items)" \
                     || fail "lookups['${key}'] missing or empty"
done

# Grab first IDs from junction-table lookups for use in dog-save
OCC_ID=$(echo "$LOOKUPS" | jget "d['occupations'][0]['code']")
HP_ID=$(echo "$LOOKUPS"  | jget "d['healthProblems'][0]['code']")
MK_ID=$(echo "$LOOKUPS"  | jget "d['otherMarkings'][0]['code']")
PHONE_ROLE=$(echo "$LOOKUPS" | jget "d['telephoneRoles'][0]['code']")
EMAIL_ROLE=$(echo "$LOOKUPS" | jget "d['emailRoles'][0]['code']")
ADDR_ROLE=$(echo  "$LOOKUPS" | jget "d['addressRoles'][0]['code']")

# ── 3. Person: error cases ─────────────────────────────────────────────
printf "\n── Person: error cases ────────────────────────────────────────────────\n"

R=$(post person-create '{"givenName":"","familyName":""}')
nonempty "empty names → error" "$(echo "$R" | jget "d.get('error','')")"

R=$(get person "id=0")
nonempty "person id=0 → error" "$(echo "$R" | jget "d.get('error','')")"

R=$(get person "id=999999999")
nonempty "person not found → error" "$(echo "$R" | jget "d.get('error','')")"

R=$(post person-save '{"id":0,"givenName":"x"}')
nonempty "person-save id=0 → error" "$(echo "$R" | jget "d.get('error','')")"

# ── 4. Person: create ─────────────────────────────────────────────────
printf "\n── Person: create ─────────────────────────────────────────────────────\n"

R=$(post person-create "{\"givenName\":\"_Test\",\"familyName\":\"${TAG}\"}")
expect  "person-create ok"        "$(echo "$R" | jget "d['ok']")"  "True"
PERSON_ID=$(echo "$R" | jget "d['id']")
nonempty "person-create returns id" "$PERSON_ID"

# ── 5. Person: fetch round-trip ────────────────────────────────────────
printf "\n── Person: fetch ──────────────────────────────────────────────────────\n"

P=$(get person "id=${PERSON_ID}")
expect "person.givenName"  "$(echo "$P" | jget "d['person']['givenName']")"  "_Test"
expect "person.familyName" "$(echo "$P" | jget "d['person']['familyName']")" "${TAG}"

# ── 6. Person: save + re-fetch ────────────────────────────────────────
printf "\n── Person: save ───────────────────────────────────────────────────────\n"

SAVE=$(python3 -c "
import json, sys
print(json.dumps({
    'id': ${PERSON_ID},
    'givenName': '_TestSaved',
    'familyName': '${TAG}',
    'isBreeder': 1,
    'alive': 'Alive',
    'kennelId': None,
    'comments': 'test public comment',
    'registrarsComments': 'test registrar comment',
    'publishContactInfo': 1,
    'phones':    [{'roleId': ${PHONE_ROLE}, 'number': '555-0199', 'note': 'cell'}],
    'emails':    [{'roleId': ${EMAIL_ROLE}, 'emailAddress': 'test@example.com', 'note': ''}],
    'addresses': [{'roleId': ${ADDR_ROLE},  'streetAddress1': '42 Test Lane',
                   'streetAddress2': '', 'city': 'Testburg',
                   'stateCode': 'OR', 'postalCode': '97000', 'note': ''}],
}))
")

R=$(post person-save "$SAVE")
expect "person-save ok" "$(echo "$R" | jget "d['ok']")" "True"

P2=$(get person "id=${PERSON_ID}")
expect  "person givenName updated"     "$(echo "$P2" | jget "d['person']['givenName']")"  "_TestSaved"
expect  "person alive updated"         "$(echo "$P2" | jget "d['person']['alive']")"      "Alive"
expect  "person isBreeder updated"     "$(echo "$P2" | jget "str(d['person']['isBreeder'])")" "1"
expect  "person comments saved"        "$(echo "$P2" | jget "d['person']['comments']")"   "test public comment"
expect  "person phone inserted (1)"    "$(echo "$P2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['phones']))"   2>/dev/null)" "1"
expect  "person email inserted (1)"    "$(echo "$P2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['emails']))"   2>/dev/null)" "1"
expect  "person address inserted (1)"  "$(echo "$P2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['addresses']))" 2>/dev/null)" "1"
expect  "person phone number correct"  "$(echo "$P2" | jget "d['phones'][0]['number']")"  "555-0199"
expect  "person email address correct" "$(echo "$P2" | jget "d['emails'][0]['emailAddress']")"   "test@example.com"
expect  "person city correct"          "$(echo "$P2" | jget "d['addresses'][0]['city']")" "Testburg"

# phone delete-and-replace: save again with different number
SAVE2=$(python3 -c "
import json
print(json.dumps({
    'id': ${PERSON_ID}, 'givenName': '_TestSaved', 'familyName': '${TAG}',
    'isBreeder': 0, 'alive': 'Unknown', 'kennelId': None,
    'comments': '', 'registrarsComments': '', 'publishContactInfo': 0,
    'phones':    [{'roleId': ${PHONE_ROLE}, 'number': '555-0200', 'note': ''}],
    'emails':    [],
    'addresses': [],
}))
")
R2=$(post person-save "$SAVE2")
expect "person-save 2 ok" "$(echo "$R2" | jget "d['ok']")" "True"
P3=$(get person "id=${PERSON_ID}")
expect "phone replaced on re-save"    "$(echo "$P3" | jget "d['phones'][0]['number']")"         "555-0200"
expect "emails cleared on re-save"    "$(echo "$P3" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['emails']))" 2>/dev/null)" "0"
expect "addresses cleared on re-save" "$(echo "$P3" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['addresses']))" 2>/dev/null)" "0"

# ── 7. Dog: error cases ───────────────────────────────────────────────
printf "\n── Dog: error cases ───────────────────────────────────────────────────\n"

R=$(post dog-create '{"dogName":""}')
nonempty "empty name → error"       "$(echo "$R" | jget "d.get('error','')")"

R=$(get dog "id=0")
nonempty "dog id=0 → error"         "$(echo "$R" | jget "d.get('error','')")"

R=$(get dog "id=999999999")
nonempty "dog not found → error"    "$(echo "$R" | jget "d.get('error','')")"

R=$(post dog-save '{"id":0,"name":"x"}')
nonempty "dog-save id=0 → error"    "$(echo "$R" | jget "d.get('error','')")"

# ── 8. Dog: create ────────────────────────────────────────────────────
printf "\n── Dog: create ────────────────────────────────────────────────────────\n"

R=$(post dog-create "{\"dogName\":\"_TestDog ${TAG}\"}")
expect  "dog-create ok"        "$(echo "$R" | jget "d['ok']")"  "True"
DOG_ID=$(echo "$R" | jget "d['id']")
nonempty "dog-create returns id" "$DOG_ID"

# ── 9. Dog: fetch after create ────────────────────────────────────────
printf "\n── Dog: fetch ─────────────────────────────────────────────────────────\n"

D=$(get dog "id=${DOG_ID}")
expect "dog.name after create" "$(echo "$D" | jget "d['dog']['name']")" "_TestDog ${TAG}"

# ── 10. Dog: save + re-fetch (comprehensive round-trip) ───────────────
printf "\n── Dog: save ──────────────────────────────────────────────────────────\n"

DOG_SAVE=$(python3 -c "
import json
print(json.dumps({
    'id': ${DOG_ID},
    'name': '_TestDog ${TAG}',
    'sex': 'Male',
    'descriptor': 'test descriptor',
    'registrationType': 'Unknown',
    'registrationTypeComment': '',
    'callNames': 'Rowlf',
    'callNamesComment': '',
    'nameComment': 'name note',
    'coatColorCode': None,
    'whiteMarkings': 'Unknown',
    'tail': 'Unknown',
    'adultHeight': 24,
    'adultHeightAgeMonths': 18,
    'adultWeight': 65,
    'adultWeightAgeMonths': 24,
    'heightWeightComment': '',
    'spayStatus': 'Unknown',
    'spayAgeMonths': None,
    'otherHealthInfo': 'test health note',
    'ageAtDeathYears': 2,
    'ageAtDeathMonths': 3,
    'ageAtDeathComment': 'death note',
    'causeOfDeathId': None,
    'otherCauseOfDeath': 'hit by bus',
    'causeOfDeathComment': '',
    'pennhipDiLeft': '0.31', 'pennhipDiRight': '0.29',
    'pennhipDjdLeft': 0, 'pennhipDjdRight': 0,
    'pennhipCavLeft': 0, 'pennhipCavRight': 0,
    'pennhipAge': 24,
    'ofaHipsResult': 'Unknown', 'ofaHipsAge': 24,
    'gdcHipsResult': '', 'gdcHipsAge': None,
    'otherHipsResult': '', 'otherHipsAge': None, 'otherHipsComment': '',
    'ofaElbowsResult': 'Unknown', 'ofaElbowsAge': None,
    'cerfResult': 'Unknown', 'cerfAge': None,
    'mdr1Result': 'Unknown', 'mdr1Age': None,
    'ownersDescription': \"owner's test description\",
    'farmOrRanchDog': 1,
    'beefCattle': 10, 'dairyCattle': 5, 'sheep': 0,
    'goats': 0, 'hogs': 0, 'horses': 2, 'poultry': 0,
    'livestockComment': 'livestock note',
    'occupationsComment': 'occ note',
    'microchipNumber': '985112345678901',
    'microchipRegistryId': None, 'microchipTypeId': None,
    'microchipComment': 'chip note',
    'tattooNumber': 'XY999', 'tattooRegistry': 'NARG', 'tattooComment': '',
    'ukcPurpleRibbon': 1,
    'registrarsComment': 'registrar note',
    'dateAcquired': '2021-03-15',
    'ownerComment': 'owner note',
    'previousOwnerComment': '',
    'dateOfWhelpComment': '', 'breederComment': '',
    'ownerId': None, 'previousOwnerId': None,
    'beneficiaryId': None, 'registeredById': None,
    'sireId': None, 'damId': None, 'breederId': None, 'dateOfWhelp': '',
    'occupationIds':    [${OCC_ID}],
    'healthProblemIds': [${HP_ID}],
    'markingIds':       [${MK_ID}],
    'externalRegistrations': [
        {'registry': 'UKC',   'registrationNumber': 'UKC-TST-001', 'registeredName': 'Test UKC', 'comment': 'ukc note'},
        {'registry': 'IESR',  'registrationNumber': '',  'registeredName': '', 'comment': ''},
        {'registry': 'ARF',   'registrationNumber': '',  'registeredName': '', 'comment': ''},
        {'registry': 'NKC',   'registrationNumber': '',  'registeredName': '', 'comment': ''},
        {'registry': 'Other', 'registrationNumber': 'OTH-001', 'registeredName': 'Test Other', 'comment': ''},
    ],
    'titles': [
        {'discipline': 'Herding',  'titles': 'HCT HS'},
        {'discipline': 'Agility',  'titles': 'NAJ OAJ'},
        {'discipline': 'Obedience','titles': 'CD'},
    ],
    'photoCaptions': [
        {'idx': 0, 'caption': 'First photo'},
        {'idx': 1, 'caption': 'Second photo'},
        {'idx': 2, 'caption': ''},
        {'idx': 3, 'caption': ''},
        {'idx': 4, 'caption': ''},
        {'idx': 5, 'caption': ''},
        {'idx': 6, 'caption': ''},
        {'idx': 7, 'caption': ''},
        {'idx': 8, 'caption': ''},
        {'idx': 9, 'caption': ''},
    ],
}))
")

R=$(post dog-save "$DOG_SAVE")
expect "dog-save ok" "$(echo "$R" | jget "d['ok']")" "True"

D2=$(get dog "id=${DOG_ID}")

# Core dog fields
expect "dog sex"             "$(echo "$D2" | jget "d['dog']['sex']")"  "Male"

# Detail fields
expect "dog descriptor"          "$(echo "$D2" | jget "d['detail']['descriptor']")"              "test descriptor"
expect "dog callNames"           "$(echo "$D2" | jget "d['detail']['callNames']")"               "Rowlf"
expect "dog nameComment"         "$(echo "$D2" | jget "d['detail']['nameComment']")"             "name note"
expect "dog adultHeight"         "$(echo "$D2" | jget "str(d['detail']['adultHeight'])")"        "24"
expect "dog adultWeight"         "$(echo "$D2" | jget "str(d['detail']['adultWeight'])")"        "65"
expect "dog microchipNumber"     "$(echo "$D2" | jget "d['detail']['microchipNumber']")"         "985112345678901"
expect "dog tattooNumber"        "$(echo "$D2" | jget "d['detail']['tattooNumber']")"            "XY999"
expect "dog tattooRegistry"      "$(echo "$D2" | jget "d['detail']['tattooRegistry']")"          "NARG"
expect "dog otherHealthInfo"     "$(echo "$D2" | jget "d['detail']['otherHealthInformation']")"  "test health note"
expect "dog farmOrRanchDog"      "$(echo "$D2" | jget "str(d['detail']['farmOrRanchDog'])")"     "1"
expect "dog beefCattle"          "$(echo "$D2" | jget "str(d['detail']['beefCattle'])")"         "10"
expect "dog ukcPurpleRibbon"     "$(echo "$D2" | jget "str(d['detail']['ukcPurpleRibbon'])")"    "1"
expect "dog registrarsComment"   "$(echo "$D2" | jget "d['detail']['registrarsComment']")"       "registrar note"
expect "dog ownersDescription"   "$(echo "$D2" | jget "d['detail']['ownersDescription']")"       "owner's test description"
expect "dog otherCauseOfDeath"   "$(echo "$D2" | jget "d['detail']['otherCauseOfDeath']")"       "hit by bus"

# age_at_death: years=2, months=3 → stored as 27 months
expect "dog ageAtDeathMonths (2yr3mo→27)" \
    "$(echo "$D2" | jget "str(d['detail']['ageAtDeathInMonths'])")" "27"

# PennHIP
expect "dog pennHIPDILeft"  "$(echo "$D2" | jget "d['detail']['pennHIPDILeft']")"  "0.31"
expect "dog pennHIPDIRight" "$(echo "$D2" | jget "d['detail']['pennHIPDIRight']")" "0.29"

# Junction tables
expect "occupations count (1)"    \
    "$(echo "$D2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['occupations']))"    2>/dev/null)" "1"
expect "healthProblems count (1)" \
    "$(echo "$D2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['healthProblems']))" 2>/dev/null)" "1"
expect "otherMarkings count (1)"  \
    "$(echo "$D2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['otherMarkings']))"  2>/dev/null)" "1"

# External registrations
expect "UKC reg# saved"  \
    "$(echo "$D2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['externalRegistrations']['UKC']['registrationNumber'])"  2>/dev/null)" "UKC-TST-001"
expect "Other reg# saved" \
    "$(echo "$D2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['externalRegistrations']['Other']['registrationNumber'])" 2>/dev/null)" "OTH-001"
expect "IESR not saved (empty)" \
    "$(echo "$D2" | python3 -c "import sys,json; d=json.load(sys.stdin); print('IESR' in d['externalRegistrations'])" 2>/dev/null)" "False"

# Titles
expect "titles count (3)" \
    "$(echo "$D2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['titles']))"         2>/dev/null)" "3"
expect "Herding title value" \
    "$(echo "$D2" | jget "d['titles'].get('Herding','')")" "HCT HS"
expect "Agility title value" \
    "$(echo "$D2" | jget "d['titles'].get('Agility','')")" "NAJ OAJ"

# Photos (only 2 non-empty captions)
expect "photo captions count (2 non-empty)" \
    "$(echo "$D2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['photos']))" 2>/dev/null)" "2"
expect "photo 0 caption" \
    "$(echo "$D2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(next(p['caption'] for p in d['photos'] if p['idx']==0))" 2>/dev/null)" "First photo"

# ── 11. Search ────────────────────────────────────────────────────────
printf "\n── Search ─────────────────────────────────────────────────────────────\n"

SR=$(get search "q=${TAG}&limit=10")
expect "search finds test dog (1)" \
    "$(echo "$SR" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['dogs']))"   2>/dev/null)" "1"
expect "search finds test person (1)" \
    "$(echo "$SR" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['people']))" 2>/dev/null)" "1"

# Single-char query must return empty (API requires q.length >= 2)
SR2=$(get search "q=X")
expect "single-char query returns no dogs" \
    "$(echo "$SR2" | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['dogs']))" 2>/dev/null)" "0"
