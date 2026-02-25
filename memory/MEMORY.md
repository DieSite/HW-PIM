# HW-PIM Project Memory

## ProductRepository — gradual removal

Whenever you encounter code that injects or uses `ProductRepository`, opportunistically refactor it to use `Product::query()` (or `Product::with(...)->...`) directly.

**Strategy:**
- New code: always use the `Product` model directly, never `ProductRepository`
- Existing code: replace `ProductRepository` calls when you're already editing that file
- Simple 1-to-1 replacements:
  - `$repo->find($id)` → `Product::find($id)`
  - `$repo->findOrFail($id)` → `Product::findOrFail($id)`
  - `$repo->where(...)->get()` → `Product::where(...)->get()`
  - `$repo->with(...)->find($id)` → `Product::with(...)->find($id)`
  - `$repo->findWhereIn($col, $vals)` → `Product::whereIn($col, $vals)->get()`
  - `$repo->findOneByField($col, $val)` → `Product::where($col, $val)->first()`
  - `$repo->findByField($col, $val)` → `Product::where($col, $val)->get()`
- Custom business-logic methods (`create`, `update`, `copy`, `search*`) — leave alone for now, they need a dedicated refactor
- Do NOT remove the repository class itself yet; just reduce its usage surface over time