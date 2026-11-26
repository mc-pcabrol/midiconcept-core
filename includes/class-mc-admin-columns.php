<?php
/**
 * MidiConcept Core – Admin Columns
 * Description: Déclaration simple de colonnes personnalisées (ACF, méta, taxonomie, miniature, callback) dans les listes admin, avec tri et filtres.
 *
 * Chargé par le plugin MidiConcept Core. Configurer dans le hook "midiconcept_core_init".
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * UTILISATION
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * MC_Admin_Columns::add('vehicule', [
 *     'photo'  => ['label' => '', 'thumbnail' => true, 'after' => 'cb', 'width' => '70px'],
 *     'prix'   => ['label' => 'Prix', 'acf' => 'prix', 'format' => 'price', 'sortable' => 'numeric'],
 *     'annee'  => ['label' => 'Année', 'acf' => 'annee', 'sortable' => 'numeric', 'filter' => true],
 *     'marque' => ['label' => 'Marque', 'taxonomy' => 'marque', 'filter' => true],
 *     'vendu'  => ['label' => 'Vendu', 'acf' => 'vendu', 'format' => 'bool', 'filter' => ['1' => 'Oui', '0' => 'Non']],
 *     'ref'    => ['label' => 'Réf.', 'callback' => fn($id) => 'V-' . $id],
 * ]);
 *
 * MC_Admin_Columns::remove('vehicule', ['author', 'comments']);
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * OPTIONS D'UNE COLONNE
 * ─────────────────────────────────────────────────────────────────────────────
 * label     string          Titre de la colonne.
 * Source (une seule) :
 *   acf       string        Nom du champ ACF (get_field, repli sur get_post_meta si ACF absent).
 *   meta      string        Clé de méta brute.
 *   taxonomy  string        Taxonomie : termes cliquables (filtrent la liste).
 *   thumbnail bool          Image mise en avant.
 *   callback  callable      fn(int $post_id): string — HTML libre (passé dans wp_kses_post).
 * format    string          'price' | 'number' | 'date' | 'bool' | 'image' (pour acf/meta).
 * sortable  bool|string     true|'alpha' | 'numeric' | 'date' (acf/meta uniquement).
 * filter    bool|array      true = liste déroulante auto (termes ou valeurs distinctes),
 *                           ou tableau ['valeur' => 'Libellé'] pour imposer les choix.
 * after     string          Clé de la colonne après laquelle insérer (défaut : avant 'date').
 * width     string          Largeur CSS, ex. '80px' ou '10%'.
 * empty     string          Affichage si vide (défaut : '—').
 */

defined('ABSPATH') || exit;

if (!class_exists('MC_Admin_Columns')) :

final class MC_Admin_Columns
{
    /** @var array<string, array<string, array>> post_type => [clé => args] */
    private static array $columns = [];

    /** @var array<string, string[]> post_type => clés à retirer */
    private static array $removed = [];

    /** @var array<string, bool> types de contenu déjà branchés */
    private static array $hooked = [];

    private static bool $booted = false;

    private const FILTER_PREFIX = 'mc_f_';

    /* ───────────────────────────── API publique ───────────────────────────── */

    public static function add(string $post_type, array $columns): void
    {
        foreach ($columns as $key => $args) {
            self::$columns[$post_type][(string) $key] = self::normalize((array) $args);
        }
        self::hook_post_type($post_type);
    }

    public static function remove(string $post_type, array $keys): void
    {
        self::$removed[$post_type] = array_unique(array_merge(self::$removed[$post_type] ?? [], $keys));
        self::hook_post_type($post_type);
    }

    /** Taxonomies déjà filtrées par une colonne, pour ne pas doubler la liste déroulante. */
    public static function filtered_taxonomies(string $post_type): array
    {
        $out = [];
        foreach (self::$columns[$post_type] ?? [] as $args) {
            if (!empty($args['filter']) && !empty($args['taxonomy'])) {
                $out[] = (string) $args['taxonomy'];
            }
        }
        return $out;
    }

    /* ─────────────────────────────── Branchement ──────────────────────────── */

