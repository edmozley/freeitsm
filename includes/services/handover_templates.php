<?php
/**
 * Handover document templates (discussion #56).
 *
 * Lets an administrator lay out the equipment handover document: which sections
 * appear, in what order, what they say, and which columns the equipment table
 * shows — with merge codes for the person, the date and so on.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  WHY BLOCKS AND NOT A FREE-FORM EDITOR
 * ─────────────────────────────────────────────────────────────────────────────
 * The obvious "designer" is a rich-text canvas you type a document into. It is
 * the wrong tool here for one structural reason: the middle of this document is
 * a REPEATING REGION. One row per asset, count unknown until it renders. Every
 * WYSIWYG editor either cannot express that or expresses it as a loop the
 * administrator has to write, which is not a designer any more.
 *
 * So the document is a list of blocks from a fixed catalogue. You reorder them,
 * switch them off, edit the words in the ones that have words, and choose the
 * columns on the one that is a table. That covers what people actually change
 * about a handover form, and it means:
 *
 *   - the stored template is always renderable (nothing arbitrary is kept)
 *   - the equipment table stays a real table rather than hand-written markup
 *   - an added block type appears in the designer for free
 *
 * ⚠️ NOTHING FREE-FORM IS EVER STORED AS MARKUP. Editable text is plain text,
 * escaped at render. Merge codes are substituted AFTER escaping, so a person
 * whose display name contains markup cannot inject anything into a document
 * that gets emailed. See renderBlocks().
 */

require_once __DIR__ . '/../timezone.php';   // DateFmt - the merge codes render dates
require_once __DIR__ . '/../i18n.php';

class HandoverTemplates
{
    /**
     * The block catalogue. `text` lists the editable plain-text fields on that
     * block, with their defaults.
     *
     * ⚠️ Adding a block here adds it to the designer and the renderer. A block
     * NOT in this list is dropped on save — that is what makes stored templates
     * safe to render without further checking.
     */
    public static function catalogue(): array
    {
        return [
            'logo'         => ['text' => []],
            'title'        => ['text' => ['heading' => 'Equipment handover record',
                                          'subheading' => 'Prepared {{date}}']],
            'intro'        => ['text' => ['body' => '']],
            'person'       => ['text' => ['heading' => 'Issued to']],
            'assets'       => ['text' => ['heading' => 'Equipment ({{asset_count}} items)'],
                               'columns' => true],
            'declaration'  => ['text' => ['body' => 'I confirm that I have received the equipment listed above, that it is in working order, and that it remains the property of the organisation. I will take reasonable care of it and return it on request or when I leave.']],
            'signatures'   => ['text' => ['employee' => 'Signature and date',
                                          'it'       => 'IT representative - signature and date']],
            'footer'       => ['text' => ['left'  => 'Record reference: USR-{{employee.id}}',
                                          'right' => 'Generated {{date}}']],
        ];
    }

    /**
     * Columns the equipment table can show, and their defaults.
     *
     * With a $conn, every custom asset field is offered too, keyed `cf:<key>`
     * and defaulting to OFF — a handover document is a legal-ish record that
     * somebody signs, so nothing appears on it that an administrator has not
     * deliberately put there.
     *
     * ⚠️ Offering ALL custom fields rather than only the ones ticked "offer as
     * a column": that flag is about the asset LIST, and a serial-number-ish
     * field you would never put in a table is exactly the sort of thing you do
     * want on a handover. Overloading one flag for two unrelated screens is how
     * settings stop meaning anything.
     */
    public static function assetColumns(?PDO $conn = null): array
    {
        $cols = [
            'type'     => true,
            'name'     => true,
            'model'    => true,
            'serial'   => true,
            'tag'      => true,
            'assigned' => true,
            'location' => false,
            'status'   => false,
            'notes'    => false,
        ];
        foreach (self::customColumns($conn) as $key => $def) {
            $cols[$key] = false;
        }
        return $cols;
    }

