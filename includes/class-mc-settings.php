<?php
/**
 * MidiConcept Core – Réglages
 * Description: Page Réglages → MidiConcept. La configuration est en base, donc une mise à jour du plugin ne l'écrase pas et aucun fichier n'est à déposer sur le serveur.
 */

defined('ABSPATH') || exit;

if (!class_exists('MC_Settings')) :

final class MC_Settings
{
    public const OPTION = 'mc_core_settings';

    /** Vrai si un mu-plugin ou le thème a déjà appelé MC_Client_Admin::init(). */
    public static bool $from_code = false;

    private const MENUS = [
        'edit-comments.php'    => 'Commentaires',
        'tools.php'            => 'Outils',
        'themes.php'           => 'Apparence',
        'plugins.php'          => 'Extensions',
        'options-general.php'  => 'Réglages',
        'users.php'            => 'Comptes',
        'edit.php'             => 'Articles',
        'edit.php?post_type=page' => 'Pages',
        'upload.php'           => 'Médiathèque',
    ];

    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_filter('plugin_action_links_' . plugin_basename(MC_CORE_FILE), [__CLASS__, 'action_link']);
    }

    /** Config prête pour MC_Client_Admin::init(), ou null si l'admin client est désactivé. */
    public static function client_config(): ?array
    {
        $o = self::get();
        if (empty($o['enabled'])) {
            return null;
        }

        if ($o['roles_mode'] === 'all') {
            $roles = 'all';
        } elseif ($o['roles_mode'] === 'custom' && $o['roles']) {
            $roles = $o['roles'];
        } else {
            $roles = null; // tous sauf administrator
        }

        return [
            'roles' => $roles,
            'theme' => [
                'primary'    => $o['primary'],
                'menu_bg'    => $o['menu_bg'],
                'menu_width' => ((int) $o['menu_width']) . 'px',
                'adminbar'   => $o['adminbar'] === 'light' ? 'light' : 'dark',
            ],
            'brand' => [
                'name'   => $o['brand_name'],
                'logo'   => $o['logo'],
                'footer' => $o['footer'],
            ],
            'dashboard' => [
                'title'     => $o['dash_title'],
                'intro'     => $o['dash_intro'],
                'shortcuts' => $o['shortcuts'],
                'help'      => $o['help'],
            ],
            'menu' => [
                'hide' => $o['hide_menus'],
            ],
            'hide_notices'  => !empty($o['hide_notices']),
            'status_badges' => !empty($o['status_badges']),
            'row_icons'     => !empty($o['row_icons']),
        ];
    }

    public static function menu(): void
    {
        add_options_page(
            'MidiConcept',
            'MidiConcept',
            'manage_options',
            'midiconcept-core',
            [__CLASS__, 'render']
        );
    }

    public static function action_link(array $links): array
    {
        $url = admin_url('options-general.php?page=midiconcept-core');
        array_unshift($links, '<a href="' . esc_url($url) . '">Réglages</a>');
        return $links;
    }

    public static function register(): void
    {
        register_setting('mc_core', self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default'           => self::defaults(),
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $o = self::get();
        $roles = self::roles();
        ?>
        <div class="wrap">
            <h1>MidiConcept</h1>

            <?php if (self::$from_code) : ?>
                <div class="notice notice-warning">
                    <p>Un fichier PHP de ce site appelle déjà <code>MC_Client_Admin::init()</code> (mu-plugin ou thème). Tant que c’est le cas, cette page n’est pas appliquée.</p>
                </div>
            <?php else : ?>
                <p>Ces réglages sont enregistrés dans le site. Une mise à jour de l’extension ne les modifie pas.</p>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php settings_fields('mc_core'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Admin client</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[enabled]" value="1" <?php checked($o['enabled']); ?>>
                                Activer le thème, le tableau de bord et le menu épuré
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Qui est concerné</th>
                        <td>
                            <p>
                                <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[roles_mode]" value="except_admin" <?php checked($o['roles_mode'], 'except_admin'); ?>> Tous les comptes sauf les administrateurs</label><br>
                                <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[roles_mode]" value="all" <?php checked($o['roles_mode'], 'all'); ?>> Tout le monde, y compris les administrateurs</label><br>
                                <label><input type="radio" name="<?php echo esc_attr(self::OPTION); ?>[roles_mode]" value="custom" <?php checked($o['roles_mode'], 'custom'); ?>> Seulement ces rôles</label>
                            </p>
                            <?php foreach ($roles as $key => $role) : ?>
                                <label style="display:inline-block;margin:0 16px 6px 0;">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[roles][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $o['roles'], true)); ?>>
                                    <?php echo esc_html(translate_user_role($role['name'])); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mc-brand-name">Nom affiché</label></th>
                        <td><input type="text" class="regular-text" id="mc-brand-name" name="<?php echo esc_attr(self::OPTION); ?>[brand_name]" value="<?php echo esc_attr($o['brand_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mc-logo">Logo de connexion</label></th>
                        <td>
                            <input type="url" class="regular-text" id="mc-logo" name="<?php echo esc_attr(self::OPTION); ?>[logo]" value="<?php echo esc_attr($o['logo']); ?>" placeholder="https://…">
                            <button type="button" class="button" id="mc-logo-pick">Médiathèque</button>
                            <p class="description">Image déjà présente dans la médiathèque. Laisser vide pour le logo WordPress.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Couleurs</th>
                        <td>
                            <label>Principale <input type="color" name="<?php echo esc_attr(self::OPTION); ?>[primary]" value="<?php echo esc_attr($o['primary']); ?>"></label>
                            &nbsp;&nbsp;
                            <label>Menu <input type="color" name="<?php echo esc_attr(self::OPTION); ?>[menu_bg]" value="<?php echo esc_attr($o['menu_bg']); ?>"></label>
                            <p>
                                <label>Largeur du menu
                                    <input type="number" min="160" max="360" step="10" class="small-text" name="<?php echo esc_attr(self::OPTION); ?>[menu_width]" value="<?php echo esc_attr((string) $o['menu_width']); ?>"> px
                                </label>
                            </p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[adminbar_light]" value="1" <?php checked($o['adminbar'], 'light'); ?>>
                                Barre du haut blanche
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Listes</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[status_badges]" value="1" <?php checked($o['status_badges']); ?>>
                                Pastilles de statut (Publié, Brouillon, Programmé…)
                            </label><br>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[row_icons]" value="1" <?php checked($o['row_icons']); ?>>
                                Colonne Actions à icônes (voir, modifier, corbeille)
                            </label>
                            <p class="description">La barre d’outils des listes est regroupée, et le champ de recherche affiche un texte d’exemple.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mc-footer">Pied de page</label></th>
                        <td>
                            <textarea class="large-text" rows="2" id="mc-footer" name="<?php echo esc_attr(self::OPTION); ?>[footer]"><?php echo esc_textarea($o['footer']); ?></textarea>
                            <p class="description">HTML simple autorisé (lien, gras). Visible en bas de l’admin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Tableau de bord</th>
                        <td>
                            <p><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[dash_title]" value="<?php echo esc_attr($o['dash_title']); ?>" placeholder="Titre"></p>
                            <p><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION); ?>[dash_intro]" value="<?php echo esc_attr($o['dash_intro']); ?>" placeholder="Phrase d’introduction"></p>
                            <p><textarea class="large-text" rows="2" name="<?php echo esc_attr(self::OPTION); ?>[help]" placeholder="Texte d’aide, en bas du tableau de bord"><?php echo esc_textarea($o['help']); ?></textarea></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mc-shortcuts">Raccourcis</label></th>
                        <td>
                            <textarea class="large-text code" rows="6" id="mc-shortcuts" name="<?php echo esc_attr(self::OPTION); ?>[shortcuts_text]"><?php echo esc_textarea(self::shortcuts_text($o['shortcuts'])); ?></textarea>
                            <p class="description">Une ligne : <code>Libellé | adresse</code>. Adresse d’admin (<code>upload.php</code>, <code>post-new.php?post_type=page</code>), chemin du site (<code>/contact/</code>) ou URL complète.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Menus masqués</th>
                        <td>
                            <?php foreach (self::MENUS as $slug => $label) : ?>
                                <label style="display:inline-block;margin:0 16px 6px 0;">
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[hide_menus][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $o['hide_menus'], true)); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                            <p>
                                <input type="text" class="large-text" name="<?php echo esc_attr(self::OPTION); ?>[hide_extra]" value="<?php echo esc_attr($o['hide_extra']); ?>" placeholder="Autres, séparés par des virgules : edit.php?post_type=bien">
                            </p>
                            <p class="description">Masquer un menu n’interdit pas l’adresse. Pour bloquer l’accès, retirer la capacité du rôle.</p>
                            <p>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[hide_notices]" value="1" <?php checked($o['hide_notices']); ?>>
                                    Masquer les notifications des autres extensions
                                </label>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Enregistrer'); ?>
            </form>
        </div>
        <?php
    }

    public static function assets(string $hook): void
    {
        if ($hook !== 'settings_page_midiconcept-core') {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', <<<'JS'
jQuery(function ($) {
    var frame;
    $('#mc-logo-pick').on('click', function (e) {
        e.preventDefault();
        if (frame) { frame.open(); return; }
        frame = wp.media({
            title: 'Logo de la page de connexion',
            button: { text: 'Utiliser ce fichier' },
            multiple: false
        });
        frame.on('select', function () {
            var file = frame.state().get('selection').first().toJSON();
            $('#mc-logo').val(file.url || '');
        });
        frame.open();
    });
});
JS
        );
    }

    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $base  = self::defaults();

        $mode = $input['roles_mode'] ?? 'except_admin';
        if (!in_array($mode, ['except_admin', 'all', 'custom'], true)) {
            $mode = 'except_admin';
        }

        $known_roles = array_keys(self::roles());
        $roles = array_values(array_intersect(
            array_map('sanitize_key', (array) ($input['roles'] ?? [])),
            $known_roles
        ));
        if ($mode === 'custom' && !$roles) {
            $mode = 'except_admin';
        }

        $known_menus = array_keys(self::MENUS);
        $hide = array_values(array_intersect((array) ($input['hide_menus'] ?? []), $known_menus));

        $extra_raw = sanitize_text_field((string) ($input['hide_extra'] ?? ''));
        foreach (preg_split('/\s*,\s*/', $extra_raw) ?: [] as $slug) {
            $slug = trim($slug);
            if ($slug !== '' && preg_match('/^[a-z0-9_?.=&-]+$/i', $slug) && !in_array($slug, $hide, true)) {
                $hide[] = $slug;
            }
        }
        $hide_known = array_values(array_intersect($hide, $known_menus));
        $hide_extra = array_values(array_diff($hide, $known_menus));

        return [
            'enabled'      => empty($input['enabled']) ? 0 : 1,
            'roles_mode'   => $mode,
            'roles'        => $roles,
            'brand_name'   => sanitize_text_field((string) ($input['brand_name'] ?? '')),
            'logo'         => esc_url_raw((string) ($input['logo'] ?? '')),
            'primary'      => self::hex((string) ($input['primary'] ?? ''), $base['primary']),
            'menu_bg'      => self::hex((string) ($input['menu_bg'] ?? ''), $base['menu_bg']),
            'menu_width'   => self::menu_width($input['menu_width'] ?? $base['menu_width']),
            'adminbar'     => empty($input['adminbar_light']) ? 'dark' : 'light',
            'footer'       => wp_kses_post((string) ($input['footer'] ?? '')),
            'dash_title'   => sanitize_text_field((string) ($input['dash_title'] ?? '')),
            'dash_intro'   => sanitize_text_field((string) ($input['dash_intro'] ?? '')),
            'help'         => wp_kses_post((string) ($input['help'] ?? '')),
            'shortcuts'    => self::parse_shortcuts((string) ($input['shortcuts_text'] ?? '')),
            'hide_menus'   => array_merge($hide_known, $hide_extra),
            'hide_extra'   => implode(', ', $hide_extra),
            'hide_notices'  => empty($input['hide_notices']) ? 0 : 1,
            'status_badges' => empty($input['status_badges']) ? 0 : 1,
            'row_icons'     => empty($input['row_icons']) ? 0 : 1,
        ];
    }

    private static function roles(): array
    {
        if (!function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        return get_editable_roles();
    }

    private static function get(): array
    {
        $saved = get_option(self::OPTION, null);
        if (!is_array($saved)) {
            return self::defaults();
        }

        $merged = self::defaults();
        foreach ($saved as $key => $value) {
            if (array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }
        return $merged;
    }

    private static function defaults(): array
    {
        return [
            'enabled'      => 1,
            'roles_mode'   => 'except_admin',
            'roles'        => [],
            'brand_name'   => '',
            'logo'         => '',
            'primary'      => '#2F6FED',
            'menu_bg'      => '#111827',
            'menu_width'   => 240,
            'adminbar'     => 'dark',
            'footer'       => 'Site réalisé par <a href="https://www.midiconcept.fr" target="_blank">Midiconcept</a>',
            'dash_title'   => 'Bienvenue',
            'dash_intro'   => 'Que souhaitez-vous faire aujourd’hui ?',
            'help'         => '',
            'shortcuts'    => [
                ['label' => 'Toutes les pages', 'url' => 'edit.php?post_type=page', 'icon' => 'dashicons-admin-page'],
                ['label' => 'Médiathèque', 'url' => 'upload.php', 'icon' => 'dashicons-format-gallery'],
                ['label' => 'Voir le site', 'url' => '/', 'icon' => 'dashicons-external'],
            ],
            'hide_menus'   => ['edit-comments.php', 'tools.php'],
            'hide_extra'   => '',
            'hide_notices'  => 1,
            'status_badges' => 1,
            'row_icons'     => 1,
        ];
    }

    private static function shortcuts_text(array $shortcuts): string
    {
        $lines = [];
        foreach ($shortcuts as $s) {
            if (!empty($s['label']) && !empty($s['url'])) {
                $lines[] = $s['label'] . ' | ' . $s['url'];
            }
        }
        return implode("\n", $lines);
    }

    private static function parse_shortcuts(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }
            [$label, $url] = array_map('trim', explode('|', $line, 2));
            $label = sanitize_text_field($label);
            $url   = self::sanitize_shortcut_url($url);
            if ($label === '' || $url === '') {
                continue;
            }
            $out[] = [
                'label' => $label,
                'url'   => $url,
                'icon'  => self::icon_for($url),
            ];
        }
        return $out;
    }

    private static function sanitize_shortcut_url(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return esc_url_raw($url);
        }
        if (isset($url[0]) && $url[0] === '/') {
            return '/' . ltrim(sanitize_text_field($url), '/');
        }
        if (preg_match('/^[a-z0-9_?.=&-]+$/i', $url)) {
            return $url;
        }
        return '';
    }

    private static function icon_for(string $url): string
    {
        if (strpos($url, 'upload.php') !== false) {
            return 'dashicons-format-gallery';
        }
        if (preg_match('#^https?://#i', $url) || (isset($url[0]) && $url[0] === '/')) {
            return 'dashicons-external';
        }
        if (strpos($url, 'post-new.php') !== false) {
            return 'dashicons-plus-alt2';
        }
        if (strpos($url, 'edit.php') !== false) {
            return 'dashicons-admin-page';
        }
        return 'dashicons-admin-links';
    }

    private static function menu_width($value): int
    {
        $width = (int) $value;
        if ($width < 160 || $width > 360) {
            return 240;
        }
        return $width;
    }

    private static function hex(string $color, string $fallback): string
    {
        $color = trim($color);
        return preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : $fallback;
    }
}

endif;