    private static function normalize(array $args): array
    {
        return array_merge([
            'label'     => '',
            'acf'       => null,
            'meta'      => null,
            'taxonomy'  => null,
            'thumbnail' => false,
            'callback'  => null,
            'format'    => null,
            'sortable'  => false,
            'filter'    => false,
            'after'     => null,
            'width'     => null,
            'empty'     => '—',
        ], $args);
    }

    private static function hook_post_type(string $pt): void
    {
        self::boot();

        if (isset(self::$hooked[$pt])) {
            return;
        }
        self::$hooked[$pt] = true;

        add_filter("manage_{$pt}_posts_columns", static fn(array $cols) => self::filter_columns($pt, $cols), 20);
        add_action("manage_{$pt}_posts_custom_column", static function (string $col, int $post_id) use ($pt) {
            self::render_cell($pt, $col, $post_id);
        }, 10, 2);
        add_filter("manage_edit-{$pt}_sortable_columns", static fn(array $cols) => self::sortable_columns($pt, $cols));
    }

    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        add_action('restrict_manage_posts', [__CLASS__, 'render_filters'], 10, 2);
        add_action('pre_get_posts', [__CLASS__, 'alter_query']);
        add_action('admin_head-edit.php', [__CLASS__, 'print_css']);
    }

    /* ─────────────────────────────── Colonnes ─────────────────────────────── */

    private static function filter_columns(string $pt, array $cols): array
    {
        foreach (self::$removed[$pt] ?? [] as $key) {
            unset($cols[$key]);
        }

        foreach (self::$columns[$pt] ?? [] as $key => $args) {
            $label = esc_html($args['label']);
            unset($cols[$key]);

            $anchor = $args['after'];
            if ($anchor && isset($cols[$anchor])) {
                $cols = self::insert_after($cols, $anchor, $key, $label);
            } elseif (isset($cols['date'])) {
                $cols = self::insert_before($cols, 'date', $key, $label);
            } else {
                $cols[$key] = $label;
            }
        }

        return $cols;
    }

    private static function insert_after(array $cols, string $anchor, string $key, string $label): array
    {
        $out = [];
        foreach ($cols as $k => $v) {
            $out[$k] = $v;
            if ($k === $anchor) {
                $out[$key] = $label;
            }
        }
        return $out;
    }

    private static function insert_before(array $cols, string $anchor, string $key, string $label): array
    {
        $out = [];
        foreach ($cols as $k => $v) {
            if ($k === $anchor) {
                $out[$key] = $label;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /* ─────────────────────────────── Rendu ────────────────────────────────── */

    private static function render_cell(string $pt, string $col, int $post_id): void
    {
        $args = self::$columns[$pt][$col] ?? null;
        if (!$args) {
            return;
        }

        $html = self::cell_html($pt, $args, $post_id);
        echo ($html === '' || $html === null) ? esc_html($args['empty']) : $html; // phpcs:ignore -- déjà échappé
    }

    private static function cell_html(string $pt, array $args, int $post_id): string
    {
        if ($args['callback'] && is_callable($args['callback'])) {
            return wp_kses_post((string) call_user_func($args['callback'], $post_id));
        }

        if ($args['thumbnail']) {
            return has_post_thumbnail($post_id)
                ? get_the_post_thumbnail($post_id, [60, 60], ['style' => 'width:60px;height:auto;display:block;'])
                : '';
        }

        if ($args['taxonomy']) {
            return self::terms_html($pt, $args['taxonomy'], $post_id);
        }

        if ($args['acf'] || $args['meta']) {
            return self::format_value(self::get_value($args, $post_id), $args['format']);
        }

        return '';
    }

    private static function get_value(array $args, int $post_id)
    {
        if ($args['acf']) {
            return function_exists('get_field')
                ? get_field($args['acf'], $post_id)
                : get_post_meta($post_id, $args['acf'], true);
        }
        return get_post_meta($post_id, $args['meta'], true);
    }

    private static function terms_html(string $pt, string $taxonomy, int $post_id): string
    {
        $terms = get_the_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) {
            return '';
        }

        $tax   = get_taxonomy($taxonomy);
        $qvar  = $tax && $tax->query_var ? $tax->query_var : null;
        $links = [];

        foreach ($terms as $term) {
            $name = esc_html($term->name);
            if ($qvar) {
                $url     = add_query_arg(['post_type' => $pt, $qvar => $term->slug], admin_url('edit.php'));
                $links[] = '<a href="' . esc_url($url) . '">' . $name . '</a>';
            } else {
                $links[] = $name;
            }
        }

        return implode(', ', $links);
    }

    private static function format_value($value, ?string $format): string
    {
        if ($format === 'bool') {
            return $value ? '<span style="color:#00a32a" aria-label="Oui">✔</span>' : '<span aria-label="Non">—</span>';
        }

        if ($value === null || $value === '' || $value === false || $value === []) {
            return '';
        }

        switch ($format) {
            case 'image':
                $id = is_array($value) ? ($value['ID'] ?? $value['id'] ?? 0) : (int) $value;
                return $id ? wp_get_attachment_image($id, [60, 60], false, ['style' => 'width:60px;height:auto;display:block;']) : '';

            case 'price':
                return is_numeric($value) ? esc_html(number_format_i18n((float) $value, 2) . ' €') : esc_html((string) $value);

            case 'number':
                return is_numeric($value) ? esc_html(number_format_i18n((float) $value, 0)) : esc_html((string) $value);

            case 'date':
                $ts = self::to_timestamp($value);
                return $ts ? esc_html(date_i18n(get_option('date_format'), $ts)) : esc_html((string) $value);
        }

        return self::stringify($value);
    }

    /** Gère les retours ACF courants : objets post, termes, tableaux, sélections multiples. */
    private static function stringify($value): string
    {
        if ($value instanceof WP_Post) {
            return '<a href="' . esc_url(get_edit_post_link($value->ID)) . '">' . esc_html(get_the_title($value)) . '</a>';
        }

        if ($value instanceof WP_Term) {
            return esc_html($value->name);
        }

        if (is_array($value)) {
            // Champ "choix" ACF en retour valeur+libellé.
            if (isset($value['label'])) {
                return esc_html((string) $value['label']);
            }
            // Image/fichier ACF en retour tableau.
            if (isset($value['ID'], $value['url'])) {
                return self::format_value($value, 'image');
            }
            $parts = array_filter(array_map([__CLASS__, 'stringify'], $value), static fn($v) => $v !== '');
            return implode(', ', $parts);
        }

        return is_scalar($value) ? esc_html((string) $value) : '';
    }

    private static function to_timestamp($value): ?int
    {
        $value = (string) $value;
        if (preg_match('/^\d{8}$/', $value)) { // format de stockage ACF : Ymd
            $dt = DateTime::createFromFormat('Ymd', $value, wp_timezone());
            return $dt ? $dt->getTimestamp() : null;
        }
        $ts = strtotime($value);
        return $ts ?: null;
    }

    /* ─────────────────────────────── Tri ──────────────────────────────────── */

    private static function sortable_columns(string $pt, array $cols): array
    {
        foreach (self::$columns[$pt] ?? [] as $key => $args) {
            if ($args['sortable'] && self::meta_key($args)) {
                $cols[$key] = $key;
            }
        }
        return $cols;
    }

    private static function meta_key(array $args): ?string
    {
        return $args['acf'] ?: ($args['meta'] ?: null);
    }

    /* ─────────────────────────────── Filtres ──────────────────────────────── */

    public static function render_filters(string $pt, string $which = 'top'): void
    {
        if ($which !== 'top' || empty(self::$columns[$pt])) {
            return;
        }

        foreach (self::$columns[$pt] as $key => $args) {
            if (!$args['filter']) {
                continue;
            }

            if ($args['taxonomy']) {
                self::taxonomy_dropdown($args);
            } elseif (self::meta_key($args)) {
                self::meta_dropdown($pt, $key, $args);
            }
        }
    }

    private static function taxonomy_dropdown(array $args): void
    {
        $tax = get_taxonomy($args['taxonomy']);
        if (!$tax || !$tax->query_var) {
            return;
        }

        $selected = isset($_GET[$tax->query_var]) ? sanitize_title(wp_unslash($_GET[$tax->query_var])) : '';

        wp_dropdown_categories([
            'taxonomy'        => $tax->name,
            'name'            => $tax->query_var,
            'value_field'     => 'slug',
            'selected'        => $selected,
            'show_option_all' => sprintf('%s : tous', $args['label'] ?: $tax->labels->singular_name),
            'hide_empty'      => true,
            'hierarchical'    => $tax->hierarchical,
            'show_count'      => false,
            'orderby'         => 'name',
        ]);
    }

    private static function meta_dropdown(string $pt, string $key, array $args): void
    {
        $param    = self::FILTER_PREFIX . $key;
        $selected = isset($_GET[$param]) ? sanitize_text_field(wp_unslash($_GET[$param])) : '';
        $options  = is_array($args['filter']) ? $args['filter'] : self::distinct_values($pt, self::meta_key($args));

        if (!$options) {
            return;
        }

        printf('<select name="%s">', esc_attr($param));
        printf('<option value="">%s</option>', esc_html(($args['label'] ?: $key) . ' : tous'));
        foreach ($options as $value => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr((string) $value),
                selected($selected, (string) $value, false),
                esc_html((string) $label)
            );
        }
        echo '</select>';
    }

    private static function distinct_values(string $pt, string $meta_key): array
    {
        global $wpdb;

        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value
               FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key = %s
                AND p.post_type = %s
                AND p.post_status NOT IN ('trash', 'auto-draft')
                AND pm.meta_value <> ''
           ORDER BY pm.meta_value ASC
              LIMIT 200",
            $meta_key,
            $pt
        ));

        $values = array_filter($values, static fn($v) => !is_serialized($v));

        return array_combine($values, $values) ?: [];
    }

    /* ─────────────────────────────── Requête ──────────────────────────────── */

    public static function alter_query(WP_Query $q): void
    {
        global $pagenow;

        if (!is_admin() || !$q->is_main_query() || $pagenow !== 'edit.php') {
            return;
        }

        $pt = $q->get('post_type') ?: 'post';
        if (is_array($pt) || empty(self::$columns[$pt])) {
            return;
        }

        $meta_query = (array) $q->get('meta_query');

        // Filtres méta.
        foreach (self::$columns[$pt] as $key => $args) {
            $param = self::FILTER_PREFIX . $key;
            if (!$args['filter'] || !isset($_GET[$param]) || $_GET[$param] === '' || !self::meta_key($args)) {
                continue;
            }
            $meta_query[] = [
                'key'     => self::meta_key($args),
                'value'   => sanitize_text_field(wp_unslash($_GET[$param])),
                'compare' => '=',
            ];
        }

        // Tri : on garde les contenus sans valeur (NOT EXISTS) au lieu de les exclure.
        $orderby = $q->get('orderby');
        if (is_string($orderby) && isset(self::$columns[$pt][$orderby])) {
            $args = self::$columns[$pt][$orderby];
            $key  = self::meta_key($args);

            if ($args['sortable'] && $key) {
                $type = $args['sortable'] === 'numeric' ? 'DECIMAL(15,4)' : 'CHAR';

                $meta_query['mc_sort'] = [
                    'relation'     => 'OR',
                    'mc_sort_value' => ['key' => $key, 'compare' => 'EXISTS', 'type' => $type],
                    ['key' => $key, 'compare' => 'NOT EXISTS'],
                ];
                $q->set('orderby', ['mc_sort_value' => strtoupper($q->get('order') ?: 'ASC'), 'ID' => 'DESC']);
            }
        }

        if (count($meta_query) > 1 && !isset($meta_query['relation'])) {
            $meta_query['relation'] = 'AND';
        }

        if ($meta_query) {
            $q->set('meta_query', $meta_query);
        }
    }

    /* ─────────────────────────────── CSS ──────────────────────────────────── */

    public static function print_css(): void
    {
        $screen = get_current_screen();
        $pt     = $screen->post_type ?? '';

        if (!$pt || empty(self::$columns[$pt])) {
            return;
        }

        $css = '';
        foreach (self::$columns[$pt] as $key => $args) {
            if ($args['width']) {
                $css .= sprintf(
                    '.wp-list-table .column-%1$s{width:%2$s;}',
                    sanitize_html_class($key),
                    esc_html(preg_replace('/[^0-9a-z%.]/i', '', $args['width']))
                );
            }
        }

        if ($css) {
            echo "<style id=\"mc-admin-columns\">{$css}</style>"; // phpcs:ignore -- valeurs nettoyées
        }
    }
}

endif;