    /**
     * Custom asset fields available as handover columns, as `cf:<key>` => label.
     *
     * Cached per request: this is called from the designer, the validator and
     * the renderer, and none of them should each pay for it.
     */
    public static function customColumns(?PDO $conn = null): array
    {
        static $cache = null;
        if ($conn === null) {
            // No connection: the caller is a context that never had one (a saved
            // template being validated in isolation). Whatever was cached from
            // this request still applies; otherwise assume none.
            return $cache ?? [];
        }
        if ($cache === null) {
            $cache = [];
            try {
                $rows = $conn->query(
                    "SELECT field_key, label FROM asset_fields WHERE is_deleted = 0 ORDER BY label"
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $cache['cf:' . $r['field_key']] = $r['label'];
                }
            } catch (Exception) {
                // Schema not ready — no custom columns, and the document still prints.
                $cache = [];
            }
        }
        return $cache;
    }

    /**
     * Merge codes, as code => description key.
     *
     * ⚠️ This is the whole list. A code not here is left alone rather than
     * blanked, so a typo shows up in the preview as itself instead of silently
     * vanishing — which is the difference between "I mistyped" and "the feature
     * is broken".
     */
    public static function mergeCodes(): array
    {
        return [
            '{{employee.name}}',
            '{{employee.email}}',
            '{{employee.id}}',
            '{{asset_count}}',
            '{{date}}',
            '{{analyst.name}}',
        ];
    }

    /** The template used when nobody has made one — the document as it shipped. */
    public static function defaultBlocks(): array
    {
        $out = [];
        foreach (self::catalogue() as $type => $def) {
            $block = ['type' => $type, 'enabled' => true, 'text' => []];
            foreach ($def['text'] as $k => $v) {
                $block['text'][$k] = $v;
            }
            if (!empty($def['columns'])) {
                $block['columns'] = self::assetColumns();
            }
            $out[] = $block;
        }
        return $out;
    }

    /**
     * Force whatever arrived from the browser into the shape above.
     *
     * Unknown block types are dropped, unknown text fields are dropped, unknown
     * columns are dropped, and anything missing falls back to its default. The
     * result is always renderable, which is why renderBlocks() does no checking
     * of its own.
     *
     * @param mixed $input  the blocks as sent or stored - a list of {type, enabled, text, columns} or its JSON; anything else gives the defaults
     */
    public static function sanitiseBlocks($input): array
    {
        $catalogue = self::catalogue();
        $columns   = self::assetColumns();

        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (!is_array($input)) {
            return self::defaultBlocks();
        }

        $seen = [];
        $out  = [];
        foreach ($input as $raw) {
            if (!is_array($raw)) continue;
            $type = (string)($raw['type'] ?? '');
            if (!isset($catalogue[$type]) || isset($seen[$type])) {
                continue;                       // unknown, or a duplicate block
            }
            $seen[$type] = true;

            $block = ['type' => $type, 'enabled' => !empty($raw['enabled']), 'text' => []];
            foreach ($catalogue[$type]['text'] as $field => $default) {
                $val = $raw['text'][$field] ?? $default;
                // Plain text only. Length capped so a template cannot become a
                // vehicle for a very large document.
                $block['text'][$field] = mb_substr(trim((string)$val), 0, 2000);
            }
            if (!empty($catalogue[$type]['columns'])) {
                $block['columns'] = [];
                foreach ($columns as $col => $default) {
                    $block['columns'][$col] = array_key_exists($col, (array)($raw['columns'] ?? []))
                        ? (bool)$raw['columns'][$col]
                        : $default;
                }
            }
            $out[] = $block;
        }

        // Any block the client never sent is appended, switched off. That way a
        // template saved by an older client does not permanently lose a block
        // added to the catalogue since.
        foreach ($catalogue as $type => $def) {
            if (isset($seen[$type])) continue;
            $block = ['type' => $type, 'enabled' => false, 'text' => []];
            foreach ($def['text'] as $k => $v) $block['text'][$k] = $v;
            if (!empty($def['columns'])) $block['columns'] = $columns;
            $out[] = $block;
        }

        return $out;
    }

