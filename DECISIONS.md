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
