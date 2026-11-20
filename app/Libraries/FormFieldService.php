<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * The submission form, per role (student / faculty / non_teaching).
 *
 * Built-in fields (ID, name, contact, department, hostel, semester) come from the master list. The admin can
 * rename them and reorder them; core ones can never be hidden, and their mandatory setting is fixed.
 * Custom fields (text / number / dropdown / date) are added by the admin and filled in by the submitter.
 *
 * A custom field that appears in any grievance can only be hidden (old grievances keep their snapshot);
 * one that was never used can be deleted.
 */
class FormFieldService
{
    public const TYPES = ['text' => 'Text', 'number' => 'Number', 'dropdown' => 'Dropdown (choose one)', 'date' => 'Date'];

    private const MAX_OPTIONS = 50;
    private const MAX_FIELDS  = 30;

    /**
     * All fields of a role in form order, with a usage count for custom fields.
     *
     * @return list<array<string,mixed>>
     */
    public static function fields(string $role): array
    {
        self::assertRole($role);
        $rows = Database::connect()->query('SELECT * FROM form_fields WHERE role = ? ORDER BY sort_order, id', [$role])->getResultArray();

        foreach ($rows as &$r) {
            $r['custom']  = self::isCustom($r);
            $r['options'] = is_array($r['options']) ? $r['options'] : (json_decode((string) $r['options'], true) ?: []);
            $r['usage']   = $r['custom'] ? self::usage($role, $r['field_key']) : 0;
        }

        return $rows;
    }

    public static function isCustom(array $field): bool
    {
        return str_starts_with((string) $field['field_key'], 'cf_');
    }

    /** Number of grievances that carry a value for this custom field. */
    public static function usage(string $role, string $key): int
    {
        return (int) Database::connect()->query('SELECT count(*) AS n FROM grievances WHERE role = ? AND jsonb_exists(custom, ?)', [$role, $key])->getRow('n');
    }