    // ======================================================================
    //  Storage
    // ======================================================================

    /**
     * Every handover template (id, name, default, active, updated), the default first;
     * empty before the table exists.
     */
    public static function listAll(PDO $conn): array
    {
        try {
            $rows = $conn->query(
                "SELECT id, name, is_default, is_active, updated_datetime
                   FROM asset_handover_templates ORDER BY is_default DESC, name"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception) {
            return [];
        }
        foreach ($rows as &$r) {
            $r['id']         = (int)$r['id'];
            $r['is_default'] = (bool)$r['is_default'];
            $r['is_active']  = (bool)$r['is_active'];
        }
        return $rows;
    }

    /**
     * Loads one handover template with its blocks cleaned, or null when it does not exist.
     *
     * @param int $id  the asset_handover_templates row
     */
    public static function load(PDO $conn, int $id): ?array
    {
        self::customColumns($conn);   // see effective() — sanitiseBlocks needs it warm
        $s = $conn->prepare("SELECT * FROM asset_handover_templates WHERE id = ?");
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['id']         = (int)$row['id'];
        $row['is_default'] = (bool)$row['is_default'];
        $row['is_active']  = (bool)$row['is_active'];
        $row['blocks']     = self::sanitiseBlocks($row['blocks']);
        return $row;
    }

    /**
     * The template a handover document should use.
     *
     * Falls back to the shipped default rather than failing: a handover document
     * must be printable on an install that has never opened the designer, and on
     * one where somebody deactivated every template.
     *
     * @param ?int $id  the template asked for; null, 0, missing or inactive falls back to the default
     */
    public static function effective(PDO $conn, ?int $id = null): array
    {
        // ⚠️ Warm the custom-column list while a connection is in hand.
        // sanitiseBlocks(), defaultBlocks() and renderBlocks() take no $conn —
        // they are pure transformations over a stored template — so they read
        // it from the per-request cache. Every path that renders or validates a
        // template comes through here or load(), so it is always warm by then;
        // without this the columns would silently vanish from a document.
        self::customColumns($conn);
        try {
            if ($id !== null && $id > 0) {
                $t = self::load($conn, $id);
                if ($t && $t['is_active']) return $t;
            }
            $s = $conn->query(
                "SELECT * FROM asset_handover_templates
                  WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1"
            );
            $row = $s ? $s->fetch(PDO::FETCH_ASSOC) : false;
            if ($row) {
                $row['id']     = (int)$row['id'];
                $row['blocks'] = self::sanitiseBlocks($row['blocks']);
                return $row;
            }
        } catch (Exception) {
            // Table missing on a part-migrated install — fall through.
        }
        return ['id' => 0, 'name' => 'Default', 'blocks' => self::defaultBlocks()];
    }

    /**
     * Adds or updates a handover template and returns its id; can also make it the default.
     *
     * @param array $in  id (0 or absent = new), name (required), blocks, is_active, is_default
     */
    public static function save(PDO $conn, array $in): int
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new Exception('A template name is required.');
        }
        $blocks = json_encode(self::sanitiseBlocks($in['blocks'] ?? null));
        $active = array_key_exists('is_active', $in) ? (int)(bool)$in['is_active'] : 1;
        $id     = (int)($in['id'] ?? 0);

        if ($id > 0) {
            $conn->prepare(
                "UPDATE asset_handover_templates
                    SET name = ?, blocks = ?, is_active = ?, updated_datetime = UTC_TIMESTAMP()
                  WHERE id = ?"
            )->execute([mb_substr($name, 0, 120), $blocks, $active, $id]);
        } else {
            $conn->prepare(
                "INSERT INTO asset_handover_templates (name, blocks, is_active, created_datetime, updated_datetime)
                 VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([mb_substr($name, 0, 120), $blocks, $active]);
            $id = (int)$conn->lastInsertId();
        }

        if (!empty($in['is_default'])) {
            self::makeDefault($conn, $id);
        }
        return $id;
    }

