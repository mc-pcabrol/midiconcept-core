<?php
/**
 * MidiConcept Core – Client Admin
 * Description: Admin WordPress simplifié et modernisé pour les clients : thème visuel, tableau de bord à raccourcis, menu épuré, branding.
 *
 * Chargé par le plugin MidiConcept Core.
 * Réglages habituels : Réglages → MidiConcept, dans l'admin (aucun fichier à déposer).
 * Réglages avancés : hook "midiconcept_core_init", qui prend le pas sur la page de réglages.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * UTILISATION (un seul appel par site, ex. dans mu-plugins/mc-site-config.php)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * MC_Client_Admin::init([
 *     // Rôles concernés par le thème, le menu épuré et le tableau de bord.
 *     // 'all' = tout le monde ; par défaut tout le monde sauf administrator.
 *     'roles' => ['editor', 'client'],
 *
 *     'theme' => [
 *         'primary'   => '#2F6FED',   // couleur principale (boutons, liens, menu actif)
 *         'menu_bg'   => '#111827',   // fond du menu latéral
 *         'menu_text' => '#CBD5E1',
 *         'bg'        => '#F4F6FA',   // fond des pages
 *         'radius'    => '10px',
 *         'font'      => '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
 *         'font_css'  => '',          // URL d'une feuille @font-face locale (pas de Google Fonts → RGPD)
 *         'menu_width' => '240px',    // largeur du menu latéral (écrans > 960 px)
 *         'adminbar'  => 'dark',      // 'dark' ou 'light' (barre du haut blanche)
 *     ],
 *
 *     'brand' => [
 *         'name'       => 'Midiconcept',
 *         'logo'       => content_url('uploads/logo-client.svg'),  // page de connexion
 *         'footer'     => 'Site réalisé par <a href="https://www.midiconcept.fr" target="_blank">Midiconcept</a> · Besoin d’aide ? 05 00 00 00 00',
 *     ],
 *
 *     'dashboard' => [
 *         'title'     => 'Bienvenue 👋',
 *         'intro'     => 'Que souhaitez-vous faire aujourd’hui ?',
 *         'shortcuts' => [
 *             ['label' => 'Ajouter une actualité', 'url' => 'post-new.php',                     'icon' => 'dashicons-megaphone', 'cap' => 'edit_posts'],
 *             ['label' => 'Gérer les biens',       'url' => 'edit.php?post_type=bien',          'icon' => 'dashicons-admin-home'],
 *             ['label' => 'Médiathèque',           'url' => 'upload.php',                       'icon' => 'dashicons-format-gallery'],
 *             ['label' => 'Voir le site',          'url' => home_url('/'), 'target' => '_blank', 'icon' => 'dashicons-external'],
 *         ],
 *         'help'      => 'Une question ? Appelez Pierre au 05 00 00 00 00 ou écrivez à contact@midiconcept.fr',
 *     ],
 *
 *     'menu' => [
 *         'hide'   => ['edit-comments.php', 'tools.php', 'themes.php', 'plugins.php', 'options-general.php'],
 *         'rename' => ['edit.php' => 'Actualités', 'upload.php' => 'Images & fichiers'],
 *         'order'  => ['index.php', 'edit.php?post_type=bien', 'edit.php', 'edit.php?post_type=page', 'upload.php'],
 *     ],
 *
 *     'adminbar_remove' => ['wp-logo', 'comments', 'new-content', 'updates'],
 *     'hide_notices'    => true,   // regroupe les notices de plugins dans la barre du haut
 *     'status_badges'   => true,   // colonne « État » (Publié, Brouillon, Programmé…), séparée de la date
 *     'row_icons'       => true,   // colonne « Actions » : icônes natives et liens d'extensions (Elementor, IndexNow…)
 * ]);
 *
 * Rappel : masquer un menu ne bloque pas l'accès à l'URL. Pour verrouiller,
 * retirer la capacité au rôle (PublishPress Capabilities).
 */

defined('ABSPATH') || exit;

if (!class_exists('MC_Client_Admin')) :

final class MC_Client_Admin
{
    private static array $cfg = [];

    private static bool $ready = false;

    private static bool $notices_opened = false;

    private static bool $notices_closed = false;

    /** @var array<int, string> Actions d'extensions déjà transformées en icônes, par contenu. */
    private static array $extra_actions = [];

    /* ───────────────────────────── Initialisation ─────────────────────────── */

    public static function is_ready(): bool
    {
        return self::$ready;
    }

    public static function init(array $config): void
    {
        self::$ready = true;
        self::$cfg = self::merge(self::defaults(), $config);

        // Branding (tout le monde).
        add_action('login_enqueue_scripts', [__CLASS__, 'login_styles']);
        add_filter('login_headerurl', static fn() => home_url('/'));
        add_filter('login_headertext', static fn() => self::$cfg['brand']['name'] ?: get_bloginfo('name'));
        add_filter('admin_footer_text', [__CLASS__, 'footer_text']);

        // « Créer / Modifier avec YOOtheme » : retiré pour tout le monde.
        add_filter('post_row_actions', [__CLASS__, 'hide_yootheme_actions'], PHP_INT_MAX, 2);
        add_filter('page_row_actions', [__CLASS__, 'hide_yootheme_actions'], PHP_INT_MAX, 2);

        // Éléments ciblés par rôle : on attend que l'utilisateur soit connu.
        add_action('admin_init', [__CLASS__, 'handle_view_as'], 1);
        add_action('admin_init', [__CLASS__, 'setup_for_user']);
        add_action('admin_bar_menu', [__CLASS__, 'view_as_menu'], 80);
        add_action('in_admin_header', [__CLASS__, 'view_as_banner']);
        add_action('admin_bar_menu', [__CLASS__, 'clean_admin_bar'], 999);
    }

    private static function defaults(): array
    {
        return [
            'roles' => null, // null = tous sauf administrator
            'theme' => [
                'enabled'   => true,
                'primary'   => '#2F6FED',
                'menu_bg'   => '#111827',
                'menu_text' => '#CBD5E1',
                'bg'        => '#F4F6FA',
                'text'      => '#1F2937',
                'radius'    => '10px',
                'font'      => '"Inter", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                'font_css'  => '',
                'menu_width' => '240px',
                'adminbar'  => 'dark',
            ],
            'brand' => [
                'name'   => '',
                'logo'   => '',
                'footer' => '',
            ],
            'dashboard' => [
                'enabled'   => true,
                'title'     => 'Bienvenue',
                'intro'     => 'Que souhaitez-vous faire aujourd’hui ?',
                'shortcuts' => [],
                'help'      => '',
            ],
            'menu' => [
                'hide'   => [],
                'rename' => [],
                'order'  => [],
            ],
            'adminbar_remove' => ['wp-logo', 'comments'],
            'hide_notices'    => false,
            'status_badges'   => true,
            'row_icons'       => true,
        ];
    }

