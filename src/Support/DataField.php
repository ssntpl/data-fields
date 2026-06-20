<?php

namespace Ssntpl\DataFields\Support;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ssntpl\DataFields\Casts\DataFieldCast;
use Ssntpl\DataFields\Concerns\FieldAttributes;

/**
 * Cast value object: one self-describing field document. The cast root is
 * always a single DataField — either a container (`items` of more
 * DataFields) or a leaf (`value` of the declared type).
 *
 * @property mixed $value  Magic accessor — read/write the leaf value via
 *                         `ValueCaster::castNativeRead` (write) /
 *                         `castNativeWrite` (toArray serialisation).
 */
class DataField implements Castable, \IteratorAggregate, \JsonSerializable, \ArrayAccess, \Countable
{
    use FieldAttributes;

    public ?string $key = null;
    public FieldType $type;
    /** @var DataField[] */
    public array $items = [];
    public ?string $label = null;
    public ?string $description = null;
    public array $validations = [];
    public array $options = [];
    public mixed $default = null;
    public ?array $visibleIf = null;
    public array $meta = [];

    /**
     * Runtime form of the leaf value (cast via `ValueCaster::castNativeRead`).
     * Surfaced via the magic `$df->value` accessor; storage form on disk is
     * the result of `ValueCaster::castNativeWrite` (in toArray()).
     */
    private mixed $rawValue = null;
    private bool $valueIsSet = false;

    public function __construct(array $node)
    {
        $this->hydrate($node);
    }

    // -----------------------------------------------------------------
    // Castable — Laravel resolves DataField::class in $casts via this
    // -----------------------------------------------------------------

    public static function castUsing(array $arguments): string
    {
        return DataFieldCast::class;
    }

    // -----------------------------------------------------------------
    // Factories
    // -----------------------------------------------------------------

    public static function leaf(FieldType|string $type, mixed $value = null, array $extra = []): self
    {
        $node       = $extra;
        $node['type']  = $type instanceof FieldType ? $type->value : $type;
        $node['value'] = $value;
        return new self($node);
    }

    public static function section(?string $key = null, array $items = [], array $extra = []): self
    {
        return self::container(FieldType::Section, $key, $items, $extra);
    }

    public static function step(?string $key = null, array $items = [], array $extra = []): self
    {
        return self::container(FieldType::Step, $key, $items, $extra);
    }

    public static function group(?string $key = null, array $items = [], array $extra = []): self
    {
        return self::container(FieldType::Group, $key, $items, $extra);
    }

    private static function container(FieldType $type, ?string $key, array $items, array $extra): self
    {
        $node          = $extra;
        $node['type']  = $type->value;
        $node['items'] = $items;
        if ($key !== null) {
            $node['key'] = $key;
        }
        return new self($node);
    }

    public static function fromArray(array $node): self
    {
        return new self($node);
    }

    // -----------------------------------------------------------------
    // Visibility (`visible_if` equality resolution)
    // -----------------------------------------------------------------

    /**
     * Resolve `visible_if` against the provided sibling-value map. Returns
     * true when no `visible_if` is declared or when every key matches.
     *
     * Note: overrides the simpler `FieldAttributes::isVisible()` (no-arg)
     * with this scope-aware variant — but since `FieldAttributes` doesn't
     * declare an `isVisible()` method, there is no collision.
     */
    public function isVisible(?array $siblingValues = null): bool
    {
        if ($this->visibleIf === null) {
            return true;
        }
        $scope = $siblingValues ?? [];
        foreach ($this->visibleIf as $k => $expected) {
            if (($scope[$k] ?? null) !== $expected) {
                return false;
            }
        }
        return true;
    }

    // -----------------------------------------------------------------
    // Hydration + structural validation (immediate)
    // -----------------------------------------------------------------

