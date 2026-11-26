<?php
/**
 * MidiConcept Core – Outils de contenu
 * Description: Duplication, publication des programmations manquées, remplacement de média,
 * colonne ID et filtres de taxonomies hiérarchiques.
 *
 * Réglages : Réglages → MidiConcept, onglets Listes et Contenu.
 * Ces outils suivent les cases cochées, même si un fichier PHP configure l'admin client.
 */

defined('ABSPATH') || exit;

if (!class_exists('MC_Tools')) :

final class MC_Tools
{
    private static bool $replacing = false;

    public static function boot(): void
    {
        $tools = MC_Settings::tools();

        if (!empty($tools['duplicate'])) {
            add_filter('post_row_actions', [__CLASS__, 'duplicate_link'], 10, 2);
            add_filter('page_row_actions', [__CLASS__, 'duplicate_link'], 10, 2);
            add_action('admin_action_mc_duplicate', [__CLASS__, 'duplicate']);
        }

        if (!empty($tools['missed_schedule'])) {
            add_action('init', [__CLASS__, 'publish_missed'], 20);
        }

        if (!empty($tools['media_replace'])) {
            add_action('post_edit_form_tag', [__CLASS__, 'replace_form_tag']);
            add_action('edit_form_after_title', [__CLASS__, 'replace_field']);
            add_action('edit_attachment', [__CLASS__, 'replace_media']);
            add_filter('redirect_post_location', [__CLASS__, 'replace_redirect'], 10, 2);
            add_action('admin_notices', [__CLASS__, 'replace_notice'], PHP_INT_MIN);
        }

        if (!empty($tools['show_id']) || !empty($tools['tax_filters'])) {
            add_action('admin_init', [__CLASS__, 'list_hooks']);
        }
    }

    /* ─────────────────────────────── Duplication ──────────────────────────── */

    /** @param mixed $post */
    public static function duplicate_link(array $actions, $post): array
    {
        if (!$post instanceof WP_Post || !self::can_duplicate($post)) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=mc_duplicate&post=' . $post->ID),
            'mc_duplicate_' . $post->ID
        );
        $actions['mc_duplicate'] = '<a href="' . esc_url($url) . '">Dupliquer</a>';

