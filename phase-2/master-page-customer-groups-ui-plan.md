# Phase 2 – Master Page: Customer Groups, Locations & Floors (UI Plan)

## 1. Purpose

- **Single screen** as the **master** for:
  - **Customer groups**
  - **Locations** (customers under a group)
  - **Floors** (under each location/customer)
- **Do not remove** existing menus (e.g. Add Customer Group, View Customer Groups); this screen is the main place for the tree + detail workflow.
- **Rename** the page conceptually as the “Master” screen for customer groups / locations / floors (URL can stay `/customer-groups/view` or be renamed to `/customer-groups` or `/customer-groups/master` as preferred).

---

## 2. Screen Identity

| Item | Value |
|------|--------|
| **Page name (label)** | Master – Customer Groups, Locations & Floors (or “Customer Groups Master”) |
| **URL** | `/customer-groups/view` (no change to route; optional rename later to `/customer-groups/master` if needed) |
| **Menu** | Keep existing “View Customer Groups” (or rename to “Customer Groups Master”) – same route. Do not remove other menus. |
| **Component** | Same view component as today; enhance with API tree + popups. |

---

## 3. Tree Behaviour

### 3.1 Initial load

- On open, call **API once** to get **all customer groups** (list).
- Build **first level of tree** = customer groups only (no locations/floors yet).
- Show group name + **location count** `(N)` if API provides it (or from list length); else show `(0)` until a group is expanded.

### 3.2 Expand group (click group name or chevron)

- **First time** clicking a group:
  - Call **API** for that **customer group** to get:
    - **Locations** = customers where `customer_group_id = this group id`
    - **Floors** = per location (e.g. from `customers_floor` where `location_id` in those customer ids)
  - Build tree: **Group → Locations → Floors** for that group.
  - **Cache** the result in the component (e.g. `treeCache[groupId]` or a store).
- **Second time** (click same group again):
  - **Do not call API again.** Use cached tree for that group.
- Counts:
  - Next to **group name**: number of **locations** (e.g. `(2)`).
  - Next to **location name**: number of **floors** (e.g. `(3)`).

### 3.3 Right‑click / context menu

- **Group:** View, Edit, Delete, Add location (unchanged from current plan).
- **Location:** View, Edit, Delete, Add floors/sections (unchanged).
- **Floor:** View, Edit, Delete (unchanged).

(Implementation can stay as already planned; only data source switches from localStorage to API.)

---

## 4. Add Customer Group – Popup Only (Same Component)

### 4.1 No separate full page for “add”

- **Do not use** a separate route for “add” from this master screen (e.g. user stays on `/customer-groups/view`).
- **Add Customer Group** is done via a **popup/modal** that reuses the **same form** as the current Add Customer Group page (same fields: name*, description, notes, status).

### 4.2 Where the popup is opened

1. **“Add Customer Group” button** (top of the master screen, where it already exists).
2. **Plus (+) button** next to the tree title “Customer Groups & Locations” (above the tree).

Both open the **same** “Add Customer Group” modal.

### 4.3 Form content (same as existing add form)

- Customer group name * (required)
- Status (active / inactive)
- Description (optional)
- Notes (optional)
- Actions: **Save** (submit via API), **Cancel** (close modal).

Reuse the same component (or same form template + logic) that already exists for add; the only change is it is shown inside a **modal** on the master page instead of a separate route.

### 4.4 After save

- Close modal.
- Refresh **customer groups** list (and tree first level) from API so the new group appears without full page reload.
- Optionally expand the new group once (can call group API once to show locations/floors if needed).

### 4.5 Routes

- **Keep** route `customer-groups/add` if it is still used from **menu** “Add Customer Group” (full page add). So:
  - From **menu**: “Add Customer Group” can still go to `/customer-groups/add` (full page) if you want to keep it.
  - From **master page**: only the popup; no navigation to `/customer-groups/add` from this screen.
- Or: **Remove** navigation to `add` from menu and only use popup everywhere; then `add` route can be removed or kept for direct link. Plan: **keep old menus**, so keep the add route; master page uses popup only.

---

## 5. API Usage Summary

| When | What to call | Cache? |
|------|----------------|--------|
| Page load | Get all customer groups | No (single load) |
| First click on a group | Get group’s locations + floors (or group detail + locations + floors) | Yes, by group id |
| Click same group again | — | Use cache, do not call API |
| After “Add customer group” (popup save) | Refresh customer groups list (and optionally tree) | Invalidate / refresh list only |
| Add location / Add floor / Edit / Delete | Existing APIs; after success refresh that group’s tree from API (or invalidate cache for that group) | Invalidate cache for that group |

---

## 6. File / Folder Structure (Plan)

- **Phase 2 UI plan:** `phase-2/` (this folder).
  - `master-page-customer-groups-ui-plan.md` (this file).
- **Master page component:** Reuse and extend existing view component under `customer-group/` (e.g. `view-customer-group`).
- **Add Customer Group form:** Reuse existing add form (same component or shared form component) inside a **modal** on the master page.
- **No new route** for “add” from master; optional: rename route `view` to `master` later and update menu label.

---

## 7. Implementation Checklist (UI)

- [ ] Rename page title to “Master – Customer Groups, Locations & Floors” (or agreed label); keep URL `/customer-groups/view`.
- [ ] Tree: initial load = API for customer groups only; build first level.
- [ ] Tree: on first expand of a group, call API for that group (locations + floors); cache by group id.
- [ ] Tree: same group clicked again = use cache, no second API call.
- [ ] Show location count next to group name, floor count next to location name (from API/cache).
- [ ] “Add Customer Group” button (top) opens **popup** with same form as current add (name, status, description, notes).
- [ ] Plus (+) button above tree opens **same** “Add Customer Group” popup.
- [ ] Save in popup: call API, close modal, refresh groups list (and tree).
- [ ] Keep existing menus (Add Customer Group, View Customer Groups); do not remove.
- [ ] Optional: keep `/customer-groups/add` for menu “Add Customer Group” as full page, or switch menu to open master + popup; document choice.

---

## 8. Doubts / Decisions

1. **Route rename:** Keep `/customer-groups/view` or change to `/customer-groups/master`? Plan: keep `view` unless you explicitly rename.
2. **Menu label:** Keep “View Customer Groups” or change to “Customer Groups Master”? Plan: can rename to “Customer Groups Master” for this screen only.
3. **Edit customer group:** From tree context menu “Edit” – open same form in a **popup** (prefilled) or navigate to existing edit route? Plan: popup preferred for consistency with add.

Once this plan is reviewed, implementation can follow this document step by step.