    /** @return list<string> */
    public static function parseOptions(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && ! in_array(mb_strtolower($line), array_map('mb_strtolower', $out), true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function validate(string $role, string $label, string $type, string $optionsText, ?int $ignoreId = null, bool $needsOptions = false): array
    {
        $errors = [];
        $label  = trim($label);

        if ($label === '' || mb_strlen($label) > 150) {
            $errors[] = 'Label is required (up to 150 characters).';
        } else {
            $dup = Database::connect()->query('SELECT 1 FROM form_fields WHERE role = ? AND lower(label) = lower(?) AND id <> ?', [$role, $label, $ignoreId ?? 0])->getRowArray();
            if ($dup) {
                $errors[] = "A field labelled '{$label}' already exists on this form.";
            }
        }

        if (! isset(self::TYPES[$type])) {
            $errors[] = 'Choose a field type.';
        }

        if ($type === 'dropdown' || $needsOptions) {
            $options = self::parseOptions($optionsText);
            if (count($options) < 2) {
                $errors[] = 'A dropdown needs at least two choices, one per line.';
            } elseif (count($options) > self::MAX_OPTIONS) {
                $errors[] = 'A dropdown can have at most ' . self::MAX_OPTIONS . ' choices.';
            } elseif (array_filter($options, static fn ($o) => mb_strlen($o) > 100) !== []) {
                $errors[] = 'Each choice can be at most 100 characters.';
            }
        }

        return $errors;
    }

    public static function add(string $role, string $label, string $type, string $optionsText, bool $mandatory): int
    {
        self::assertRole($role);
        $label  = trim($label);
        $errors = self::validate($role, $label, $type, $optionsText);
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $db = Database::connect();
        if ((int) $db->query('SELECT count(*) AS n FROM form_fields WHERE role = ?', [$role])->getRow('n') >= self::MAX_FIELDS) {
            throw new RuntimeException('A form can have at most ' . self::MAX_FIELDS . ' fields.');
        }

        $db->transStart();
        $db->table('form_fields')->insert([
            'role'       => $role,
            'field_key'  => self::newKey($role, $label),
            'label'      => $label,
            'field_type' => $type,
            'options'    => json_encode($type === 'dropdown' ? self::parseOptions($optionsText) : [], JSON_UNESCAPED_UNICODE),
            'mandatory'  => pg_flag($mandatory),
            'visible'    => pg_flag(true),
            'sort_order' => 1000,
            'is_core'    => pg_flag(false),
        ]);
        $id = (int) $db->insertID();
        self::renumber($role);
        AuditLogger::log('create', 'form_field', $id, null, ['role' => $role, 'label' => $label, 'type' => $type, 'mandatory' => $mandatory]);
        $db->transComplete();

        return $id;
    }

    /**
     * Change label, mandatory, visible and (custom dropdowns) the choices.
     * Core fields ignore visible/mandatory changes: they are always shown and their rule is fixed.
     */
    public static function update(int $id, string $label, bool $mandatory, bool $visible, string $optionsText): void
    {
        $f      = self::find($id);
        $custom = self::isCustom($f);
        $core   = is_true($f['is_core']);
        $label  = trim($label);

        // Built-in dropdowns (department, hostel, semester) take their choices from the lists, so only custom ones need choices here
        $checkType = $f['field_type'] === 'dropdown' && ! $custom ? 'text' : $f['field_type'];
        $errors    = self::validate($f['role'], $label, $checkType, $optionsText, $id, $custom && $f['field_type'] === 'dropdown');
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        $newMandatory = $core ? is_true($f['mandatory']) : $mandatory;
        $newVisible   = $core ? true : $visible;
        if (! $newVisible) {
            $newMandatory = false;
        }
        $options = $custom && $f['field_type'] === 'dropdown' ? self::parseOptions($optionsText) : null;

        $sql  = 'UPDATE form_fields SET label = ?, mandatory = ?, visible = ?, updated_at = now()' . ($options !== null ? ', options = ?::jsonb' : '') . ' WHERE id = ?';
        $args = [$label, pg_flag($newMandatory), pg_flag($newVisible), ...($options !== null ? [json_encode($options, JSON_UNESCAPED_UNICODE)] : []), $id];
        Database::connect()->query($sql, $args);

        AuditLogger::log('update', 'form_field', $id,
            ['label' => $f['label'], 'mandatory' => is_true($f['mandatory']), 'visible' => is_true($f['visible'])],
            ['label' => $label, 'mandatory' => $newMandatory, 'visible' => $newVisible, 'role' => $f['role']]);
    }

    public static function move(int $id, string $direction): void
    {
        $f = self::find($id);
        if (! in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('Unknown direction.');
        }

        $db   = Database::connect();
        $rows = $db->query('SELECT id FROM form_fields WHERE role = ? ORDER BY sort_order, id', [$f['role']])->getResultArray();
        $ids  = array_map('intval', array_column($rows, 'id'));
        $pos  = array_search($id, $ids, true);
        $swap = $direction === 'up' ? $pos - 1 : $pos + 1;
        if (! isset($ids[$swap])) {
            return;
        }

        [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
        $db->transStart();
        foreach ($ids as $i => $fid) {
            $db->query('UPDATE form_fields SET sort_order = ? WHERE id = ?', [$i + 1, $fid]);
        }
        AuditLogger::log('move', 'form_field', $id, null, ['label' => $f['label'], 'role' => $f['role'], 'direction' => $direction]);
        $db->transComplete();
    }

    public static function delete(int $id): void
    {
        $f = self::find($id);
        if (! self::isCustom($f)) {
            throw new RuntimeException("'{$f['label']}' is a built-in field and cannot be deleted.");
        }
        if (self::usage($f['role'], $f['field_key']) > 0) {
            throw new RuntimeException("'{$f['label']}' appears in existing grievances, so it can only be hidden.");
        }

        $db = Database::connect();
        $db->transStart();
        $db->query('DELETE FROM form_fields WHERE id = ?', [$id]);
        self::renumber($f['role']);
        AuditLogger::log('delete', 'form_field', $id, ['role' => $f['role'], 'label' => $f['label']], null);
        $db->transComplete();
    }

    /** @return array<string,mixed> */
    public static function find(int $id): array
    {
        $row = Database::connect()->query('SELECT * FROM form_fields WHERE id = ?', [$id])->getRowArray();
        if ($row === null) {
            throw new RuntimeException('That field does not exist.');
        }

        return $row;
    }

    // ---------------------------------------------------------------------

    private static function renumber(string $role): void
    {
        $db   = Database::connect();
        $rows = $db->query('SELECT id FROM form_fields WHERE role = ? ORDER BY sort_order, id', [$role])->getResultArray();
        foreach ($rows as $i => $r) {
            $db->query('UPDATE form_fields SET sort_order = ? WHERE id = ?', [$i + 1, $r['id']]);
        }
    }

    /** A stable internal key such as cf_roll_of_honour (never shown to users). */
    private static function newKey(string $role, string $label): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label), '_'));
        $slug = substr($slug === '' ? 'field' : $slug, 0, 40);
        $key  = "cf_{$slug}";
        $db   = Database::connect();
        $n    = 1;
        // A key is never reused, even after a delete, because old grievances may still carry it
        while ($db->query('SELECT 1 FROM form_fields WHERE role = ? AND field_key = ?', [$role, $key])->getRowArray() !== null
            || $db->query('SELECT 1 FROM grievances WHERE role = ? AND jsonb_exists(custom, ?)', [$role, $key])->getRowArray() !== null) {
            $key = "cf_{$slug}_" . (++$n);
        }

        return $key;
    }

    private static function assertRole(string $role): void
    {
        if (! in_array($role, PostService::ROLES, true)) {
            throw new RuntimeException('Unknown role.');
        }
    }
}
