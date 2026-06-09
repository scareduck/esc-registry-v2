# Pending Decisions — ESC Registry v2

Items to discuss with Rebecca before implementing.

---

## 1. ES Kennel field — creating new kennels

**Context:** The kennel field on the person editor is currently a typeahead that only links to *existing* kennels. Typing a name not in the system produces no result; saving leaves the field blank.

**Options:**
- A. Separate flow — a "+ New Kennel" link (like "+ New Person" / "+ New Dog") that opens a kennel editor. Simple, consistent with existing pattern.
- B. Inline creation — if the typed name has no match, offer "Create kennel named X" in the dropdown. More convenient for the common create-person-and-assign-kennel workflow.

**Constraint (if B):** The kennel field should only be enabled when ES Breeder is set to Yes.

---

## 2. Disable Account vs. Delete User Account

**Context:** The person editor's Administration card has two similar-looking buttons:

- **Disable Account** — blocks login by setting a disabled flag, but preserves the username and role. Reversible via Reset Password.
- **Delete User Account** — clears username, password, and role entirely. Also frees the username for potential reassignment.

In practice both prevent login. The distinction is suspension vs. full disassociation of credentials from the person record. Delete User Account is also slightly at odds with the Delete Person behavior, which intentionally keeps the username as a tombstone so it cannot be reused.

**Options:**
- A. Keep both buttons with their current behavior.
- B. Drop Delete User Account — use Disable for everything. Simpler; consistent with the tombstone approach.
- C. Rename / reframe one of them to make the distinction clearer.

---

## 3. Member-initiated contact info changes

**Context:** Regular users (role < 3) currently have read-only access — they can view their own person record but cannot edit it. If we want members to be able to update their own phone/email/address, we need a policy for how those changes are handled.

**Options:**
- A. Allow direct edits — members can update their own contact info and it takes effect immediately. Simple, but there's no oversight.
- B. Approval queue — member-submitted changes are held in a pending state until a Registrar reviews and approves them. More work to build and to operate, but Rebecca retains control over what goes in the record.
- C. Don't allow self-service at all — members contact a Registrar to make changes on their behalf (current default behavior).

**Rob's instinct:** Option B (approval queue), but Rebecca may not care enough about contact-info accuracy to want the overhead.

---

## 4. Change history / audit log

**Context:** Storing a JSON snapshot of a record (person, dog, litter) each time it is saved — along with the editor's username and a timestamp — would provide a full audit trail. This could reduce the urgency of the approval queue (Decision 3), since any bad change could be reviewed and rolled back.

**Options:**
- A. Full audit log — every save writes a history row (`table_name`, `record_id`, `changed_by`, `changed_at`, `snapshot JSON`). Enables rollback and accountability.
- B. Lightweight last-modified tracking — just store `updated_by` and `updated_at` on each record, no snapshot. Cheaper, but no rollback.
- C. No history — not worth the complexity for the current usage level.

**Note:** If A is implemented, it may make the approval queue in Decision 3 less necessary (override rather than pre-approve).

---
