<?php

namespace Screenover\Api\Query;

use Screenover\Api\Exception\UnsupportedFilterException;

/**
 * Translates the legacy Mediative query options into a PayloadCMS REST query string.
 *
 * Supported legacy options:
 *   - where:     "title%%test;created<2014-11-12"   (";" means AND)
 *   - order:     "created:DESC,title"
 *   - fields:    "Media.id,Media.title"
 *   - recursive: -1 | 0 | 1 ...   (mapped to PayloadCMS "depth")
 *   - limit:     "offset,count" | "count"
 *
 * Native PayloadCMS options are also accepted and passed through:
 *   - locale, depth, page, limit (int), draft, sort, select, where (array)
 */
class OptionParser
{
    /**
     * Mediative operator => PayloadCMS operator.
     * Order matters: multi-character operators must be tested before single-character ones.
     *
     * @var array<string,string>
     */
    private const OPERATORS = [
        '%%' => 'like',
        '!=' => 'not_equals',
        '>=' => 'greater_than_equal',
        '<=' => 'less_than_equal',
        '>' => 'greater_than',
        '<' => 'less_than',
        '=' => 'equals',
        ':' => 'equals', // legacy Mediative "field:value" (id:, category:, Category.id:)
    ];

    /**
     * Known field/relation renames between Mediative and ScreenOver/PayloadCMS.
     * Keys are looked up case-insensitively (see resolveField()).
     *
     * @var array<string,string>
     */
    private const FIELD_MAP = [
        'created' => 'createdAt',
        'modified' => 'updatedAt',
        'updated' => 'updatedAt',
        'id' => 'id',
        // Media -> category relation goes through the "categories" join (category-media).
        'category' => 'categories.category',
        'category.id' => 'categories.category',
    ];

    /**
     * @param array<string,mixed> $options
     */
    public function build(array $options): string
    {
        $query = [];

        $this->applyWhere($options, $query);
        $this->applyOrder($options, $query);
        $this->applyFields($options, $query);
        $this->applyRecursive($options, $query);
        $this->applyLimit($options, $query);
        $this->applyPassThrough($options, $query);

        if ($query === []) {
            return '';
        }

        return http_build_query($query);
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $query
     */
    private function applyWhere(array $options, array &$query): void
    {
        if (empty($options['where'])) {
            return;
        }

        // Native PayloadCMS where arrays are forwarded untouched.
        if (is_array($options['where'])) {
            $query['where'] = $options['where'];
            return;
        }

        $conditions = array_filter(array_map('trim', explode(';', (string) $options['where'])), 'strlen');
        $index = 0;
        foreach ($conditions as $condition) {
            $matched = false;
            foreach (self::OPERATORS as $symbol => $operator) {
                $pos = strpos($condition, $symbol);
                if ($pos === false) {
                    continue;
                }

                $field = $this->resolveField(substr($condition, 0, $pos));
                $value = trim(substr($condition, $pos + strlen($symbol)));

                $query['where']['and'][$index][$field][$operator] = $value;
                $index++;
                $matched = true;
                break;
            }

            if (!$matched) {
                throw new UnsupportedFilterException(sprintf(
                    'Unsupported legacy filter "%s": no recognised operator (expected one of %s).',
                    $condition,
                    implode(', ', array_keys(self::OPERATORS))
                ));
            }
        }
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $query
     */
    private function applyOrder(array $options, array &$query): void
    {
        if (isset($options['sort'])) {
            $query['sort'] = $options['sort'];
            return;
        }
        if (empty($options['order'])) {
            return;
        }

        $parts = [];
        foreach (explode(',', (string) $options['order']) as $clause) {
            $clause = trim($clause);
            if ($clause === '') {
                continue;
            }
            [$field, $direction] = array_pad(explode(':', $clause), 2, 'ASC');
            $field = $this->resolveField($field);
            $prefix = strtoupper(trim($direction)) === 'DESC' ? '-' : '';
            $parts[] = $prefix . $field;
        }

        if ($parts !== []) {
            $query['sort'] = implode(',', $parts);
        }
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $query
     */
    private function applyFields(array $options, array &$query): void
    {
        if (isset($options['select'])) {
            $query['select'] = $options['select'];
            return;
        }
        if (empty($options['fields'])) {
            return;
        }

        foreach (explode(',', (string) $options['fields']) as $field) {
            $field = $this->resolveField($field);
            if ($field !== '') {
                $query['select'][$field] = 'true';
            }
        }
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $query
     */
    private function applyRecursive(array $options, array &$query): void
    {
        if (isset($options['depth'])) {
            $query['depth'] = (int) $options['depth'];
            return;
        }
        if (!array_key_exists('recursive', $options)) {
            return;
        }

        $recursive = (int) $options['recursive'];
        // Mediative used -1 to disable relation population; PayloadCMS uses depth 0 for that.
        $query['depth'] = $recursive < 0 ? 0 : $recursive;
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $query
     */
    private function applyLimit(array $options, array &$query): void
    {
        if (empty($options['limit'])) {
            return;
        }

        $limit = (string) $options['limit'];
        if (strpos($limit, ',') !== false) {
            [$offset, $count] = array_map('intval', explode(',', $limit, 2));
            $count = max(1, $count);
            $query['limit'] = $count;
            $query['page'] = (int) floor($offset / $count) + 1;
            return;
        }

        $query['limit'] = (int) $limit;
    }

    /**
     * Forward native PayloadCMS options the caller may have set directly.
     *
     * @param array<string,mixed> $options
     * @param array<string,mixed> $query
     */
    private function applyPassThrough(array $options, array &$query): void
    {
        foreach (['locale', 'page', 'draft', 'fallback-locale'] as $key) {
            if (isset($options[$key]) && !isset($query[$key])) {
                $query[$key] = $options[$key];
            }
        }
    }

    /**
     * Translate a legacy field reference (with an optional "Model." prefix) into the
     * matching ScreenOver field/relation.
     *
     * Resolution order:
     *   - direct lookup on the full token (e.g. "Category.id" => "categories.category");
     *   - otherwise drop the "Model." prefix and retry the lookup
     *     (e.g. "Media.id" => "id", "Media.title" => "title").
     */
    private function resolveField(string $field): string
    {
        $field = trim($field);

        if (isset(self::FIELD_MAP[strtolower($field)])) {
            return self::FIELD_MAP[strtolower($field)];
        }

        $stripped = $this->stripModel($field);

        return self::FIELD_MAP[strtolower($stripped)] ?? $stripped;
    }

    /**
     * Remove a "Model." prefix from a field reference (e.g. "Media.title" => "title").
     */
    private function stripModel(string $field): string
    {
        $dot = strrpos($field, '.');
        return $dot === false ? $field : substr($field, $dot + 1);
    }
}