    /**
     * Exactly one default. Cleared for everyone else in the same breath.
     *
     * @param int $id  the template to make the one default (it is also made active)
     */
    public static function makeDefault(PDO $conn, int $id): void
    {
        $conn->prepare("UPDATE asset_handover_templates SET is_default = 0")->execute();
        $conn->prepare("UPDATE asset_handover_templates SET is_default = 1, is_active = 1 WHERE id = ?")->execute([$id]);
    }

    /**
     * Deletes a handover template.
     *
     * @param int $id  the template to delete
     */
    public static function delete(PDO $conn, int $id): void
    {
        $conn->prepare("DELETE FROM asset_handover_templates WHERE id = ?")->execute([$id]);
    }

    // ======================================================================
    //  Rendering
    // ======================================================================

    /**
     * The values merge codes resolve to, for this person on this day.
     *
     * @param array $user  the person receiving the kit, with name, email and id
     * @param array $assets  the assets being handed over; only counted here
     * @param ?string $analystName  the analyst producing the document, for {{analyst.name}}
     */
    public static function mergeValues(array $user, array $assets, ?string $analystName): array
    {
        return [
            '{{employee.name}}'  => (string)($user['name'] ?? ''),
            '{{employee.email}}' => (string)($user['email'] ?? ''),
            '{{employee.id}}'    => (string)($user['id'] ?? ''),
            '{{asset_count}}'    => (string)count($assets),
            '{{date}}'           => DateFmt::render(new DateTime('now', new DateTimeZone(Tz::current())), 'D MONTH YYYY'),
            '{{analyst.name}}'   => (string)($analystName ?? ''),
        ];
    }

