<?php

namespace Ssntpl\DataFields\Support;

use Ssntpl\DataFields\Models\DataField;

/**
 * Asserts that a JSON-mode schema array is well-formed before it gets stored
 * or walked. Catches the common typos that would otherwise silently degrade:
 * duplicate keys at the same level, container nodes without `items`, leaf
 * nodes with reserved container type names, malformed `options` /
 * `visible_if` shapes, and the reserved top-level `version` key.
 *
 * Usage:
 *
 *   \Ssntpl\DataFields\Support\SchemaValidator::validate($schema);
 *
 * or from a HasDataFieldsJson model:
 *
 *   $entry->validateDataFieldsSchema();
 *
 * Throws InvalidArgumentException with a descriptive `path: reason` message.
 */
class SchemaValidator
{
    private const CONTAINER_TYPES = ['step', 'section', 'group'];

    /**
     * @throws \InvalidArgumentException
     */
    public static function validate(array $schema): void
    {
        self::validateNodes($schema, pathPrefix: '', topLevel: true);
    }

    /**
     * @param list<mixed> $nodes
     */
    private static function validateNodes(array $nodes, string $pathPrefix, bool $topLevel): void
    {
        if (!array_is_list($nodes)) {
            throw new \InvalidArgumentException(
                self::msg($pathPrefix, 'expected a list of nodes, got an associative array')
            );
        }

        $seenKeys = [];
        foreach ($nodes as $index => $node) {
            $location = $pathPrefix === '' ? "[$index]" : "$pathPrefix.[$index]";

            if (!is_array($node)) {
                throw new \InvalidArgumentException(self::msg($location, 'node must be an array'));
            }

            $key = $node['key'] ?? null;
            if (!is_string($key) || $key === '') {
                throw new \InvalidArgumentException(self::msg($location, 'node `key` must be a non-empty string'));
            }
            if ($topLevel && $key === 'version') {
                throw new \InvalidArgumentException(self::msg($location, '`version` is a reserved top-level key'));
            }
            if (isset($seenKeys[$key])) {
                throw new \InvalidArgumentException(self::msg($location, "duplicate key `$key` at this level"));
            }
            $seenKeys[$key] = true;

            $type = $node['type'] ?? null;
            if (!is_string($type) || $type === '') {
                throw new \InvalidArgumentException(self::msg("$location.$key", 'node `type` must be a non-empty string'));
            }

            $nodePath = $pathPrefix === '' ? $key : "$pathPrefix.$key";

            if (in_array($type, self::CONTAINER_TYPES, true)) {
                self::validateContainer($node, $nodePath);
                continue;
            }

            self::validateLeaf($node, $type, $nodePath);
        }
    }

    private static function validateContainer(array $node, string $path): void
    {
        if (!array_key_exists('items', $node)) {
            throw new \InvalidArgumentException(
                self::msg($path, 'container node is missing required `items` array')
            );
        }
        if (!is_array($node['items'])) {
            throw new \InvalidArgumentException(
                self::msg($path, '`items` must be a list of child nodes')
            );
        }
        if (isset($node['requires']) && !self::isListOfStrings($node['requires'])) {
            throw new \InvalidArgumentException(
                self::msg($path, '`requires` must be a list of container keys (strings)')
            );
        }
        if (isset($node['assigned_to']) && !self::isAssignmentHint($node['assigned_to'])) {
            throw new \InvalidArgumentException(
                self::msg($path, '`assigned_to` must be an object with string `type` and `key`')
            );
        }

        self::validateNodes($node['items'], $path, topLevel: false);
    }

    private static function validateLeaf(array $node, string $type, string $path): void
    {
        if (!in_array($type, DataField::getAllTypes(), true)) {
            throw new \InvalidArgumentException(
                self::msg($path, "unknown leaf type `$type`; expected one of " . implode(', ', DataField::getAllTypes()))
            );
        }

        if (isset($node['validations']) && !self::isListOfRules($node['validations'])) {
            throw new \InvalidArgumentException(
                self::msg($path, '`validations` must be a list of strings or rule objects')
            );
        }
        if (isset($node['visible_if']) && !self::isAssocStringMap($node['visible_if'])) {
            throw new \InvalidArgumentException(
                self::msg($path, '`visible_if` must be an object map of field keys to expected values')
            );
        }
        if (isset($node['meta']) && !is_array($node['meta'])) {
            throw new \InvalidArgumentException(self::msg($path, '`meta` must be an object'));
        }

        if (in_array($type, [DataField::SELECT_SINGLE, DataField::SELECT_MULTIPLE], true)) {
            self::validateOptions($node, $path);
        }
    }

    private static function validateOptions(array $node, string $path): void
    {
        if (!isset($node['options'])) {
            return; // optional in spec; caller can still validate without auto-`in:` rule
        }
        if (!is_array($node['options']) || !array_is_list($node['options'])) {
            throw new \InvalidArgumentException(self::msg($path, '`options` must be a list'));
        }
        $seen = [];
        foreach ($node['options'] as $i => $opt) {
            $loc = "$path.options[$i]";
            if (!is_array($opt)) {
                throw new \InvalidArgumentException(self::msg($loc, 'option must be an object'));
            }
            $key = $opt['key'] ?? null;
            if (!is_string($key) && !is_int($key) || $key === '') {
                throw new \InvalidArgumentException(self::msg($loc, 'option `key` must be a non-empty string or int'));
            }
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException(self::msg($loc, "duplicate option key `$key`"));
            }
            $seen[$key] = true;
        }
    }

    private static function isListOfStrings(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item)) {
                return false;
            }
        }
        return true;
    }

    private static function isListOfRules(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $rule) {
            // Laravel accepts strings, Rule instances, or nested arrays.
            if (!is_string($rule) && !is_object($rule) && !is_array($rule)) {
                return false;
            }
        }
        return true;
    }

    private static function isAssocStringMap(mixed $value): bool
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return false;
        }
        foreach (array_keys($value) as $k) {
            if (!is_string($k) || $k === '') {
                return false;
            }
        }
        return true;
    }

    private static function isAssignmentHint(mixed $value): bool
    {
        return is_array($value)
            && isset($value['type'], $value['key'])
            && is_string($value['type'])
            && is_string($value['key']);
    }

    private static function msg(string $path, string $reason): string
    {
        return $path === ''
            ? "Schema invalid: $reason"
            : "Schema invalid at `$path`: $reason";
    }
}
