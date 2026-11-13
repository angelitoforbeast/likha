# 260: Item Type Mapping lists the newest mappings first

## Why
On /jnt/item-types, "Existing Mappings" is sorted A to Z by item type and then item name. That is ItemTypeMappingController::index:
`DB::table('item_type_mappings')->orderBy('item_type')->orderBy('item_name')`
As a result, the staff cannot see what they just uploaded. The Sender Name Mapping page already shows the latest uploads first. Staff asked for the same approach here, and the owner said yes.

## Decision
The Existing Mappings list on /jnt/item-types (Option 1, the paste page) is ordered newest first: `created_at` desc, then `id` desc.
- Search still filters the same way.
- Paging stays at 100 per page.
- The "Added" column still shows `created_at`.

Leave unchanged:
- the conflict map (`$existingMap`)
- the grouped page
- the inline page (Option 2)
- save and saveOne

## Done when
- A feature or unit test proves the order:
  - three mappings created at different times are listed newest first, regardless of name (e.g. "zz" newest, "aa" oldest);
  - a search keeps newest-first order.
  The test fails before the change and passes after it.
- No new failures in the tests touching item types.
- The result states the final sha of each branch.