    private function hydrate(array $node): void
    {
        $type = $node['type'] ?? null;
        if (!is_string($type) && !$type instanceof FieldType) {
            throw new \InvalidArgumentException('node `type` is required and must be a FieldType or string');
        }
        try {
            $this->type = FieldType::coerce($type);
        } catch (\ValueError $e) {
            throw new \InvalidArgumentException("unknown field type `{$type}`");
        }

        if (array_key_exists('key', $node) && $node['key'] !== null) {
            if (!is_string($node['key']) || $node['key'] === '') {
                throw new \InvalidArgumentException('`key` must be a non-empty string when present');
            }
            $this->key = $node['key'];
        }

        $this->label       = $node['label']       ?? null;
        $this->description = $node['description'] ?? null;

        $meta = $node['meta'] ?? [];
        if (!is_array($meta)) {
            throw new \InvalidArgumentException('`meta` must be an array');
        }
        $this->meta = $meta;

        if ($this->isContainer()) {
            $this->hydrateContainer($node);
        } else {
            $this->hydrateLeaf($node);
        }
    }

    private function hydrateContainer(array $node): void
    {
        if (!array_key_exists('items', $node)) {
            throw new \InvalidArgumentException('container node missing required `items` array');
        }
        $items = $node['items'];
        if (!is_array($items)) {
            throw new \InvalidArgumentException('`items` must be a list');
        }
        $seen = [];
        foreach ($items as $i => $item) {
            if (!$item instanceof self && !is_array($item)) {
                throw new \InvalidArgumentException("items[$i] must be a DataField or array");
            }
            $child = $item instanceof self ? $item : new self($item);
            if ($child->key === null) {
                throw new \InvalidArgumentException("items[$i] missing required `key`");
            }
            if (isset($seen[$child->key])) {
                throw new \InvalidArgumentException("items[$i] duplicate sibling key `{$child->key}`");
            }
            $seen[$child->key] = true;
            $this->items[]     = $child;
        }
    }

    private function hydrateLeaf(array $node): void
    {
        $validations = $node['validations'] ?? [];
        if (!is_array($validations)) {
            throw new \InvalidArgumentException('`validations` must be a list');
        }
        $this->validations = $validations;

        $options = $node['options'] ?? [];
        if (!is_array($options)) {
            throw new \InvalidArgumentException('`options` must be a list');
        }
        $this->options = $options;
        if ($options !== []) {
            $this->validateOptions();
        }

        $this->default = $node['default'] ?? null;

        $visibleIf = $node['visible_if'] ?? $node['visibleIf'] ?? null;
        if ($visibleIf !== null) {
            if (!is_array($visibleIf) || $visibleIf === [] || array_is_list($visibleIf)) {
                throw new \InvalidArgumentException('`visible_if` must be a non-empty associative map');
            }
            $this->visibleIf = $visibleIf;
        }

        if (array_key_exists('value', $node)) {
            $this->rawValue   = ValueCaster::castNativeRead($this->type, $node['value']);
            $this->valueIsSet = true;
        }
    }