    /** Fusion récursive des tableaux associatifs, les listes sont remplacées. */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $k => $v) {
            $is_list = is_array($v) && array_keys($v) === range(0, count($v) - 1);
            if (is_array($v) && $v && isset($base[$k]) && is_array($base[$k]) && !$is_list) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /** L'utilisateur courant est-il concerné par l'admin client ? */
    private static function targets_current_user(): bool
    {
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }

        $effective = self::effective_roles($user);
        $roles = self::$cfg['roles'];
        if ($roles === 'all') {
            return true;
        }
        if ($roles === null) {
            return !in_array('administrator', $effective, true);
        }
        return (bool) array_intersect((array) $roles, $effective);
    }

    /** Rôles utilisés pour l'aperçu. Les droits réels du compte ne changent pas. */
    private static function effective_roles(WP_User $user): array
    {
        if (self::is_real_admin($user)) {
            $view = get_user_meta($user->ID, 'mc_view_as_role', true);
            if (is_string($view) && $view !== '' && $view !== 'administrator' && get_role($view)) {
                return [$view];
            }
        }
        return (array) $user->roles;
    }

    private static function is_real_admin(WP_User $user): bool
    {
        return in_array('administrator', (array) $user->roles, true);
    }

    public static function setup_for_user(): void
    {
        if (!self::targets_current_user()) {
            return;
        }

        if (self::$cfg['theme']['enabled']) {
            add_filter('admin_body_class', static function ($c) {
                $c .= ' mc-admin ';
                if (self::$cfg['theme']['adminbar'] === 'light') {
                    $c .= ' mc-adminbar-light ';
                }
                return $c;
            });
            add_action('admin_enqueue_scripts', [__CLASS__, 'admin_styles']);
            add_action('admin_footer', [__CLASS__, 'search_placeholder']);
        }

        if (self::$cfg['row_icons']) {
            add_action('current_screen', [__CLASS__, 'pin_actions_column']);
            add_action('manage_posts_custom_column', [__CLASS__, 'actions_cell'], 10, 2);
            add_action('manage_pages_custom_column', [__CLASS__, 'actions_cell'], 10, 2);
            add_filter('post_row_actions', [__CLASS__, 'collect_row_actions'], PHP_INT_MAX, 2);
            add_filter('page_row_actions', [__CLASS__, 'collect_row_actions'], PHP_INT_MAX, 2);
            add_filter('admin_body_class', static fn($c) => $c . ' mc-row-icons ');
        }

        if (self::$cfg['status_badges']) {
            $add_status = static function (array $cols): array {
                $new = [];
                foreach ($cols as $key => $label) {
                    if ($key === 'date') {
                        $new['mc_status'] = 'État';
                    }
                    $new[$key] = $label;
                }
                if (!isset($new['mc_status'])) {
                    $new['mc_status'] = 'État';
                }
                return $new;
            };
            add_filter('manage_posts_columns', $add_status, 20);
            add_filter('manage_pages_columns', $add_status, 20);
            add_action('manage_posts_custom_column', [__CLASS__, 'status_cell'], 10, 2);
            add_action('manage_pages_custom_column', [__CLASS__, 'status_cell'], 10, 2);
            // WordPress ajoute un <br> après ce texte : on le vide, le CSS retire le saut de ligne.
            add_filter('post_date_column_status', static fn() => '', 10);
            add_filter('admin_body_class', static fn($c) => $c . ' mc-status-col ');
            add_filter('display_post_states', [__CLASS__, 'post_states'], 10, 2);
        }

        if (self::$cfg['dashboard']['enabled']) {
            add_action('wp_dashboard_setup', [__CLASS__, 'dashboard'], 999);
            remove_action('welcome_panel', 'wp_welcome_panel');
        }

        add_action('admin_menu', [__CLASS__, 'menu'], 9991);

        if (self::$cfg['menu']['order']) {
            add_filter('custom_menu_order', '__return_true');
            add_filter('menu_order', [__CLASS__, 'menu_order'], 999);
        }

        if (self::$cfg['hide_notices']) {
            add_action('admin_notices', [__CLASS__, 'notices_open'], PHP_INT_MIN + 1);
            add_action('all_admin_notices', [__CLASS__, 'notices_close'], PHP_INT_MAX);
            add_action('admin_bar_menu', [__CLASS__, 'notices_bar'], 50);
            add_action('admin_footer', [__CLASS__, 'notices_script']);
        }

        add_filter('update_footer', '__return_empty_string', 999);
    }

    /* ─────────────────────────────── Branding ─────────────────────────────── */

    public static function footer_text($text): string
    {
        $footer = self::$cfg['brand']['footer'];
        return $footer ? wp_kses_post($footer) : (string) $text;
    }

    public static function clean_admin_bar(WP_Admin_Bar $bar): void
    {
        // Admin et front : uniquement pour les rôles ciblés.
        if (!self::targets_current_user()) {
            return;
        }
        foreach ((array) self::$cfg['adminbar_remove'] as $node) {
            $bar->remove_node($node);
        }
    }

    /* ─────────────────────────────── Menu ─────────────────────────────────── */

    public static function menu(): void
    {
        global $menu;

        foreach ((array) self::$cfg['menu']['hide'] as $slug) {
            remove_menu_page($slug);
        }

        $rename = (array) self::$cfg['menu']['rename'];
        if ($rename && is_array($menu)) {
            foreach ($menu as $i => $item) {
                $slug = $item[2] ?? '';
                if (isset($rename[$slug])) {
                    // Conserve les pastilles de compteur éventuelles (<span class="awaiting-mod">…).
                    $bubble      = preg_match('/<span.*<\/span>/s', (string) $item[0], $m) ? ' ' . $m[0] : '';
                    $menu[$i][0] = esc_html($rename[$slug]) . $bubble;
                }
            }
        }
    }

    public static function menu_order(array $order): array
    {
        $wanted = array_values(array_filter((array) self::$cfg['menu']['order'], 'is_string'));
        if (!$wanted) {
            return $order;
        }

        // Les séparateurs qui précèdent une entrée la suivent quand on la déplace.
        $before  = [];
        $pending = [];
        foreach ($order as $slug) {
            if (strpos((string) $slug, 'separator') === 0) {
                $pending[] = $slug;
                continue;
            }
            $before[$slug] = $pending;
            $pending = [];
        }

        $placed = [];
        $out    = [];
        foreach ($wanted as $slug) {
            if (!in_array($slug, $order, true) || isset($placed[$slug])) {
                continue;
            }
            foreach ($before[$slug] ?? [] as $sep) {
                if (!isset($placed[$sep])) {
                    $out[] = $sep;
                    $placed[$sep] = true;
                }
            }
            $out[] = $slug;
            $placed[$slug] = true;
        }
        foreach ($order as $slug) {
            if (!isset($placed[$slug])) {
                $out[] = $slug;
                $placed[$slug] = true;
            }
        }
        return $out;
    }

    /* ─────────────────────────────── Aperçu par rôle ──────────────────────── */

    public static function handle_view_as(): void
    {
        if (!isset($_GET['mc_view_as'])) {
            return;
        }

        $user = wp_get_current_user();
        if (!$user instanceof WP_User || !self::is_real_admin($user)) {
            return;
        }

        $role = sanitize_key(wp_unslash($_GET['mc_view_as']));
        check_admin_referer('mc_view_as_' . $role);

        if ($role === 'reset') {
            delete_user_meta($user->ID, 'mc_view_as_role');
        } elseif ($role !== 'administrator' && get_role($role)) {
            update_user_meta($user->ID, 'mc_view_as_role', $role);
        }

        wp_safe_redirect(remove_query_arg(['mc_view_as', '_wpnonce']));
        exit;
    }

    public static function view_as_menu(WP_Admin_Bar $bar): void
    {
        $user = wp_get_current_user();
        if (!$user instanceof WP_User || !self::is_real_admin($user)) {
            return;
        }

        if (!function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }

        $current = (string) get_user_meta($user->ID, 'mc_view_as_role', true);
        $label   = 'Voir comme';
        if ($current !== '' && get_role($current)) {
            $label = 'Aperçu : ' . self::role_label($current);
        }

        $bar->add_node([
            'id'     => 'mc-view-as',
            'parent' => 'top-secondary',
            'title'  => esc_html($label),
            'href'   => '#',
        ]);

        if ($current !== '') {
            $bar->add_node([
                'id'     => 'mc-view-as-reset',
                'parent' => 'mc-view-as',
                'title'  => 'Revenir à l’administrateur',
                'href'   => self::view_as_url('reset'),
            ]);
        }

        foreach (get_editable_roles() as $slug => $role) {
            if ($slug === 'administrator') {
                continue;
            }
            $name = translate_user_role($role['name'] ?? $slug);
            if ($slug === $current) {
                $name .= ' ✓';
            }
            $bar->add_node([
                'id'     => 'mc-view-as-' . $slug,
                'parent' => 'mc-view-as',
                'title'  => esc_html($name),
                'href'   => self::view_as_url($slug),
            ]);
        }
    }

    public static function view_as_banner(): void
    {
        $user = wp_get_current_user();
        if (!$user instanceof WP_User || !self::is_real_admin($user)) {
            return;
        }

        $role = (string) get_user_meta($user->ID, 'mc_view_as_role', true);
        if ($role === '' || !get_role($role)) {
            return;
        }

        $back = esc_url(self::view_as_url('reset'));
        echo '<div class="notice notice-info" style="margin:12px 20px 0;"><p>Vous voyez l’admin comme <strong>'
            . esc_html(self::role_label($role))
            . '</strong>. Les menus et l’apparence suivent ce rôle, vos droits d’administrateur restent en place. <a href="'
            . $back
            . '">Revenir</a></p></div>';
    }

    private static function view_as_url(string $role): string
    {
        return wp_nonce_url(add_query_arg('mc_view_as', $role), 'mc_view_as_' . $role);
    }

    private static function role_label(string $slug): string
    {
        $roles = wp_roles()->roles;
        $name  = $roles[$slug]['name'] ?? $slug;
        return translate_user_role($name);
    }

    /* ─────────────────────────────── Notifications ────────────────────────── */

    public static function notices_open(): void
    {
        if (self::$notices_opened) {
            return;
        }
        self::$notices_opened = true;
        echo '<div id="mc-notices" class="mc-notices" hidden>';
    }

    public static function notices_close(): void
    {
        if (!self::$notices_opened || self::$notices_closed) {
            return;
        }
        self::$notices_closed = true;
        echo '</div>';
    }

    public static function notices_bar(WP_Admin_Bar $bar): void
    {
        $bar->add_node([
            'id'    => 'mc-notices-toggle',
            'title' => 'Notifications',
            'href'  => '#mc-notices',
        ]);
    }

    public static function notices_script(): void
    {
        echo <<<'HTML'
<style>
#mc-notices[hidden]{display:none !important;}
#mc-notices:not([hidden]){margin:8px 20px 0 2px;}
#wpadminbar .mc-notices-count{display:inline-block;min-width:16px;margin-left:6px;padding:0 6px;border-radius:8px;background:#d63638;color:#fff;font-size:11px;line-height:16px;text-align:center;}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.getElementById('mc-notices');
    var link = document.querySelector('#wp-admin-bar-mc-notices-toggle > a');
    if (!box || !link) return;
    var n = box.querySelectorAll('.notice, .updated, .error, .update-nag').length;
    var item = document.getElementById('wp-admin-bar-mc-notices-toggle');
    if (!n) {
        if (item) item.style.display = 'none';
        return;
    }
    var badge = document.createElement('span');
    badge.className = 'mc-notices-count';
    badge.textContent = String(n);
    link.appendChild(badge);
    link.addEventListener('click', function (e) {
        e.preventDefault();
        if (box.hasAttribute('hidden')) box.removeAttribute('hidden');
        else box.setAttribute('hidden', '');
    });
});
</script>
HTML;
    }

    /* ─────────────────────────────── Tableau de bord ──────────────────────── */

    public static function dashboard(): void
    {
        global $wp_meta_boxes;

        // On retire tous les widgets existants (WordPress et plugins).
        if (isset($wp_meta_boxes['dashboard'])) {
            $wp_meta_boxes['dashboard'] = [];
        }

        $d = self::$cfg['dashboard'];
        wp_add_dashboard_widget('mc_welcome', esc_html($d['title']), [__CLASS__, 'dashboard_widget']);
    }

    public static function dashboard_widget(): void
    {
        $d = self::$cfg['dashboard'];

        if ($d['intro']) {
            echo '<p class="mc-dash-intro">' . esc_html($d['intro']) . '</p>';
        }

        $items = '';
        foreach ((array) $d['shortcuts'] as $s) {
            if (!empty($s['cap']) && !current_user_can($s['cap'])) {
                continue;
            }
            $url    = self::shortcut_url((string) ($s['url'] ?? ''));
            $blank  = preg_match('#^https?://#', (string) ($s['url'] ?? '')) || (isset($s['url'][0]) && $s['url'][0] === '/');
            $target = (!empty($s['target']) || $blank) ? ' target="_blank" rel="noopener"' : '';
            $icon   = !empty($s['icon']) ? '<span class="dashicons ' . esc_attr($s['icon']) . '" aria-hidden="true"></span>' : '';

            $items .= sprintf(
                '<a class="mc-dash-tile" href="%s"%s>%s<span class="mc-dash-label">%s</span></a>',
                esc_url($url),
                $target,
                $icon,
                esc_html($s['label'])
            );
        }

        if ($items) {
            echo '<div class="mc-dash-grid">' . $items . '</div>'; // phpcs:ignore -- échappé ci-dessus
        }

        if ($d['help']) {
            echo '<p class="mc-dash-help"><span class="dashicons dashicons-sos" aria-hidden="true"></span> ' . wp_kses_post($d['help']) . '</p>';
        }
    }

    /* ─────────────────────────────── Listes ───────────────────────────────── */

    /** Colonne « État », à gauche de la date. */
    public static function status_cell(string $column, int $post_id): void
    {
        if ($column !== 'mc_status') {
            return;
        }
        $post = get_post($post_id);
        if ($post instanceof WP_Post) {
            echo self::status_badge_html($post); // phpcs:ignore -- échappé dans la méthode
        }
    }

    private static function status_badge_html(WP_Post $post): string
    {
        $map = [
            'publish' => ['Publié', 'publish'],
            'future'  => ['Programmé', 'future'],
            'draft'   => ['Brouillon', 'draft'],
            'pending' => ['En attente', 'pending'],
            'private' => ['Privé', 'private'],
            'trash'   => ['Corbeille', 'other'],
        ];

        if (isset($map[$post->post_status])) {
            [$label, $mod] = $map[$post->post_status];
        } else {
            $obj   = get_post_status_object($post->post_status);
            $label = $obj->label ?? $post->post_status;
            $mod   = 'other';
        }

        return sprintf(
            '<span class="mc-badge mc-badge--%s">%s</span>',
            esc_attr($mod),
            esc_html($label)
        );
    }

    /**
     * Place la colonne Actions après toutes les autres, y compris celles
     * ajoutées par Rank Math sur manage_{écran}_columns.
     */
    public static function pin_actions_column($screen): void
    {
        if (!$screen instanceof WP_Screen || $screen->base !== 'edit' || $screen->post_type === 'attachment') {
            return;
        }
        add_filter('manage_' . $screen->id . '_columns', [__CLASS__, 'actions_column_last'], PHP_INT_MAX);
    }

    public static function actions_column_last(array $cols): array
    {
        $label = $cols['mc_actions'] ?? 'Actions';
        unset($cols['mc_actions']);
        $cols['mc_actions'] = $label;
        return $cols;
    }

    /**
     * Reprend les liens d'extensions (Elementor, IndexNow, duplication…) et retire YOOtheme.
     * Les liens promus gardent leur adresse : ils réapparaissent en texte sur mobile.
     *
     * @param mixed $post
     */
    public static function collect_row_actions(array $actions, $post): array
    {
        if (!$post instanceof WP_Post || $post->post_status === 'trash') {
            return $actions;
        }

        $native = ['edit', 'inline hide-if-no-js', 'trash', 'view', 'preview', 'delete', 'untrash'];
        $extra  = [];
        $kept   = [];

        foreach ($actions as $key => $html) {
            $html = (string) $html;
            $key  = (string) $key;
            if (self::is_yootheme_action($key, $html)) {
                continue;
            }
            if (in_array($key, $native, true)) {
                $kept[$key] = $html;
                continue;
            }
            $icon = self::iconify_row_action($key, $html);
            if ($icon === '') {
                $kept[$key] = $html;
                continue;
            }
            $extra[$key] = $icon;
            $kept[$key]  = self::mark_promoted_action($html);
        }

        self::$extra_actions[$post->ID] = $extra;
        return $kept;
    }

    /**
     * Colonne « Actions » : icônes grisées. Les actions natives équivalentes sont masquées
     * sous le titre (CSS), de même que les liens d'extensions repris ici.
     * Dans la corbeille, on laisse les actions natives (Restaurer / Supprimer définitivement).
     */
    public static function actions_cell(string $column, int $post_id): void
    {
        if ($column !== 'mc_actions') {
            return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status === 'trash') {
            return;
        }

        $type  = get_post_type_object($post->post_type);
        $title = _draft_or_post_title($post);
        $btn   = static function (string $icon, string $label, string $html_open, string $html_close = '</a>'): string {
            return $html_open
                . '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>'
                . '<span class="screen-reader-text">' . esc_html($label) . '</span>'
                . $html_close;
        };

        $out = '';

        // Voir / Prévisualiser
        if ($type && $type->public && is_post_type_viewable($type)) {
            $published = in_array($post->post_status, ['publish', 'private'], true);
            $url       = $published ? get_permalink($post) : get_preview_post_link($post);
            if ($url && ($published || current_user_can('edit_post', $post_id))) {
                $label = $published ? 'Voir' : 'Prévisualiser';
                $out  .= $btn('dashicons-visibility', $label, sprintf(
                    '<a class="mc-act" href="%s" target="_blank" rel="noopener" title="%s">',
                    esc_url($url),
                    esc_attr($label . ' « ' . $title . ' »')
                ));
            }
        }

        if (current_user_can('edit_post', $post_id)) {
            // Modifier
            $out .= $btn('dashicons-edit', 'Modifier', sprintf(
                '<a class="mc-act" href="%s" title="%s">',
                esc_url(get_edit_post_link($post_id)),
                esc_attr('Modifier « ' . $title . ' »')
            ));

            // Modification rapide (même classe que le bouton natif : le JS de WordPress s'en charge)
            if (!wp_check_post_lock($post_id)) {
                $out .= $btn('dashicons-fullscreen-alt', 'Modification rapide', sprintf(
                    '<button type="button" class="mc-act mc-act--quick button-link editinline" aria-expanded="false" title="%s">',
                    esc_attr('Modification rapide de « ' . $title . ' »')
                ), '</button>');
            }
        }

        foreach (self::$extra_actions[$post_id] ?? [] as $html) {
            $out .= $html;
        }

        // Corbeille
        if (current_user_can('delete_post', $post_id) && EMPTY_TRASH_DAYS) {
            $out .= $btn('dashicons-trash', 'Mettre à la corbeille', sprintf(
                '<a class="mc-act mc-act--danger submitdelete" href="%s" title="%s">',
                esc_url(get_delete_post_link($post_id)),
                esc_attr('Mettre « ' . $title . ' » à la corbeille')
            ));
        }

        echo '<div class="mc-actions">' . $out . '</div>'; // phpcs:ignore -- échappé dans iconify ou ci-dessus
    }

    /** @param mixed $post */
    public static function hide_yootheme_actions(array $actions, $post): array
    {
        foreach ($actions as $key => $html) {
            if (self::is_yootheme_action((string) $key, (string) $html)) {
                unset($actions[$key]);
            }
        }
        return $actions;
    }

    private static function is_yootheme_action(string $key, string $html): bool
    {
        $blob = strtolower($key . ' ' . $html);
        return strpos($blob, 'yootheme') !== false;
    }

    /** @return array<int, array{0: string, 1: string[]}> */
    private static function row_action_icons(): array
    {
        return [
            ['svg:bolt', [
                'indexnow', 'instant indexing', 'index now', 'rank_math_instant',
            ]],
            ['svg:copy', [
                'mc_duplicate', 'duplicate', 'dupliquer', 'clone', 'cloner', 'copier', 'edit_as_new',
                'rewrite', 'réécrire', 'nouveau brouillon', 'new draft',
            ]],
            ['dashicons-layout', [
                'elementor', 'bricks', 'breakdance', 'oxygen', 'brizy', 'beaver', 'fl-builder',
                'wpbakery', 'js_composer', 'vc_edit', 'seedprod', 'cornerstone', 'zionbuilder',
                'thrive', 'et_pb', 'et-fb', 'divi builder', 'the divi', 'avada', 'fusion-builder',
                'uxbuilder', 'flatsome', 'live composer',
            ]],
            ['dashicons-search', [
                'rank-math', 'rank_math', 'yoast', 'wpseo', 'seopress', 'aioseo', 'slim-seo', 'slim_seo',
                'the_seo_framework', 'tsf',
            ]],
            ['dashicons-update', [
                'wp-rocket', 'wpr-purge', 'rocket_purge', 'litespeed', 'w3tc', 'purge_cache', 'purge-cache',
                'supercache', 'flying-press', 'nitropack', 'vider le cache', 'clear cache', 'purge this',
            ]],
            ['dashicons-translation', [
                'wpml', 'polylang', 'pll_', 'translate', 'traduire', 'trp_',
            ]],
            ['dashicons-download', ['export', 'télécharger', 'download']],
            ['dashicons-randomize', ['redirection', 'redirect']],
            ['dashicons-admin-comments', ['comment']],
            ['dashicons-external', ['nouvel onglet', 'new tab', 'frontend', 'front-end']],
            ['dashicons-visibility', ['preview', 'prévisual', 'voir', 'view']],
        ];
    }

    private static function icon_for_action(string $key, string $html): string
    {
        $blob = strtolower($key . ' ' . html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8') . ' ' . $html);
        foreach (self::row_action_icons() as $rule) {
            foreach ($rule[1] as $needle) {
                if ($needle !== '' && strpos($blob, $needle) !== false) {
                    return $rule[0];
                }
            }
        }
        return 'dashicons-admin-generic';
    }

    private static function action_label(string $key, string $html): string
    {
        $label = trim(wp_strip_all_tags($html));
        if ($label === '' && preg_match('/\balt=(["\'])(.*?)\1/i', $html, $alt)) {
            $label = html_entity_decode($alt[2], ENT_QUOTES, 'UTF-8');
        }
        if ($label === '' && preg_match('/\btitle=(["\'])(.*?)\1/i', $html, $title)) {
            $label = html_entity_decode($title[2], ENT_QUOTES, 'UTF-8');
        }
        return $label !== '' ? $label : $key;
    }

    private static function action_glyph(string $icon, string $label): string
    {
        if ($icon === 'svg:bolt') {
            $glyph = '<svg class="mc-act-svg" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M11.1 1.2 4.2 11h5.2l-1.5 7.8 7.6-10.6h-5.2l.8-7z"/></svg>';
        } elseif ($icon === 'svg:copy') {
            $glyph = '<svg class="mc-act-svg" viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M7 2.2h8.1c.7 0 1.2.6 1.2 1.2v8.1h-1.7V3.9H7V2.2zM4.2 5h8.2c.7 0 1.2.6 1.2 1.2v8.4c0 .7-.5 1.2-1.2 1.2H4.2c-.7 0-1.2-.5-1.2-1.2V6.2C3 5.6 3.5 5 4.2 5zm.3 1.7v7.6h7.6V6.7H4.5z"/></svg>';
        } else {
            $glyph = '<span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span>';
        }
        return $glyph . '<span class="screen-reader-text">' . esc_html($label) . '</span>';
    }

    /** Garde un drapeau ou une image fournie par l'extension, au format de la colonne. */
    private static function action_image_inner(string $html, string $label): string
    {
        if (!preg_match('/<img\b[^>]*>/i', $html, $m)) {
            return self::action_glyph('dashicons-admin-generic', $label);
        }
        return '<span class="mc-act-img">' . $m[0] . '</span><span class="screen-reader-text">' . esc_html($label) . '</span>';
    }

    /**
     * @param callable(string, string, string): string $rewrite attrs, label, inner
     */
    private static function rewrite_action_tag(string $html, callable $rewrite): string
    {
        $label = self::action_label('', $html);
        $out = preg_replace_callback(
            '/<(a|button)\b([^>]*)>.*?<\/\1>/is',
            static function (array $m) use ($rewrite, $label): string {
                return $rewrite($m[1], $m[2], $label);
            },
            $html,
            1,
            $count
        );
        return ($count && is_string($out)) ? $out : '';
    }

    private static function with_action_class(string $attrs, string $class, string $label): string
    {
        if (!preg_match('/\btitle=/i', $attrs)) {
            $attrs .= ' title="' . esc_attr($label) . '"';
        }
        if (preg_match('/\bclass=(["\'])(.*?)\1/i', $attrs)) {
            return (string) preg_replace('/\bclass=(["\'])(.*?)\1/i', 'class=$1' . $class . ' $2$1', $attrs, 1);
        }
        return $attrs . ' class="' . esc_attr($class) . '"';
    }

    /** Lien d'origine, réduit à une icône, pour la colonne. */
    private static function iconify_row_action(string $key, string $html): string
    {
        $label = self::action_label($key, $html);
        $plain = trim(wp_strip_all_tags($html));
        $keep_img = (bool) preg_match('/<img\b/i', $html) && strlen($plain) < 12;
        $inner = $keep_img
            ? self::action_image_inner($html, $label)
            : self::action_glyph(self::icon_for_action($key, $html), $label);

        return self::rewrite_action_tag($html, static function (string $tag, string $attrs) use ($inner, $label): string {
            $attrs = self::with_action_class($attrs, 'mc-act', $label);
            return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
        });
    }

    /** Marque le lien sous le titre pour le masquer sur grand écran, sans le retirer du mobile. */
    private static function mark_promoted_action(string $html): string
    {
        $marked = self::rewrite_action_tag($html, static function (string $tag, string $attrs, string $label) use ($html): string {
            $attrs = self::with_action_class($attrs, 'mc-row-promoted', $label);
            if (!preg_match('/<(a|button)\b[^>]*>(.*?)<\/\1>/is', $html, $inner)) {
                return '<' . $tag . $attrs . '></' . $tag . '>';
            }
            return '<' . $tag . $attrs . '>' . $inner[2] . '</' . $tag . '>';
        });
        return $marked !== '' ? $marked : $html;
    }

    /** Après le titre : on retire les statuts déjà affichés en pastille (« — Brouillon »…). */
    public static function post_states(array $states, $post): array
    {
        if (!function_exists('get_current_screen')) {
            return $states;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->base !== 'edit') {
            return $states;
        }
        unset($states['draft'], $states['pending'], $states['scheduled'], $states['private'], $states['protected']);
        return $states;
    }

    /** Ajoute un texte d'exemple aux champs de recherche des listes (« Rechercher une formation… »). */
    public static function search_placeholder(): void
    {
        echo "<script>document.querySelectorAll('.search-box input[type=search]').forEach(function(i){if(!i.placeholder){var b=i.parentNode.querySelector('input[type=submit]');if(b){i.placeholder=b.value+'…';}}});</script>";
    }

    /* ─────────────────────────────── Styles ───────────────────────────────── */

    private static function css_vars(): string
    {
        $t       = self::$cfg['theme'];
        $primary = self::hex($t['primary'], '#2F6FED');

        $vars = [
            '--mc-primary'       => $primary,
            '--mc-primary-dark'  => self::shade($primary, -0.15),
            '--mc-primary-soft'  => self::rgba($primary, 0.12),
            '--mc-focus'         => self::rgba($primary, 0.35),
            '--mc-menu-bg'       => self::hex($t['menu_bg'], '#111827'),
            '--mc-menu-bg-2'     => self::shade(self::hex($t['menu_bg'], '#111827'), 0.08),
            '--mc-menu-text'     => self::hex($t['menu_text'], '#CBD5E1'),
            '--mc-bg'            => self::hex($t['bg'], '#F4F6FA'),
            '--mc-text'          => self::hex($t['text'], '#1F2937'),
            '--mc-muted'         => '#6B7280',
            '--mc-border'        => '#E5E7EB',
            '--mc-surface'       => '#FFFFFF',
            '--mc-radius'        => preg_replace('/[^0-9a-z.%]/i', '', (string) $t['radius']) ?: '10px',
            '--mc-shadow'        => '0 1px 2px rgba(16,24,40,.05), 0 1px 3px rgba(16,24,40,.06)',
            '--mc-font'          => str_replace(['<', '>', '{', '}', ';'], '', (string) $t['font']),
            '--mc-menu-w'        => preg_replace('/[^0-9a-z.%]/i', '', (string) $t['menu_width']) ?: '240px',
        ];

        $out = '';
        foreach ($vars as $k => $v) {
            $out .= "{$k}:{$v};";
        }
        return ':root{' . $out . '}';
    }

    public static function admin_styles(): void
    {
        $font_css = self::$cfg['theme']['font_css'];
        if ($font_css) {
            wp_enqueue_style('mc-admin-font', esc_url_raw($font_css), [], null);
        }

        wp_register_style('mc-admin', false, [], MC_CORE_VERSION);
        wp_enqueue_style('mc-admin');
        wp_add_inline_style('mc-admin', self::css_vars() . self::admin_css());
    }

    public static function login_styles(): void
    {
        $font_css = self::$cfg['theme']['font_css'];
        if ($font_css) {
            wp_enqueue_style('mc-admin-font', esc_url_raw($font_css), [], null);
        }

        $logo = self::$cfg['brand']['logo'];
        $css  = self::css_vars() . self::login_css();
        if ($logo) {
            $css .= sprintf(
                '#login h1 a{background-image:url("%s");background-size:contain;background-position:center;width:100%%;height:90px;}',
                esc_url($logo)
            );
        }

        wp_register_style('mc-login', false, [], MC_CORE_VERSION);
        wp_enqueue_style('mc-login');
        wp_add_inline_style('mc-login', $css);
    }

    /**
     * Thème admin. Règles :
     * - uniquement du CSS sur les classes de base de WordPress ;
     * - tout est scopé sous body.mc-admin ;
     * - l'éditeur de blocs (.block-editor-page) est exclu, hors menu/barre d'admin.
     */
    private static function admin_css(): string
    {
        return <<<'CSS'
/* ── Base ─────────────────────────────────────────────── */
body.mc-admin{background:var(--mc-bg);color:var(--mc-text);font-family:var(--mc-font);}
body.mc-admin #wpwrap,body.mc-admin #wpcontent,body.mc-admin #wpbody-content{background:var(--mc-bg);}
body.mc-admin:not(.block-editor-page) #wpcontent{padding-left:28px;}
body.mc-admin:not(.block-editor-page) .wrap{margin-top:22px;margin-right:28px;}
body.mc-admin:not(.block-editor-page) .wrap h1,body.mc-admin:not(.block-editor-page) .wrap h1.wp-heading-inline{font-size:26px;font-weight:700;letter-spacing:-.01em;color:var(--mc-text);}
body.mc-admin:not(.block-editor-page) a{color:var(--mc-primary);}
body.mc-admin:not(.block-editor-page) a:hover{color:var(--mc-primary-dark);}
body.mc-admin #wpfooter{color:var(--mc-muted);}

/* ── Barre d'admin ────────────────────────────────────── */
body.mc-admin #wpadminbar{background:var(--mc-menu-bg);}
body.mc-admin #wpadminbar .ab-item,body.mc-admin #wpadminbar a.ab-item,body.mc-admin #wpadminbar .ab-label{color:var(--mc-menu-text);font-family:var(--mc-font);}
body.mc-admin #wpadminbar .ab-top-menu>li:hover>.ab-item,body.mc-admin #wpadminbar .ab-top-menu>li.hover>.ab-item{background:var(--mc-menu-bg-2);color:#fff;}
body.mc-admin #wpadminbar .ab-sub-wrapper{background:var(--mc-menu-bg-2);}

/* ── Menu latéral ─────────────────────────────────────── */
body.mc-admin #adminmenuback,body.mc-admin #adminmenuwrap,body.mc-admin #adminmenu{background:var(--mc-menu-bg);}
body.mc-admin #adminmenu{padding:10px 0;}
body.mc-admin #adminmenu a{color:var(--mc-menu-text);font-family:var(--mc-font);}
body.mc-admin #adminmenu>li.menu-top{margin:2px 10px;border-radius:calc(var(--mc-radius) - 2px);}
body.mc-admin.folded #adminmenu>li.menu-top{margin:2px 4px;}
body.mc-admin #adminmenu>li.menu-top>a{border-radius:calc(var(--mc-radius) - 2px);}
body.mc-admin #adminmenu div.wp-menu-image:before{color:var(--mc-menu-text);opacity:.85;}
body.mc-admin #adminmenu li.menu-top:hover>a,body.mc-admin #adminmenu li.opensub>a.menu-top,body.mc-admin #adminmenu li>a.menu-top:focus{background:var(--mc-menu-bg-2);color:#fff;}
body.mc-admin #adminmenu li.menu-top:hover div.wp-menu-image:before{color:#fff;opacity:1;}
body.mc-admin #adminmenu li.current a.menu-top,body.mc-admin #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu,body.mc-admin #adminmenu li.wp-has-current-submenu .wp-submenu .wp-submenu-head{background:var(--mc-primary);color:#fff;}
body.mc-admin #adminmenu li.current div.wp-menu-image:before,body.mc-admin #adminmenu li.wp-has-current-submenu div.wp-menu-image:before{color:#fff;opacity:1;}
body.mc-admin #adminmenu .wp-submenu,body.mc-admin #adminmenu .wp-has-current-submenu .wp-submenu,body.mc-admin #adminmenu .wp-has-current-submenu.opensub .wp-submenu,body.mc-admin #adminmenu a.wp-has-current-submenu:focus+.wp-submenu{background:var(--mc-menu-bg-2);}
body.mc-admin #adminmenu .wp-submenu a{color:var(--mc-menu-text);opacity:.85;}
body.mc-admin #adminmenu .wp-submenu a:hover,body.mc-admin #adminmenu .wp-submenu a:focus,body.mc-admin #adminmenu .wp-submenu li.current a{color:#fff;opacity:1;}
body.mc-admin #adminmenu li.wp-has-current-submenu a.wp-has-current-submenu:after,body.mc-admin #adminmenu li.current a.menu-top:after,body.mc-admin #adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub:hover:after{border-right-color:transparent;}
body.mc-admin #adminmenu .awaiting-mod,body.mc-admin #adminmenu .update-plugins{background:var(--mc-primary);}
body.mc-admin #collapse-button{color:var(--mc-menu-text);}

/* ── Boutons ──────────────────────────────────────────── */
body.mc-admin:not(.block-editor-page) .wp-core-ui .button,
body.mc-admin:not(.block-editor-page).wp-core-ui .button,
body.mc-admin:not(.block-editor-page) .page-title-action{border-radius:calc(var(--mc-radius) - 3px);border-color:var(--mc-border);background:var(--mc-surface);color:var(--mc-text);box-shadow:var(--mc-shadow);font-weight:500;transition:background .15s,border-color .15s,box-shadow .15s;}
body.mc-admin:not(.block-editor-page).wp-core-ui .button:hover,
body.mc-admin:not(.block-editor-page) .page-title-action:hover{background:#F9FAFB;border-color:#D1D5DB;color:var(--mc-text);}
body.mc-admin:not(.block-editor-page).wp-core-ui .button-primary,
body.mc-admin:not(.block-editor-page) .page-title-action{background:var(--mc-primary);border-color:var(--mc-primary);color:#fff;}
body.mc-admin:not(.block-editor-page).wp-core-ui .button-primary:hover,
body.mc-admin:not(.block-editor-page).wp-core-ui .button-primary:focus,
body.mc-admin:not(.block-editor-page) .page-title-action:hover{background:var(--mc-primary-dark);border-color:var(--mc-primary-dark);color:#fff;}
body.mc-admin:not(.block-editor-page) .page-title-action{padding:6px 14px;top:-2px;}
body.mc-admin:not(.block-editor-page).wp-core-ui .button:focus,
body.mc-admin:not(.block-editor-page) .page-title-action:focus{box-shadow:0 0 0 3px var(--mc-focus);outline:none;}

/* ── Champs de formulaire ─────────────────────────────── */
body.mc-admin:not(.block-editor-page) input[type=text],
body.mc-admin:not(.block-editor-page) input[type=search],
body.mc-admin:not(.block-editor-page) input[type=email],
body.mc-admin:not(.block-editor-page) input[type=url],
body.mc-admin:not(.block-editor-page) input[type=tel],
body.mc-admin:not(.block-editor-page) input[type=number],
body.mc-admin:not(.block-editor-page) input[type=password],
body.mc-admin:not(.block-editor-page) input[type=date],
body.mc-admin:not(.block-editor-page) select,
body.mc-admin:not(.block-editor-page) textarea{border-radius:calc(var(--mc-radius) - 4px);border-color:#D1D5DB;box-shadow:none;}
body.mc-admin:not(.block-editor-page) input:focus,
body.mc-admin:not(.block-editor-page) select:focus,
body.mc-admin:not(.block-editor-page) textarea:focus{border-color:var(--mc-primary);box-shadow:0 0 0 3px var(--mc-focus);outline:none;}

/* ── Cartes, boîtes, tableaux ─────────────────────────── */
body.mc-admin:not(.block-editor-page) .postbox,
body.mc-admin:not(.block-editor-page) .card,
body.mc-admin:not(.block-editor-page) .stuffbox{border:1px solid var(--mc-border);border-radius:var(--mc-radius);box-shadow:var(--mc-shadow);background:var(--mc-surface);}
body.mc-admin:not(.block-editor-page) .postbox .postbox-header{border-bottom-color:var(--mc-border);}
body.mc-admin:not(.block-editor-page) .postbox .hndle{font-weight:600;}
body.mc-admin:not(.block-editor-page) .wp-list-table{border:1px solid var(--mc-border);border-radius:var(--mc-radius);box-shadow:var(--mc-shadow);border-collapse:separate;border-spacing:0;overflow:hidden;}
body.mc-admin:not(.block-editor-page) .wp-list-table thead th,
body.mc-admin:not(.block-editor-page) .wp-list-table thead td,
body.mc-admin:not(.block-editor-page) .wp-list-table tfoot th,
body.mc-admin:not(.block-editor-page) .wp-list-table tfoot td{background:#F9FAFB;border-color:#C5CAD3;font-weight:600;color:var(--mc-muted);}
body.mc-admin:not(.block-editor-page) .wp-list-table td,
body.mc-admin:not(.block-editor-page) .wp-list-table th,
body.mc-admin:not(.block-editor-page) .wp-list-table .check-column{padding-top:14px;padding-bottom:14px;vertical-align:middle;border-bottom:1px solid #C5CAD3;}
body.mc-admin:not(.block-editor-page) .striped>tbody>:nth-child(odd){background:var(--mc-surface);}
body.mc-admin:not(.block-editor-page) .striped>tbody>:nth-child(even){background:#FBFCFD;}
body.mc-admin:not(.block-editor-page) .wp-list-table tbody tr:not(.inline-edit-row):hover{background:#F5F7FA;}
body.mc-admin:not(.block-editor-page) .wp-list-table .row-title{font-weight:600;color:var(--mc-text);}
body.mc-admin:not(.block-editor-page) .subsubsub a.current{color:var(--mc-primary);}
body.mc-admin:not(.block-editor-page) .tablenav .tablenav-pages a,
body.mc-admin:not(.block-editor-page) .tablenav-pages-navspan{border-radius:6px;}

/* ── Notices ──────────────────────────────────────────── */
body.mc-admin:not(.block-editor-page) .notice,
body.mc-admin:not(.block-editor-page) div.updated,
body.mc-admin:not(.block-editor-page) div.error{border-radius:calc(var(--mc-radius) - 2px);box-shadow:var(--mc-shadow);border-top:0;border-right:0;border-bottom:0;}
body.mc-admin:not(.block-editor-page) .notice-info{border-left-color:var(--mc-primary);}

/* ── Onglets, cases à cocher ──────────────────────────── */
body.mc-admin:not(.block-editor-page) .nav-tab{border-radius:8px 8px 0 0;}
body.mc-admin:not(.block-editor-page) .nav-tab-active{border-bottom-color:var(--mc-bg);background:var(--mc-bg);}
body.mc-admin:not(.block-editor-page) input[type=checkbox]:checked::before{filter:none;}
body.mc-admin:not(.block-editor-page) input[type=checkbox]:focus,
body.mc-admin:not(.block-editor-page) input[type=radio]:focus{border-color:var(--mc-primary);box-shadow:0 0 0 2px var(--mc-focus);}
body.mc-admin:not(.block-editor-page) input[type=radio]:checked::before{background-color:var(--mc-primary);}

/* ── Tableau de bord client ───────────────────────────── */
body.mc-admin.index-php #dashboard-widgets .postbox-container{width:100%;}
body.mc-admin.index-php #dashboard-widgets .empty-container{display:none;}
body.mc-admin.index-php #screen-meta-links{display:none;}
body.mc-admin #mc_welcome .inside{padding:6px 18px 18px;}
body.mc-admin #mc_welcome .postbox-header .hndle{font-size:18px;}
body.mc-admin .mc-dash-intro{font-size:15px;color:var(--mc-muted);margin:6px 0 18px;}
body.mc-admin .mc-dash-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;}
body.mc-admin .mc-dash-tile{display:flex;flex-direction:column;align-items:flex-start;gap:14px;padding:20px;border:1px solid var(--mc-border);border-radius:var(--mc-radius);background:var(--mc-surface);color:var(--mc-text);text-decoration:none;transition:transform .15s,box-shadow .15s,border-color .15s;}
body.mc-admin .mc-dash-tile:hover,body.mc-admin .mc-dash-tile:focus{transform:translateY(-2px);border-color:var(--mc-primary);box-shadow:0 8px 20px rgba(16,24,40,.08);color:var(--mc-text);}
body.mc-admin .mc-dash-tile:focus{outline:none;box-shadow:0 0 0 3px var(--mc-focus);}
body.mc-admin .mc-dash-tile .dashicons{width:42px;height:42px;font-size:22px;line-height:42px;text-align:center;border-radius:calc(var(--mc-radius) - 2px);background:var(--mc-primary-soft);color:var(--mc-primary);}
body.mc-admin .mc-dash-label{font-size:14px;font-weight:600;}
body.mc-admin .mc-dash-help{margin:20px 0 0;padding:14px 16px;border-radius:calc(var(--mc-radius) - 2px);background:var(--mc-bg);color:var(--mc-muted);}
body.mc-admin .mc-dash-help .dashicons{color:var(--mc-primary);vertical-align:middle;}

/* ── Menu latéral large (écrans > 960 px, menu déplié) ── */
@media screen and (min-width: 961px){
  body.mc-admin:not(.folded) #adminmenuback,
  body.mc-admin:not(.folded) #adminmenuwrap,
  body.mc-admin:not(.folded) #adminmenu{width:var(--mc-menu-w);}
  body.mc-admin:not(.folded) #wpcontent,
  body.mc-admin:not(.folded) #wpfooter{margin-left:var(--mc-menu-w);}
  body.mc-admin:not(.folded) .interface-interface-skeleton{left:var(--mc-menu-w);}
  body.mc-admin:not(.folded) .woocommerce-layout__header{width:calc(100% - var(--mc-menu-w));}
  body.mc-admin:not(.folded) #adminmenu .wp-not-current-submenu .wp-submenu{left:calc(var(--mc-menu-w) - 20px);min-width:200px;}
  body.mc-admin:not(.folded) #adminmenu .wp-has-current-submenu .wp-submenu{width:auto;}
}
body.mc-admin #adminmenuback{min-height:100%;}
body.mc-admin #adminmenu{padding:14px 0;}
body.mc-admin #adminmenu div.wp-menu-name{font-size:14px;line-height:1.35;padding:10px 10px 10px 42px;}
body.mc-admin #adminmenu div.wp-menu-image{width:42px;height:40px;}
body.mc-admin #adminmenu div.wp-menu-image:before{padding:10px 0;}
body.mc-admin #adminmenu div.wp-menu-image.svg{background-position:center 11px;}
body.mc-admin.folded #adminmenu div.wp-menu-image{width:28px;}
body.mc-admin #adminmenu li.wp-menu-separator{height:1px;margin:10px 18px;background:rgba(255,255,255,.08);}
body.mc-admin #adminmenu li.wp-menu-separator .separator{display:none;}
/* Groupe courant : élément principal + sous-menu dans un même bloc arrondi */
body.mc-admin #adminmenu li.wp-has-current-submenu{background:var(--mc-menu-bg-2);border-radius:calc(var(--mc-radius) - 2px);}
body.mc-admin:not(.folded) #adminmenu li.wp-has-current-submenu>a.menu-top{border-radius:calc(var(--mc-radius) - 2px) calc(var(--mc-radius) - 2px) 0 0;}
body.mc-admin #adminmenu .wp-has-current-submenu .wp-submenu{padding:6px 0 8px;border-radius:0 0 calc(var(--mc-radius) - 2px) calc(var(--mc-radius) - 2px);}
body.mc-admin:not(.folded) #adminmenu .wp-submenu a{padding:7px 12px 7px 42px;font-size:13px;line-height:1.4;}
body.mc-admin #adminmenu .wp-submenu li.current a{font-weight:600;box-shadow:inset 3px 0 0 var(--mc-primary);}
body.mc-admin #adminmenu .wp-submenu .wp-submenu-head{display:none;}
/* Sous-menus volants (au survol des autres entrées) */
body.mc-admin #adminmenu .wp-not-current-submenu .wp-submenu{border-radius:calc(var(--mc-radius) - 2px);box-shadow:0 12px 28px rgba(0,0,0,.28);padding:6px 0;}
body.mc-admin #adminmenu .wp-not-current-submenu .wp-submenu a{padding-left:16px;}
body.mc-admin #adminmenu .wp-not-current-submenu .wp-submenu .wp-submenu-head{display:block;padding:8px 16px;color:#fff;font-weight:600;}

/* ── Rouge réservé aux actions ────────────────────────── */
body.mc-admin:not(.block-editor-page) .wp-list-table tbody td:not(.column-title):not(.column-primary) a:not(.button){color:#374151;}
body.mc-admin:not(.block-editor-page) .wp-list-table tbody td:not(.column-title):not(.column-primary) a:not(.button):hover{color:var(--mc-primary);}
body.mc-admin:not(.block-editor-page) .wp-list-table th.sortable a,
body.mc-admin:not(.block-editor-page) .wp-list-table th.sorted a{color:var(--mc-muted);}
body.mc-admin:not(.block-editor-page) .wp-list-table th.sorted a,
body.mc-admin:not(.block-editor-page) .wp-list-table th.sortable a:hover{color:var(--mc-text);}
body.mc-admin:not(.block-editor-page) .wp-list-table .row-title:hover{color:var(--mc-primary);}
body.mc-admin:not(.block-editor-page) .row-actions,
body.mc-admin:not(.block-editor-page) .row-actions a,
body.mc-admin:not(.block-editor-page) .row-actions .button-link{color:var(--mc-muted);}
body.mc-admin:not(.block-editor-page) .row-actions a:hover,
body.mc-admin:not(.block-editor-page) .row-actions .button-link:hover{color:var(--mc-primary);}
body.mc-admin:not(.block-editor-page) .row-actions .trash a,
body.mc-admin:not(.block-editor-page) .row-actions .delete a{color:#B42318;}
body.mc-admin:not(.block-editor-page) .wp-list-table .column-date{color:var(--mc-muted);font-size:12.5px;line-height:1.7;}

/* Onglets Tous / Publiés / Brouillons en pastilles */
body.mc-admin:not(.block-editor-page) .subsubsub{margin:12px 0 10px;}
body.mc-admin:not(.block-editor-page) .subsubsub li{font-size:0;}
body.mc-admin:not(.block-editor-page) .subsubsub li a{font-size:13px;display:inline-block;padding:4px 12px;margin-right:6px;border-radius:999px;color:var(--mc-muted);background:transparent;}
body.mc-admin:not(.block-editor-page) .subsubsub li a:hover{color:var(--mc-text);background:rgba(17,24,39,.05);}
body.mc-admin:not(.block-editor-page) .subsubsub li a.current{color:var(--mc-primary);background:var(--mc-primary-soft);font-weight:600;}
body.mc-admin:not(.block-editor-page) .subsubsub li a .count{color:inherit;opacity:.7;font-weight:400;}

/* Modification rapide : un bandeau gris neutre, champs blancs, pas de cartes */
body.mc-admin:not(.block-editor-page) .inline-edit-row,
body.mc-admin:not(.block-editor-page) .inline-edit-row td{background:#F3F4F6;}
body.mc-admin:not(.block-editor-page) .inline-edit-row td.colspanchange{box-shadow:inset 0 1px 0 #E5E7EB, inset 0 -1px 0 #E5E7EB;padding-top:18px;padding-bottom:16px;}
body.mc-admin:not(.block-editor-page) .inline-edit-row fieldset.inline-edit-col-left,
body.mc-admin:not(.block-editor-page) .inline-edit-row fieldset.inline-edit-col-center,
body.mc-admin:not(.block-editor-page) .inline-edit-row fieldset.inline-edit-col-right{background:transparent;border:0;border-radius:0;box-shadow:none;}
body.mc-admin:not(.block-editor-page) .inline-edit-row .inline-edit-legend{color:#6B7280;font-size:11px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;margin-bottom:12px;}
body.mc-admin:not(.block-editor-page) .inline-edit-row label .title,
body.mc-admin:not(.block-editor-page) .inline-edit-row .inline-edit-categories-label{color:#6B7280;font-weight:500;}
body.mc-admin:not(.block-editor-page) .inline-edit-row input[type=text],
body.mc-admin:not(.block-editor-page) .inline-edit-row select{background:#fff;border-color:#D1D5DB;}
body.mc-admin:not(.block-editor-page) .inline-edit-row .cat-checklist{background:#fff;border:1px solid #E5E7EB;border-radius:8px;}

/* Tableau des extensions : remplace le bleu WordPress des lignes actives */
body.mc-admin:not(.block-editor-page) .plugins .active th,
body.mc-admin:not(.block-editor-page) .plugins .active td,
body.mc-admin:not(.block-editor-page) .plugins tr.active + tr.plugin-update-tr td,
body.mc-admin:not(.block-editor-page) .plugins tr.active + tr.plugin-update-tr .plugin-update,
body.mc-admin:not(.block-editor-page) .plugin-update-tr.active td{background-color:var(--mc-primary-soft);border-left-color:var(--mc-primary);}
body.mc-admin:not(.block-editor-page) .plugins .active th.check-column,
body.mc-admin:not(.block-editor-page) .plugins tr.active + tr.plugin-update-tr .plugin-update{border-left:4px solid var(--mc-primary);}
body.mc-admin:not(.block-editor-page) .plugins .inactive th,
body.mc-admin:not(.block-editor-page) .plugins .inactive td,
body.mc-admin:not(.block-editor-page) .plugins tr.inactive + tr.plugin-update-tr td{background-color:var(--mc-surface);}
body.mc-admin:not(.block-editor-page) .plugins .plugin-title,
body.mc-admin:not(.block-editor-page) .plugins .plugin-title strong,
body.mc-admin:not(.block-editor-page) .plugins .column-description,
body.mc-admin:not(.block-editor-page) .plugins .column-description a,
body.mc-admin:not(.block-editor-page) .plugins .row-actions a{color:var(--mc-text);}
body.mc-admin:not(.block-editor-page) .plugins .plugin-title a:hover,
body.mc-admin:not(.block-editor-page) .plugins .column-description a:hover,
body.mc-admin:not(.block-editor-page) .plugins .row-actions a:hover{color:var(--mc-primary);}
body.mc-admin:not(.block-editor-page) .plugins .row-actions .delete a{color:#B42318;}

/* ── Colonne Actions à icônes ─────────────────────────── */
/* Largeur automatique : la colonne se cale sur les icônes sans déborder
   sur la colonne suivante (Rank Math, etc.). */
body.mc-admin .wp-list-table.table-view-list.fixed{table-layout:auto;}
body.mc-admin .wp-list-table th.column-mc_actions,
body.mc-admin .wp-list-table td.column-mc_actions{width:1%;white-space:nowrap;text-align:right;vertical-align:middle;}
body.mc-admin .mc-actions{display:inline-flex;justify-content:flex-end;gap:2px;vertical-align:middle;}
body.mc-admin .mc-act{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;color:#9CA3AF;text-decoration:none;cursor:pointer;transition:background .15s,color .15s;}
body.mc-admin .mc-act .dashicons,
body.mc-admin .mc-act .mc-act-svg{font-size:18px;width:18px;height:18px;}
body.mc-admin .mc-act-img{display:inline-flex;width:18px;height:18px;}
body.mc-admin .mc-act-img img{width:18px;height:18px;object-fit:contain;}
body.mc-admin .mc-act:hover,body.mc-admin .mc-act:focus{background:var(--mc-primary-soft);color:var(--mc-primary);box-shadow:none;outline:none;}
body.mc-admin .mc-act:focus-visible{box-shadow:0 0 0 2px var(--mc-focus);}
body.mc-admin .mc-act--danger:hover,body.mc-admin .mc-act--danger:focus{background:#FEE4E2;color:#B42318;}
body.mc-admin .wp-list-table tbody tr:hover .mc-act{color:#6B7280;}
body.mc-admin .mc-act--quick,
body.mc-admin .wp-list-table tbody tr:hover .mc-act--quick,
body.mc-admin .mc-act--quick:hover,
body.mc-admin .mc-act--quick:focus{color:#111827;background:transparent;}
body.mc-admin .mc-act--quick:hover,body.mc-admin .mc-act--quick:focus{background:#F3F4F6;}
@media screen and (min-width: 783px){
  /* Les séparateurs « | » ne restent pas seuls une fois les liens repris en icônes. */
  body.mc-row-icons .wp-list-table tr:not(.status-trash) .row-actions{font-size:0;}
  body.mc-row-icons .wp-list-table tr:not(.status-trash) .row-actions > span{font-size:13px;}
  /* Les actions natives équivalentes disparaissent sous le titre (hors corbeille) */
  body.mc-row-icons .wp-list-table tr:not(.status-trash) .row-actions .edit,
  body.mc-row-icons .wp-list-table tr:not(.status-trash) .row-actions .inline,
  body.mc-row-icons .wp-list-table tr:not(.status-trash) .row-actions .trash,
  body.mc-row-icons .wp-list-table tr:not(.status-trash) .row-actions .view,
  body.mc-row-icons .wp-list-table tr:not(.status-trash) .row-actions span:has(> .mc-row-promoted){display:none;}
}
@media screen and (max-width: 782px){
  body.mc-admin .wp-list-table .column-mc_actions{display:none !important;}
}

/* ── Pastilles de statut ──────────────────────────────── */
body.mc-status-col .wp-list-table .column-date br{display:none;}
body.mc-admin .column-mc_status{width:118px;white-space:nowrap;}
body.mc-admin .mc-badge{display:inline-block;margin:0;padding:2px 9px;border-radius:999px;font-size:11.5px;font-weight:600;line-height:1.6;}
body.mc-admin .mc-badge--publish{background:#DCFCE7;color:#166534;}
body.mc-admin .mc-badge--future{background:#DBEAFE;color:#1E40AF;}
body.mc-admin .mc-badge--draft{background:#F3F4F6;color:#4B5563;}
body.mc-admin .mc-badge--pending{background:#FEF3C7;color:#92400E;}
body.mc-admin .mc-badge--private{background:#EDE9FE;color:#5B21B6;}
body.mc-admin .mc-badge--other{background:#F3F4F6;color:#374151;}
body.mc-admin .post-state{display:inline-block;padding:1px 8px;border-radius:999px;background:#F3F4F6;color:#4B5563;font-size:11.5px;font-weight:600;}

/* ── Barre d'outils des listes regroupée ──────────────── */
@media screen and (min-width: 783px){
  body.mc-admin:not(.block-editor-page) .tablenav.top{display:flex;flex-wrap:wrap;align-items:center;gap:8px;height:auto;margin:0 0 14px;padding:10px 12px;background:var(--mc-surface);border:1px solid var(--mc-border);border-radius:var(--mc-radius);box-shadow:var(--mc-shadow);}
  body.mc-admin:not(.block-editor-page) .tablenav.top .actions{float:none;padding:0;overflow:visible;display:flex;align-items:center;gap:6px;}
  body.mc-admin:not(.block-editor-page) .tablenav.top .tablenav-pages{float:none;margin:0 0 0 auto;}
  body.mc-admin:not(.block-editor-page) .tablenav.top br.clear{display:none;}
  body.mc-admin:not(.block-editor-page) .tablenav.bottom{margin-top:12px;}
  body.mc-admin:not(.block-editor-page) p.search-box{margin:10px 0 0;}
}
body.mc-admin:not(.block-editor-page) .search-box input[type=search]{min-width:240px;padding:5px 12px 5px 34px;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='none' stroke='%239CA3AF' stroke-width='2' stroke-linecap='round'%3E%3Ccircle cx='7' cy='7' r='5'/%3E%3Cpath d='m11 11 3.5 3.5'/%3E%3C/svg%3E") no-repeat 11px center;}
body.mc-admin:not(.block-editor-page) .tablenav-pages .pagination-links .button{min-width:32px;}
body.mc-admin:not(.block-editor-page) .tablenav-pages .current-page{border-radius:calc(var(--mc-radius) - 4px);}

/* ── Barre du haut claire (option 'adminbar' => 'light') ── */
body.mc-admin.mc-adminbar-light #wpadminbar{background:var(--mc-surface);border-bottom:1px solid var(--mc-border);}
body.mc-admin.mc-adminbar-light #wpadminbar .ab-item,
body.mc-admin.mc-adminbar-light #wpadminbar a.ab-item,
body.mc-admin.mc-adminbar-light #wpadminbar .ab-label,
body.mc-admin.mc-adminbar-light #wpadminbar>#wp-toolbar span.ab-label,
body.mc-admin.mc-adminbar-light #wpadminbar>#wp-toolbar span.noticon{color:var(--mc-text);}
body.mc-admin.mc-adminbar-light #wpadminbar .ab-icon,
body.mc-admin.mc-adminbar-light #wpadminbar .ab-icon:before,
body.mc-admin.mc-adminbar-light #wpadminbar .ab-item:before,
body.mc-admin.mc-adminbar-light #wpadminbar .ab-item:after{color:var(--mc-muted);}
body.mc-admin.mc-adminbar-light #wpadminbar .ab-top-menu>li:hover>.ab-item,
body.mc-admin.mc-adminbar-light #wpadminbar .ab-top-menu>li.hover>.ab-item,
body.mc-admin.mc-adminbar-light #wpadminbar .ab-top-menu>li>.ab-item:focus{background:var(--mc-bg);color:var(--mc-primary);}
body.mc-admin.mc-adminbar-light #wpadminbar .ab-top-menu>li:hover>.ab-item .ab-icon:before,
body.mc-admin.mc-adminbar-light #wpadminbar .ab-top-menu>li:hover>.ab-item:before{color:var(--mc-primary);}
body.mc-admin.mc-adminbar-light #wpadminbar .ab-sub-wrapper,
body.mc-admin.mc-adminbar-light #wpadminbar .ab-sub-wrapper .ab-submenu{background:var(--mc-surface);}
body.mc-admin.mc-adminbar-light #wpadminbar .ab-sub-wrapper{border:1px solid var(--mc-border);border-radius:0 0 8px 8px;box-shadow:0 10px 24px rgba(16,24,40,.1);}
body.mc-admin.mc-adminbar-light #wpadminbar .ab-submenu .ab-item{color:var(--mc-text);}
body.mc-admin.mc-adminbar-light #wpadminbar .ab-submenu .ab-item:hover{color:var(--mc-primary);}

@media (prefers-reduced-motion: reduce){
  body.mc-admin .mc-dash-tile{transition:none;}
  body.mc-admin .mc-dash-tile:hover{transform:none;}
}
CSS;
    }

    private static function login_css(): string
    {
        return <<<'CSS'
body.login{background:var(--mc-bg);font-family:var(--mc-font);color:var(--mc-text);}
.login #login{padding-top:8vh;}
.login form{border:1px solid var(--mc-border);border-radius:var(--mc-radius);box-shadow:0 10px 30px rgba(16,24,40,.08);padding:28px 26px;}
.login label{font-weight:500;}
.login input[type=text],.login input[type=password],.login input[type=email]{border-radius:calc(var(--mc-radius) - 4px);border-color:#D1D5DB;font-size:16px;padding:6px 12px;}
.login input[type=text]:focus,.login input[type=password]:focus,.login input[type=email]:focus{border-color:var(--mc-primary);box-shadow:0 0 0 3px var(--mc-focus);}
.wp-core-ui .button-primary{background:var(--mc-primary);border-color:var(--mc-primary);border-radius:calc(var(--mc-radius) - 4px);box-shadow:none;}
.wp-core-ui .button-primary:hover,.wp-core-ui .button-primary:focus{background:var(--mc-primary-dark);border-color:var(--mc-primary-dark);}
.login #nav a,.login #backtoblog a{color:var(--mc-muted);}
.login #nav a:hover,.login #backtoblog a:hover{color:var(--mc-primary);}
.login .message,.login .notice,.login .success{border-radius:calc(var(--mc-radius) - 2px);border-left-color:var(--mc-primary);}
.login .language-switcher{display:none;}
CSS;
    }

    /** Adresse admin, chemin du site (/contact/) ou URL absolue. */
    private static function shortcut_url(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (isset($url[0]) && $url[0] === '/') {
            return home_url($url);
        }
        return admin_url($url);
    }

    /* ─────────────────────────────── Couleurs ─────────────────────────────── */

    private static function hex(?string $color, string $fallback): string
    {
        $color = trim((string) $color);
        if (preg_match('/^#([0-9a-f]{3})$/i', $color, $m)) {
            $c = $m[1];
            return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
        }
        return preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : $fallback;
    }

    /** Éclaircit (>0) ou assombrit (<0) une couleur hexadécimale. */
    private static function shade(string $hex, float $amount): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $f = static function (int $c) use ($amount): int {
            $v = $amount < 0 ? $c * (1 + $amount) : $c + (255 - $c) * $amount;
            return (int) max(0, min(255, round($v)));
        };
        return sprintf('#%02x%02x%02x', $f($r), $f($g), $f($b));
    }

    private static function rgba(string $hex, float $alpha): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        return sprintf('rgba(%d,%d,%d,%s)', $r, $g, $b, $alpha);
    }
}

endif;
