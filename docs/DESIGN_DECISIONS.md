# Design Decisions Log

Why things are the way they are in `ssntpl/data-fields`. Each line is a
non-obvious call baked into the package that a future contributor would
otherwise rediscover the hard way. Pair with `README.md` (the API guide)
and the code itself (the source of truth).

## Storage modes

- **One package, two storage modes.** The cast (JSON column) and the row
  mode (`data_fields` table) ship together. Shared internals
  (`ValueCaster`, `FieldType`, `FieldAttributes` trait) make a split
  unattractive — three packages or duplication, both worse.
- **No `data_sets` table.** Grouping is a UX/domain concern. Cast mode
  has containers (`step`, `section`, `group`) inline in the document; row
  mode is flat. Anything richer (named categories with lifecycle,
  completion tracking, assignment) belongs in the consuming application.
- **No `HasDataFieldsJson` trait.** Replaced by the cast. The trait forced
  one model-wide schema/values pair; the cast allows N columns per model
  and removes the schema/values sync problem.

## Naming

- **Cast value object = `DataField`; row-mode model = `DataRow`.** Both
  represent the same concept; storage layout decides which class. `DataRow`
  chosen over `DataFieldRow` for brevity / symmetry, accepting a small
  hierarchy-reading risk.
- **Consumer-facing cast attribute is `DataField::class`.** Internal cast
  implementation is `DataFieldCast`. Wired via `Castable::castUsing()` so
  the `Cast` suffix never leaks into consumer code.
- **`HasDataFields` trait in `Concerns\`, not `Traits\`.** Matches modern
  Laravel package convention (Spatie, Filament).

## Type vocabulary

- **`FieldType` enum.** PHP 8.1 backed enum. JSON storage and the
  row-mode `type` column stay as strings; enum is the in-memory and
  signature type for type safety + exhaustive `match`.

## Cast document shape

- **One JSON document per column.** Schema and values merged — each leaf
  carries `key`, `type`, `value` together. No schema column, no envelope.
- **Cast root is one `DataField`.** Whether leaf or container at the
  root, always exactly one object — never a bare collection. Containers
  nest a list of `DataField`s inside `items`.
- **Storage inside `items` is a list, not a map.** Preserves order,
  mirrors PHP iteration, no `sort_order` field needed.
- **Root `key` optional; child keys required (unique among siblings).**
  Root key would duplicate the column name; children need keys for
  property-by-key access and validation paths.
- **Empty column → `null`.** No implicit default container; consumer
  initialises explicitly via `DataField::section()` / `step()` /
  `group()` / `leaf()` factories. Matches Laravel's nullable-cast contract.

## API shape

- **Property access proxies through containers** (`$df->appearance->dark_mode`)
  but only via `__get` returning the matched child `DataField`. To reach
  the cast *value* a consumer chains `->value`. Fully consistent — every
  property access returns either a `DataField` or the cast value via the
  magic accessor.
- **`value` is a magic property** (backed by `private $rawValue`). Reads
  return the runtime-cast form (e.g. `Carbon` for `datetime`, `File` for
  `file`); writes run `ValueCaster::castNativeRead` to normalise. Storage
  form is produced by `castNativeWrite` only in `toArray()` — the
  runtime cache survives in-memory mutations cheaply.
- **`addField` validates structurally on call.** Unknown type, missing
  key, duplicate sibling key, malformed options — all throw at the
  point of authorship, not at save.
- **Rule validation is explicit only.** `$df->validate()` runs the
  Laravel rules. No auto-validate-on-save trait — matches Laravel's
  no-auto-validate-Eloquent idiom.
- **Mutability via re-serialise-on-save.** Cast object is decoupled
  from its parent model; Laravel's `getDirty()` compares serialised
  forms. Standard `AsArrayObject` pattern.
- **`validate()` is a guard, not a filter.** Returns input unchanged on
  success. Callers wanting Laravel's filtered subset call
  `Validator::make(...)->validated()` directly.
- **`dataField($path)` matches by full dotted path only.** Eliminates
  ambiguity when the same key exists in multiple containers.

## Shared internals

- **`FieldAttributes` trait is narrow** (`isLeaf()` / `isContainer()`).
  Declaring public properties on the trait would shadow Eloquent's
  magic `__get`/`__set` on `DataRow`, so the trait carries only
  behaviour. Each consuming class declares its own data shape.
- **`ValueCaster` accepts `FieldType|string`.** Coerce at the boundary.
  Backward compatible for callers passing raw type strings.
- **FILE vs FILES decided by the declared `type`, never by structure of
  the stored value.** FILES always hydrates as a list (empty list
  preserved instead of returning null); a single `File` passed to a
  FILES field is wrapped as a list on write.

## Bool storage

- **Row mode persists `'1'` / `'0'` strings.** Portable across SQLite,
  MySQL, PostgreSQL — `(bool) 'false' === true` in PHP would break a
  naive round-trip. Cast mode persists native PHP booleans (JSON has
  them). Both writers accept the usual truthy strings case-insensitively.

## Date / datetime handling

- **Read paths are defensive.** Malformed DB values return `null`
  instead of crashing the cast — a bad row must not break a
  `Builder::get()` call. Write paths still throw — bad input doesn't
  reach the column.

## Select options

- **`select_*` auto-derives `Rule::in(...)` from `options`** via
  `Illuminate\Validation\Rule::in()` (not the string `in:a,b` form) so
  option keys containing `,` / `|` don't split into multiple rule
  tokens. The cast constructor also rejects such option keys at
  definition time as belt-and-suspenders.

## File-type security

- **File resolution routes through Laravel's morph map and rejects any
  class not a subclass of `Ssntpl\LaravelFiles\Models\File`.** Without
  this guard, a tampered `{model_type, model_id}` payload could
  autoload arbitrary classes. The check honours `config('files.model')`
  so consumers subclassing `File` get their custom class.

## Removed concepts (and why)

- **No `FieldLike` interface.** Row mode and cast mode now have
  distinct, storage-specific call sites — no remaining use case for a
  uniform iteration API.
- **No `SchemaValidator` class.** Structural checks fold into
  `DataField::__construct`. One place to enforce well-formed-ness.
- **No envelope, no `strict_writes`, no `clearDataFieldsCache()`.**
  Each was a workaround for a concern the new design no longer has.
- **No new `list` / repeatable container type.** Use `FieldType::Array_`
  or `FieldType::Json` at the leaf level for repeated structures.
  Revisit when a concrete consumer need surfaces.