    /**
     * Escape first, substitute second.
     *
     * ⚠️ THE ORDER IS THE SECURITY. Escaping the finished string instead would
     * escape the caller's own markup; substituting into un-escaped text would let
     * a display name containing markup reach a document that is emailed out.
     * Values are escaped individually, then dropped into already-escaped text.
     *
     * @param string $text  the template text as typed - plain text, escaped here
     * @param array $values  [merge code => value] from mergeValues(); values are escaped here too
     */
    private static function mergeText(string $text, array $values): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $from = array_keys($values);
        $to   = array_map(fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), array_values($values));
        return str_replace($from, $to, $escaped);
    }

    /**
     * Render the document body as HTML.
     *
     * Returns markup for the inside of the sheet — the page (or the email) supplies
     * the wrapper and the stylesheet, so the same renderer serves the printable
     * page, the designer preview and the emailed copy without three layouts.
     *
     * @param array $blocks  the template's blocks, already cleaned by sanitiseBlocks()
     * @param array $user  the person receiving the kit, with name, email and id
     * @param array $assets  the asset rows to list, as AssetsService::assetsForUser() returns them
     * @param array $opts  analyst_name, logo_path (an image URL) and labels (overrides for the active locale's headings)
     */
    public static function renderBlocks(array $blocks, array $user, array $assets, array $opts = []): string
    {
        $values    = self::mergeValues($user, $assets, $opts['analyst_name'] ?? null);
        $logoPath  = $opts['logo_path'] ?? null;
        $columnsOn = self::assetColumns();
        // All three destinations need the same labels, even when the caller supplies none.
        $labels    = ($opts['labels'] ?? []) + [
            'name'         => t('asset-management.handover.field_name'),
            'email'        => t('asset-management.handover.field_email'),
            'col_type'     => t('asset-management.handover.col_type'),
            'col_name'     => t('asset-management.handover.col_name'),
            'col_model'    => t('asset-management.handover.col_model'),
            'col_serial'   => t('asset-management.handover.col_serial'),
            'col_tag'      => t('asset-management.handover.col_tag'),
            'col_assigned' => t('asset-management.handover.col_assigned'),
            'col_location' => t('asset-management.handover.col_location'),
            'col_status'   => t('asset-management.handover.col_status'),
            'col_notes'    => t('asset-management.handover.col_notes'),
            'none'         => t('asset-management.handover.none'),
        ];
        $L = function (string $k, string $fallback) use ($labels) {
            return isset($labels[$k]) && $labels[$k] !== '' ? $labels[$k] : $fallback;
        };

        $html = '';
        foreach ($blocks as $b) {
            if (empty($b['enabled'])) continue;
            $t    = $b['type'];
            $text = $b['text'] ?? [];

            switch ($t) {
                case 'logo':
                    if ($logoPath) {
                        $html .= '<div class="hb-logo"><img src="' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" alt=""></div>';
                    }
                    break;

                case 'title':
                    $html .= '<div class="hb-title-wrap">'
                           . '<h1 class="doc-title">' . self::mergeText($text['heading'] ?? '', $values) . '</h1>'
                           . '<div class="doc-sub">' . self::mergeText($text['subheading'] ?? '', $values) . '</div>'
                           . '</div>';
                    break;

                case 'intro':
                    $body = trim((string)($text['body'] ?? ''));
                    if ($body !== '') {
                        // nl2br so a paragraph break typed in the designer survives,
                        // applied AFTER escaping so it is the only markup that can appear.
                        $html .= '<p class="hb-intro">' . nl2br(self::mergeText($body, $values)) . '</p>';
                    }
                    break;

                case 'person':
                    $html .= '<div class="section-title">' . self::mergeText($text['heading'] ?? '', $values) . '</div>'
                           . '<div class="who">'
                           . '<div><div class="field-label">' . htmlspecialchars($L('name', 'Name'), ENT_QUOTES, 'UTF-8') . '</div>'
                           . '<div class="field-value">' . htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></div>'
                           . '<div><div class="field-label">' . htmlspecialchars($L('email', 'Email'), ENT_QUOTES, 'UTF-8') . '</div>'
                           . '<div class="field-value">' . htmlspecialchars((string)($user['email'] ?? '—'), ENT_QUOTES, 'UTF-8') . '</div></div>'
                           . '</div>';
                    break;

                case 'assets':
                    $cols = $b['columns'] ?? $columnsOn;
                    $html .= '<div class="section-title">' . self::mergeText($text['heading'] ?? '', $values) . '</div>'
                           . self::renderAssetTable($assets, $cols, $L);
                    break;

                case 'declaration':
                    $body = trim((string)($text['body'] ?? ''));
                    if ($body !== '') {
                        $html .= '<div class="declaration">' . nl2br(self::mergeText($body, $values)) . '</div>';
                    }
                    break;

                case 'signatures':
                    $html .= '<div class="signatures">'
                           . '<div><div class="sig-line"></div>'
                           . '<div class="sig-name">' . htmlspecialchars((string)($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>'
                           . '<div class="sig-label">' . self::mergeText($text['employee'] ?? '', $values) . '</div></div>'
                           . '<div><div class="sig-line"></div>'
                           . '<div class="sig-name">&nbsp;</div>'
                           . '<div class="sig-label">' . self::mergeText($text['it'] ?? '', $values) . '</div></div>'
                           . '</div>';
                    break;

                case 'footer':
                    $html .= '<div class="doc-foot">'
                           . '<span>' . self::mergeText($text['left'] ?? '', $values) . '</span>'
                           . '<span>' . self::mergeText($text['right'] ?? '', $values) . '</span>'
                           . '</div>';
                    break;
            }
        }
        return $html;
    }

    /**
     * The HTML table of the handed-over assets, with the chosen columns including custom asset fields.
     *
     * @param array $assets  the asset rows to list, with any custom field values under 'custom'
     * @param array $cols  [column key => bool] - which columns are switched on
     * @param callable $L  fn(string key, string fallback): string - a translated label, or the fallback
     */
    private static function renderAssetTable(array $assets, array $cols, callable $L): string
    {
        $defs = [
            'type'     => ['label' => $L('col_type', 'Type'),           'get' => fn($a) => $a['asset_type'] ?? '—'],
            'name'     => ['label' => $L('col_name', 'Name'),           'get' => fn($a) => $a['hostname'] ?? '—', 'strong' => true],
            'model'    => ['label' => $L('col_model', 'Make and model'), 'get' => fn($a) => trim(($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? '')) ?: '—'],
            'serial'   => ['label' => $L('col_serial', 'Serial number'), 'get' => fn($a) => $a['service_tag'] ?? '—', 'mono' => true],
            'tag'      => ['label' => $L('col_tag', 'Asset tag'),        'get' => fn($a) => $a['asset_tag'] ?? '—', 'mono' => true],
            // fmt_local: assigned_datetime is a UTC instant, and near midnight the
            // server's zone and the reader's disagree about which DAY it was.
            'assigned' => ['label' => $L('col_assigned', 'Assigned'),    'get' => fn($a) => !empty($a['assigned_datetime']) ? fmt_local($a['assigned_datetime'], 'j M Y') : '—'],
            'location' => ['label' => $L('col_location', 'Location'),    'get' => fn($a) => $a['location'] ?? '—'],
            'status'   => ['label' => $L('col_status', 'Status'),        'get' => fn($a) => $a['asset_status'] ?? '—'],
            'notes'    => ['label' => $L('col_notes', 'Notes'),          'get' => fn($a) => $a['notes'] ?? '—'],
        ];

        // Custom asset fields. The label is the administrator's own wording, so
        // it is NOT run through $L — there is nothing to translate it to.
        //
        // 🔑 A field the asset does not carry prints an em dash, exactly like an
        // empty built-in column. A signed document must not imply "no" where it
        // means "never recorded".
        foreach (self::customColumns() as $colKey => $label) {
            $fieldKey = substr($colKey, 3);   // strip the "cf:" prefix
            $defs[$colKey] = [
                'label' => $label,
                'get'   => function ($a) use ($fieldKey) {
                    $v = $a['custom'][$fieldKey] ?? null;
                    if ($v === null || $v === '') {
                        return '—';
                    }
                    if (is_bool($v)) {
                        return $v ? 'Yes' : 'No';
                    }
                    return (string)$v;
                },
            ];
        }

        $active = array_values(array_filter(array_keys($defs), fn($k) => !empty($cols[$k])));
        if (!$active) {
            $active = ['type', 'name'];    // a table with no columns is not a table
        }

        $html = '<table><thead><tr>';
        foreach ($active as $k) {
            $html .= '<th>' . htmlspecialchars($defs[$k]['label'], ENT_QUOTES, 'UTF-8') . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        if (!$assets) {
            $html .= '<tr><td class="none-row" colspan="' . count($active) . '">'
                   . htmlspecialchars($L('none', 'No equipment is currently assigned to this person.'), ENT_QUOTES, 'UTF-8')
                   . '</td></tr>';
        } else {
            foreach ($assets as $a) {
                $html .= '<tr>';
                foreach ($active as $k) {
                    $d = $defs[$k];
                    $v = htmlspecialchars((string)($d['get'])($a), ENT_QUOTES, 'UTF-8');
                    $cls = !empty($d['mono']) ? ' class="mono"' : '';
                    $html .= '<td' . $cls . '>' . (!empty($d['strong']) ? '<strong>' . $v . '</strong>' : $v) . '</td>';
                }
                $html .= '</tr>';
            }
        }
        return $html . '</tbody></table>';
    }
}