        return $actions;
    }

    public static function duplicate(): void
    {
        $id   = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        $post = $id ? get_post($id) : null;

        if (!$post instanceof WP_Post) {
            wp_die(esc_html('Contenu introuvable.'));
        }

        check_admin_referer('mc_duplicate_' . $post->ID);

        if (!self::can_duplicate($post)) {
            wp_die(esc_html('Vous ne pouvez pas dupliquer ce contenu.'));
        }

        $title = $post->post_title === '' ? '(copie)' : $post->post_title . ' (copie)';
        $new_id = wp_insert_post([
            'post_type'      => $post->post_type,
            'post_status'    => 'draft',
            'post_title'     => $title,
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_parent'    => $post->post_parent,
            'menu_order'     => $post->menu_order,
            'post_author'    => get_current_user_id(),
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
        ], true);

        if (is_wp_error($new_id) || !$new_id) {
            wp_die(esc_html('La copie n’a pas pu être créée.'));
        }

        $skip = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time'];
        foreach (get_post_meta($post->ID) as $key => $values) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            foreach ($values as $value) {
                add_post_meta($new_id, $key, $value);
            }
        }

        foreach (get_object_taxonomies($post->post_type) as $tax) {
            $terms = wp_get_object_terms($post->ID, $tax, ['fields' => 'ids']);
            if (!is_wp_error($terms) && $terms) {
                wp_set_object_terms($new_id, $terms, $tax);
            }
        }

        wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_id));
        exit;
    }

    private static function can_duplicate(WP_Post $post): bool
    {
        if (in_array($post->post_type, ['attachment', 'revision', 'nav_menu_item'], true)) {
            return false;
        }
        $type = get_post_type_object($post->post_type);
        if (!$type || empty($type->cap->create_posts)) {
            return false;
        }
        return current_user_can($type->cap->create_posts) && current_user_can('edit_post', $post->ID);
    }

    /* ───────────────────────────── Programmation ──────────────────────────── */

    public static function publish_missed(): void
    {
        if (wp_doing_ajax() || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
            return;
        }
        if (get_transient('mc_missed_schedule')) {
            return;
        }
        set_transient('mc_missed_schedule', 1, 15 * MINUTE_IN_SECONDS);

        $ids = get_posts([
            'post_type'        => 'any',
            'post_status'      => 'future',
            'posts_per_page'   => 20,
            'fields'           => 'ids',
            'suppress_filters' => true,
            'no_found_rows'    => true,
            'date_query'       => [[
                'column'    => 'post_date_gmt',
                'before'    => current_time('mysql', true),
                'inclusive' => true,
            ]],
        ]);

        foreach ($ids as $id) {
            $post = get_post((int) $id);
            if (!$post || $post->post_status !== 'future') {
                continue;
            }
            $when = strtotime($post->post_date_gmt . ' GMT');
            if ($when && $when <= time()) {
                wp_publish_post($post);
            }
        }
    }

    /* ───────────────────────────── Remplacement média ─────────────────────── */

    /** @param mixed $post */
    public static function replace_form_tag($post): void
    {
        if ($post instanceof WP_Post && $post->post_type === 'attachment') {
            echo ' enctype="multipart/form-data"';
        }
    }

    /** @param mixed $post */
    public static function replace_field($post): void
    {
        if (!$post instanceof WP_Post || $post->post_type !== 'attachment' || !current_user_can('edit_post', $post->ID)) {
            return;
        }

        $file = get_attached_file($post->ID);
        echo '<div class="mc-replace-media" style="margin:12px 0 16px;padding:12px 14px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;max-width:640px;">';
        echo '<p style="margin:0 0 8px;"><strong>Remplacer le fichier</strong></p>';
        if ($file) {
            echo '<p style="margin:0 0 8px;color:#646970;">Fichier actuel : ' . esc_html(wp_basename($file)) . '</p>';
        }
        echo '<input type="file" name="mc_replace_file" />';
        echo '<p class="description" style="margin:8px 0 0;">Enregistrez la fiche ensuite. Si l’extension ne change pas, l’adresse du fichier reste la même.</p>';
        echo '</div>';
    }

    /** @param mixed $post_id */
    public static function replace_media($post_id): void
    {
        if (self::$replacing) {
            return;
        }

        $post_id = (int) $post_id;
        $post    = $post_id > 0 ? get_post($post_id) : null;
        if (!$post instanceof WP_Post || $post->post_type !== 'attachment' || empty($_FILES['mc_replace_file']['name']) || !current_user_can('edit_post', $post_id)) {
            return;
        }

        $file = $_FILES['mc_replace_file'];
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            self::replace_error('Le fichier n’a pas pu être envoyé.');
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $checked = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
        if (empty($checked['ext']) || empty($checked['type'])) {
            self::replace_error('Ce type de fichier n’est pas autorisé.');
            return;
        }

        $old  = (string) get_attached_file($post_id);
        $meta = wp_get_attachment_metadata($post_id);
        if (!is_array($meta)) {
            $meta = [];
        }

        self::$replacing = true;
        $uploaded = wp_handle_upload($file, ['test_form' => false]);
        if (!empty($uploaded['error']) || empty($uploaded['file'])) {
            self::$replacing = false;
            self::replace_error(is_string($uploaded['error'] ?? null) ? $uploaded['error'] : 'Fichier refusé.');
            return;
        }

        $new_path = $uploaded['file'];
        $old_ext  = $old !== '' ? strtolower((string) pathinfo($old, PATHINFO_EXTENSION)) : '';
        $new_ext  = strtolower((string) pathinfo($new_path, PATHINFO_EXTENSION));
        $same     = $old !== '' && $old_ext !== '' && $old_ext === $new_ext;

        if ($same && !copy($new_path, $old)) {
            self::$replacing = false;
            @unlink($new_path);
            self::replace_error('Impossible d’écraser le fichier actuel.');
            return;
        }

        self::delete_generated_files($old, $meta, $post_id);

        if ($same) {
            if ($new_path !== $old) {
                @unlink($new_path);
            }
            $final = $old;
        } else {
            if ($old !== '' && is_file($old)) {
                @unlink($old);
            }
            update_attached_file($post_id, $new_path);
            $final = $new_path;
        }

        $mime = $checked['type'] ?: (string) ($uploaded['type'] ?? '');
        if ($mime !== '') {
            global $wpdb;
            $wpdb->update($wpdb->posts, ['post_mime_type' => $mime], ['ID' => $post_id]);
            clean_post_cache($post_id);
        }

        $new_meta = wp_generate_attachment_metadata($post_id, $final);
        if (is_array($new_meta)) {
            wp_update_attachment_metadata($post_id, $new_meta);
        }

        self::$replacing = false;
        $GLOBALS['mc_replace_ok'] = true;
    }

    /** @param array<string, mixed> $meta */
    private static function delete_generated_files(string $file, array $meta, int $post_id): void
    {
        if ($file === '') {
            return;
        }
        $dir = dirname($file);

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size) {
                if (!empty($size['file'])) {
                    $path = $dir . '/' . $size['file'];
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        if (!empty($meta['original_image'])) {
            $path = $dir . '/' . $meta['original_image'];
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $backups = get_post_meta($post_id, '_wp_attachment_backup_sizes', true);
        if (is_array($backups)) {
            foreach ($backups as $backup) {
                if (!empty($backup['file'])) {
                    $path = $dir . '/' . $backup['file'];
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            }
            delete_post_meta($post_id, '_wp_attachment_backup_sizes');
        }
    }

    private static function replace_error(string $message): void
    {
        set_transient('mc_replace_error_' . get_current_user_id(), $message, MINUTE_IN_SECONDS);
        $GLOBALS['mc_replace_error'] = true;
    }

    public static function replace_redirect(string $location, int $post_id): string
    {
        if (!empty($GLOBALS['mc_replace_ok'])) {
            return add_query_arg('mc_replace', 'ok', $location);
        }
        if (!empty($GLOBALS['mc_replace_error'])) {
            return add_query_arg('mc_replace', 'error', $location);
        }
        return $location;
    }

    public static function replace_notice(): void
    {
        $flag = isset($_GET['mc_replace']) ? sanitize_key(wp_unslash($_GET['mc_replace'])) : '';
        if ($flag === 'ok') {
            echo '<div class="notice notice-success is-dismissible"><p>Le fichier a été remplacé.</p></div>';
            return;
        }
        if ($flag !== 'error') {
            return;
        }
        $key = 'mc_replace_error_' . get_current_user_id();
        $msg = get_transient($key);
        delete_transient($key);
        if (!is_string($msg) || $msg === '') {
            $msg = 'Le remplacement a échoué.';
        }
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }

    /* ─────────────────────────────── Listes ───────────────────────────────── */

    public static function list_hooks(): void
    {
        $tools = MC_Settings::tools();
        $types = get_post_types(['show_ui' => true]);
        unset($types['attachment']);

        if (!empty($tools['show_id'])) {
            foreach ($types as $pt) {
                add_filter("manage_{$pt}_posts_columns", [__CLASS__, 'id_column']);
                add_action("manage_{$pt}_posts_custom_column", [__CLASS__, 'id_cell'], 10, 2);
                add_filter("manage_edit-{$pt}_sortable_columns", [__CLASS__, 'id_sortable']);
            }
            add_action('admin_head', [__CLASS__, 'id_css']);
        }

        if (!empty($tools['tax_filters'])) {
            add_action('restrict_manage_posts', [__CLASS__, 'tax_filters'], 20, 2);
            add_action('pre_get_posts', [__CLASS__, 'apply_tax_filters']);
        }
    }

    public static function id_column(array $cols): array
    {
        if (isset($cols['mc_id'])) {
            return $cols;
        }
        $new = [];
        foreach ($cols as $key => $label) {
            $new[$key] = $label;
            if ($key === 'cb') {
                $new['mc_id'] = 'ID';
            }
        }
        if (!isset($new['mc_id'])) {
            $new = ['mc_id' => 'ID'] + $cols;
        }
        return $new;
    }

    public static function id_cell(string $column, int $post_id): void
    {
        if ($column === 'mc_id') {
            echo (int) $post_id;
        }
    }

    public static function id_sortable(array $cols): array
    {
        $cols['mc_id'] = 'ID';
        return $cols;
    }

    public static function id_css(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit') {
            return;
        }
        echo '<style>.fixed .column-mc_id{width:4.5em;}</style>';
    }

    public static function tax_filters(string $post_type, string $which = 'top'): void
    {
        if ($which !== 'top') {
            return;
        }

        $skip = class_exists('MC_Admin_Columns') ? MC_Admin_Columns::filtered_taxonomies($post_type) : [];

        foreach (get_object_taxonomies($post_type, 'objects') as $tax) {
            if (!$tax instanceof WP_Taxonomy || !$tax->hierarchical || !$tax->show_ui || !$tax->query_var) {
                continue;
            }
            if ($tax->name === 'category' && $post_type === 'post') {
                continue;
            }
            if (in_array($tax->name, $skip, true)) {
                continue;
            }

            $selected = isset($_GET[$tax->query_var]) ? sanitize_title(wp_unslash($_GET[$tax->query_var])) : '';
            wp_dropdown_categories([
                'taxonomy'        => $tax->name,
                'name'            => $tax->query_var,
                'value_field'     => 'slug',
                'selected'        => $selected,
                'show_option_all' => $tax->labels->all_items ?: $tax->labels->name,
                'hide_empty'      => false,
                'hierarchical'    => true,
                'orderby'         => 'name',
            ]);
        }
    }

    public static function apply_tax_filters(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return;
        }

        $post_type = $query->get('post_type') ?: 'post';
        if (is_array($post_type)) {
            return;
        }

        $tax_query = $query->get('tax_query');
        if (!is_array($tax_query)) {
            $tax_query = [];
        }

        foreach (get_object_taxonomies($post_type, 'objects') as $tax) {
            if (!$tax instanceof WP_Taxonomy || !$tax->hierarchical || !$tax->query_var) {
                continue;
            }
            if ($tax->name === 'category' && $post_type === 'post') {
                continue;
            }
            if (!isset($_GET[$tax->query_var]) || $_GET[$tax->query_var] === '' || $_GET[$tax->query_var] === '0') {
                continue;
            }
            $slug = sanitize_title(wp_unslash($_GET[$tax->query_var]));
            if ($slug === '') {
                continue;
            }
            $tax_query[] = [
                'taxonomy' => $tax->name,
                'field'    => 'slug',
                'terms'    => $slug,
            ];
        }

        if ($tax_query) {
            $query->set('tax_query', $tax_query);
        }
    }
}

endif;
