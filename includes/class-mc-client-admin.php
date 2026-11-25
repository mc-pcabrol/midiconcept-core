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
 *     'hide_notices'    => true,   // masque les notices de plugins pour les rôles concernés
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

        // Éléments ciblés par rôle : on attend que l'utilisateur soit connu.
        add_action('admin_init', [__CLASS__, 'setup_for_user']);
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

        $roles = self::$cfg['roles'];
        if ($roles === 'all') {
            return true;
        }
        if ($roles === null) {
            return !in_array('administrator', (array) $user->roles, true);
        }
        return (bool) array_intersect((array) $roles, (array) $user->roles);
    }

    public static function setup_for_user(): void
    {
        if (!self::targets_current_user()) {
            return;
        }

        if (self::$cfg['theme']['enabled']) {
            add_filter('admin_body_class', static fn($c) => $c . ' mc-admin ');
            add_action('admin_enqueue_scripts', [__CLASS__, 'admin_styles']);
        }

        if (self::$cfg['dashboard']['enabled']) {
            add_action('wp_dashboard_setup', [__CLASS__, 'dashboard'], 999);
            remove_action('welcome_panel', 'wp_welcome_panel');
        }

        add_action('admin_menu', [__CLASS__, 'menu'], 999);

        if (self::$cfg['menu']['order']) {
            add_filter('custom_menu_order', '__return_true');
            add_filter('menu_order', [__CLASS__, 'menu_order'], 999);
        }

        if (self::$cfg['hide_notices']) {
            add_action('in_admin_header', static function () {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            }, 999);
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
        $wanted = array_values(array_intersect((array) self::$cfg['menu']['order'], $order));
        $rest   = array_values(array_diff($order, $wanted));
        return array_merge($wanted, $rest);
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
body.mc-admin #adminmenu>li.menu-top{margin:2px 10px;border-radius:calc(var(--mc-radius) - 2px);overflow:hidden;}
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
body.mc-admin:not(.block-editor-page) .wp-list-table tfoot td{background:#F9FAFB;border-color:var(--mc-border);font-weight:600;color:var(--mc-muted);}
body.mc-admin:not(.block-editor-page) .wp-list-table td,
body.mc-admin:not(.block-editor-page) .wp-list-table th{padding-top:12px;padding-bottom:12px;}
body.mc-admin:not(.block-editor-page) .striped>tbody>:nth-child(odd){background:var(--mc-surface);}
body.mc-admin:not(.block-editor-page) .striped>tbody>:nth-child(even){background:#FBFCFD;}
body.mc-admin:not(.block-editor-page) .wp-list-table tbody tr:hover{background:var(--mc-primary-soft);}
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