    private function validateOptions(): void
    {
        if (!array_is_list($this->options)) {
            throw new \InvalidArgumentException('`options` must be a list');
        }
        $seen = [];
        foreach ($this->options as $i => $opt) {
            if (!is_array($opt)) {
                throw new \InvalidArgumentException("options[$i] must be an array");
            }
            $key = $opt['key'] ?? null;
            if ((!is_string($key) && !is_int($key)) || $key === '') {
                throw new \InvalidArgumentException("options[$i] `key` must be a non-empty string or int");
            }
            if (is_string($key) && preg_match('/[,|]/', $key)) {
                throw new \InvalidArgumentException("options[$i] `key` must not contain `,` or `|`");
            }
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException("options[$i] duplicate option key `$key`");
            }
            $seen[$key] = true;
        }
    }

    // -----------------------------------------------------------------
    // Property access — magic `value`, container child lookup by key
    // -----------------------------------------------------------------

    public function __get(string $name): mixed
    {
        if ($name === 'value') {
            return $this->getValue();
        }
        if ($this->isContainer()) {
            return $this->findChild($name);
        }
        return null;
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name === 'value') {
            $this->setValue($value);
            return;
        }
        if ($this->isContainer()) {
            $this->replaceChild($name, $value);
            return;
        }
        throw new \LogicException("Cannot set `$name` on a leaf DataField");
    }

    public function __isset(string $name): bool
    {
        if ($name === 'value') {
            return $this->isLeaf() && ($this->valueIsSet || $this->default !== null);
        }
        if ($this->isContainer()) {
            return $this->findChild($name) !== null;
        }
        return false;
    }

    public function __unset(string $name): void
    {
        if ($name === 'value') {
            $this->rawValue   = null;
            $this->valueIsSet = false;
            return;
        }
        if ($this->isContainer()) {
            $this->removeField($name);
        }
    }

    // -----------------------------------------------------------------
    // Value access (leaf only)
    // -----------------------------------------------------------------

    public function getValue(): mixed
    {
        if (!$this->isLeaf()) {
            return null;
        }
        if ($this->valueIsSet) {
            return $this->rawValue;
        }
        return $this->default;
    }

    public function setValue(mixed $value): self
    {
        if (!$this->isLeaf()) {
            throw new \LogicException('setValue() requires a leaf DataField');
        }
        $this->rawValue   = ValueCaster::castNativeRead($this->type, $value);
        $this->valueIsSet = true;
        return $this;
    }

    // -----------------------------------------------------------------
    // Container access — explicit dotted path + add/remove
    // -----------------------------------------------------------------

    public function dataField(string $dottedPath): ?self
    {
        if ($dottedPath === '') {
            return null;
        }
        $node = $this;
        foreach (explode('.', $dottedPath) as $part) {
            if (!$node->isContainer()) {
                return null;
            }
            $node = $node->findChild($part);
            if ($node === null) {
                return null;
            }
        }
        return $node;
    }

    public function addField(self|array $node): self
    {
        if (!$this->isContainer()) {
            throw new \LogicException('addField() requires a container DataField');
        }
        $child = $node instanceof self ? $node : new self($node);
        if ($child->key === null) {
            throw new \InvalidArgumentException('added field must have a non-empty `key`');
        }
        if ($this->findChild($child->key) !== null) {
            throw new \InvalidArgumentException("duplicate sibling key `{$child->key}`");
        }
        $this->items[] = $child;
        return $this;
    }

    public function removeField(string $key): self
    {
        if (!$this->isContainer()) {
            throw new \LogicException('removeField() requires a container DataField');
        }
        $this->items = array_values(array_filter(
            $this->items,
            fn (self $i) => $i->key !== $key
        ));
        return $this;
    }

    private function findChild(string $key): ?self
    {
        foreach ($this->items as $item) {
            if ($item->key === $key) {
                return $item;
            }
        }
        return null;
    }

    private function replaceChild(string $key, mixed $value): void
    {
        $new = $value instanceof self ? $value : new self((array) $value);
        if ($new->key === null) {
            $new->key = $key;
        }
        foreach ($this->items as $i => $item) {
            if ($item->key === $key) {
                $this->items[$i] = $new;
                return;
            }
        }
        throw new \LogicException("No child `$key`; use addField() to append");
    }

    // -----------------------------------------------------------------
    // Iteration, ArrayAccess, Countable
    // -----------------------------------------------------------------

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->isContainer() ? $this->items : [$this]);
    }

    public function count(): int
    {
        return $this->isContainer() ? count($this->items) : 1;
    }

    public function offsetExists(mixed $offset): bool
    {
        if (!$this->isContainer()) {
            return false;
        }
        if (is_int($offset)) {
            return isset($this->items[$offset]);
        }
        return $this->findChild((string) $offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!$this->isContainer()) {
            return null;
        }
        if (is_int($offset)) {
            return $this->items[$offset] ?? null;
        }
        return $this->findChild((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!$this->isContainer()) {
            throw new \LogicException('Cannot use ArrayAccess writes on a leaf');
        }
        if ($offset === null) {
            $this->addField($value);
            return;
        }
        if (is_int($offset)) {
            $new = $value instanceof self ? $value : new self((array) $value);
            $this->items[$offset] = $new;
            return;
        }
        $this->replaceChild((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        if (!$this->isContainer()) {
            return;
        }
        if (is_int($offset)) {
            unset($this->items[$offset]);
            $this->items = array_values($this->items);
            return;
        }
        $this->removeField((string) $offset);
    }

    // -----------------------------------------------------------------
    // Serialisation
    // -----------------------------------------------------------------

    public function toArray(): array
    {
        $out = ['type' => $this->type->value];
        if ($this->key !== null) {
            $out['key'] = $this->key;
        }
        if ($this->label !== null) {
            $out['label'] = $this->label;
        }
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }
        if ($this->meta !== []) {
            $out['meta'] = $this->meta;
        }

        if ($this->isContainer()) {
            $out['items'] = array_map(fn (self $i) => $i->toArray(), $this->items);
            return $out;
        }

        if ($this->validations !== []) {
            // Note: `Rule` instances inside validations don't round-trip
            // through JSON cleanly. Consumers passing rule objects own that
            // limitation; string rules and rule arrays round-trip fine.
            $out['validations'] = $this->validations;
        }
        if ($this->options !== []) {
            $out['options'] = $this->options;
        }
        if ($this->default !== null) {
            $out['default'] = $this->default;
        }
        if ($this->visibleIf !== null) {
            $out['visible_if'] = $this->visibleIf;
        }
        if ($this->valueIsSet) {
            $out['value'] = ValueCaster::castNativeWrite($this->type, $this->rawValue);
        }
        return $out;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // -----------------------------------------------------------------
    // Validation (guard, not filter)
    // -----------------------------------------------------------------

    /**
     * Run Laravel's validator across the document tree. Throws
     * ValidationException with dotted error paths on failure; returns the
     * tree's runtime values unchanged on success.
     */
    public function validate(): array
    {
        $input = $this->buildValidationInput();
        $rules = [];
        $this->collectRules('', $rules, $this->siblingValuesScope());

        if ($rules === []) {
            return is_array($input) ? $input : ['value' => $input];
        }

        $shaped    = is_array($input) ? $input : ['value' => $input];
        $validator = Validator::make($shaped, $rules);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        return $shaped;
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     */
    private function collectRules(string $prefix, array &$rules, array $siblingScope): void
    {
        if ($this->isContainer()) {
            $childScope = $this->siblingValuesScope();
            foreach ($this->items as $child) {
                $childPath = $prefix === '' ? $child->key : "$prefix.{$child->key}";
                $child->collectRules($childPath, $rules, $childScope);
            }
            return;
        }

        if (!$this->isVisible($siblingScope)) {
            return;
        }

        $nodeRules  = $this->validations;
        $optionKeys = $this->optionKeysForValidation();

        if ($optionKeys !== null && $this->type === FieldType::SelectSingle) {
            $nodeRules[] = Rule::in($optionKeys);
        }
        if ($optionKeys !== null && $this->type === FieldType::SelectMultiple) {
            $nodeRules[] = 'array';
            $rules["$prefix.*"] = [Rule::in($optionKeys)];
        }

        if ($nodeRules === []) {
            return;
        }
        $rules[$prefix === '' ? 'value' : $prefix] = $nodeRules;
    }

    /**
     * @return list<string>|null
     */
    private function optionKeysForValidation(): ?array
    {
        if ($this->options === []) {
            return null;
        }
        $keys = [];
        foreach ($this->options as $opt) {
            if (!isset($opt['key'])) {
                return null;
            }
            $keys[] = (string) $opt['key'];
        }
        return $keys;
    }

    /**
     * Build the nested values input for the validator — mirrors the tree
     * shape so dotted rule paths resolve against it.
     */
    private function buildValidationInput(): mixed
    {
        if ($this->isContainer()) {
            $out = [];
            foreach ($this->items as $child) {
                $out[$child->key] = $child->buildValidationInput();
            }
            return $out;
        }
        return $this->getValue();
    }

    /**
     * Sibling scope for visible_if resolution: a flat key⇒value map of
     * leaf children in this container.
     */
    private function siblingValuesScope(): array
    {
        if (!$this->isContainer()) {
            return [];
        }
        $scope = [];
        foreach ($this->items as $child) {
            if ($child->isLeaf()) {
                $scope[$child->key] = $child->getValue();
            }
        }
        return $scope;
    }
}
