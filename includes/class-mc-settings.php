<?php
/**
 * MidiConcept Core – Réglages
 * Description: Page Réglages → MidiConcept, en onglets. La configuration est en base : une mise à jour du plugin ne l'écrase pas.
 */

defined('ABSPATH') || exit;

if (!class_exists('MC_Settings')) :

final class MC_Settings
{
    public const OPTION = 'mc_core_settings';

    /** Vrai si un mu-plugin ou le thème a déjà appelé MC_Client_Admin::init(). */
    public static bool $from_code = false;

    /** @var array<int, mixed> Menu admin avant masquage et renommage. */
    private static array $menu_snapshot = [];

    private const MENUS = [
        'edit-comments.php'       => 'Commentaires',
        'tools.php'               => 'Outils',
        'themes.php'              => 'Apparence',
        'plugins.php'             => 'Extensions',
        'options-general.php'     => 'Réglages',
        'users.php'               => 'Comptes',
        'edit.php'                => 'Articles',
        'edit.php?post_type=page' => 'Pages',
        'upload.php'              => 'Médiathèque',
    ];

    public static function boot(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_menu', [__CLASS__, 'capture_menu'], 9990);
        add_action('admin_init', [__CLASS__, 'register']);
        add_filter('plugin_action_links_' . plugin_basename(MC_CORE_FILE), [__CLASS__, 'action_link']);
        add_filter('wp_redirect', [__CLASS__, 'keep_tab']);
        add_action('admin_head', [__CLASS__, 'print_admin_css'], 99);
    }

    /** @return array<string, string> */
    public static function tabs(): array
    {
        return [
            'apparence' => 'Apparence',
            'identite'  => 'Identité',
            'dashboard' => 'Tableau de bord',
            'menu'      => 'Menu',
            'listes'    => 'Listes',
            'contenu'   => 'Contenu',
            'colonnes'  => 'Colonnes',
        ];
    }

    /** Outils de contenu et de listes, indépendants de l'admin client. */
    public static function tools(): array
    {
        $o = self::get();
        return [
            'duplicate'       => !empty($o['duplicate']),
            'missed_schedule' => !empty($o['missed_schedule']),
            'media_replace'   => !empty($o['media_replace']),
            'show_id'         => !empty($o['show_id']),
            'tax_filters'     => !empty($o['tax_filters']),
        ];
    }

    public static function capture_menu(): void
    {
        global $menu;
        self::$menu_snapshot = is_array($menu) ? $menu : [];
    }

    /** @param array<string, mixed> $o @return array<int, array{slug: string, title: string}> */
    private static function ordered_menu_items(array $o): array
    {
        $rows = [];
        foreach (self::$menu_snapshot as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug  = isset($item[2]) ? (string) $item[2] : '';
            $title = trim(wp_strip_all_tags(wp_specialchars_decode((string) ($item[0] ?? ''))));
            if ($slug === '' || $title === '' || strpos($slug, 'separator') === 0) {
                continue;
            }
            $rows[$slug] = $title;
        }

        $sorted = [];
        $order  = is_array($o['menu_order'] ?? null) ? $o['menu_order'] : [];
        foreach ($order as $slug) {
            $slug = (string) $slug;
            if (isset($rows[$slug])) {
                $sorted[] = ['slug' => $slug, 'title' => $rows[$slug]];
                unset($rows[$slug]);
            }
        }
        foreach ($rows as $slug => $title) {
            $sorted[] = ['slug' => $slug, 'title' => $title];
        }
        return $sorted;
    }

    private static function menu_slug(string $slug): string
    {
        $slug = trim(wp_unslash($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9_?.=&\/-]+$/i', $slug)) {
            return '';
        }
        return $slug;
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
            $roles = null;
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
                'hide'   => $o['hide_menus'],
                'rename' => is_array($o['menu_rename']) ? $o['menu_rename'] : [],
                'order'  => is_array($o['menu_order']) ? $o['menu_order'] : [],
            ],
            'hide_notices'  => !empty($o['hide_notices']),
            'status_badges' => !empty($o['status_badges']),
            'row_icons'     => !empty($o['row_icons']),
        ];
    }

    /** Applique les colonnes déclarées dans l'onglet Colonnes. */
    public static function apply_columns(): void
    {
        $o = self::get();

        $grouped = [];
        foreach ($o['columns'] as $col) {
            $args = ['label' => $col['label']];
            if ($col['source'] === 'thumbnail') {
                $args['thumbnail'] = true;
            } elseif ($col['source'] === 'taxonomy') {
                $args['taxonomy'] = $col['field'];
                if (!empty($col['filter'])) {
                    $args['filter'] = true;
                }
            } else {
                $args[$col['source']] = $col['field'];
                if ($col['format'] !== '') {
                    $args['format'] = $col['format'];
                }
                if ($col['sort'] !== '') {
                    $args['sortable'] = $col['sort'];
                }
                if (!empty($col['filter'])) {
                    $args['filter'] = true;
                }
            }
            if ($col['width'] !== '') {
                $args['width'] = $col['width'];
            }
            if ($col['after'] !== '') {
                $args['after'] = $col['after'];
            }
            $grouped[$col['post_type']][$col['key']] = $args;
        }

        foreach ($grouped as $post_type => $columns) {
            MC_Admin_Columns::add($post_type, $columns);
        }

        foreach ($o['columns_hide'] as $row) {
            MC_Admin_Columns::remove($row['post_type'], $row['keys']);
        }
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

    public static function keep_tab(string $location): string
    {
        if (empty($_POST['option_page']) || $_POST['option_page'] !== 'mc_core') {
            return $location;
        }
        $tab = sanitize_key((string) ($_POST['mc_tab'] ?? ''));
        if ($tab !== '' && isset(self::tabs()[$tab])) {
            $location = add_query_arg('tab', $tab, $location);
        }
        return $location;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $o   = self::get();
        $tab = sanitize_key((string) ($_GET['tab'] ?? 'apparence'));
        if (!isset(self::tabs()[$tab])) {
            $tab = 'apparence';
        }
        $opt = self::OPTION;
        ?>
        <div class="wrap mc-settings">
            <h1>MidiConcept</h1>

            <?php if (self::$from_code) : ?>
                <div class="notice notice-warning">
                    <p>Un fichier PHP configure déjà l’admin client (apparence, identité, tableau de bord, menu, pastilles). Les onglets Colonnes et Contenu restent actifs, ainsi que l’identifiant, les filtres de taxonomie et le CSS additionnel.</p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper" aria-label="Réglages MidiConcept">
                <?php foreach (self::tabs() as $id => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $id)); ?>" class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>" data-tab="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <form action="options.php" method="post">
                <?php settings_fields('mc_core'); ?>
                <input type="hidden" name="mc_tab" id="mc-tab" value="<?php echo esc_attr($tab); ?>">

                <div class="mc-panel<?php echo $tab === 'apparence' ? ' is-active' : ''; ?>" data-tab="apparence">
                    <section class="mc-card">
                        <h2>Activation</h2>
                        <p class="mc-lead">Qui voit l’admin modernisé. Les administrateurs gardent l’admin WordPress classique, sauf si vous les incluez. Dans la barre du haut, « Voir comme » affiche l’admin tel qu’un rôle le voit, sans retirer vos droits.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Admin client</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[enabled]" value="1" <?php checked($o['enabled']); ?>>
                                        Activer le thème, le tableau de bord et le menu épuré
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Comptes concernés</th>
                                <td>
                                    <label class="mc-choice"><input type="radio" name="<?php echo esc_attr($opt); ?>[roles_mode]" value="except_admin" <?php checked($o['roles_mode'], 'except_admin'); ?>> Tous les comptes sauf les administrateurs</label>
                                    <label class="mc-choice"><input type="radio" name="<?php echo esc_attr($opt); ?>[roles_mode]" value="all" <?php checked($o['roles_mode'], 'all'); ?>> Tout le monde, y compris les administrateurs</label>
                                    <label class="mc-choice"><input type="radio" name="<?php echo esc_attr($opt); ?>[roles_mode]" value="custom" <?php checked($o['roles_mode'], 'custom'); ?>> Seulement ces rôles</label>
                                    <div class="mc-roles">
                                        <?php foreach (self::roles() as $key => $role) : ?>
                                            <label>
                                                <input type="checkbox" name="<?php echo esc_attr($opt); ?>[roles][]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $o['roles'], true)); ?>>
                                                <?php echo esc_html(translate_user_role($role['name'])); ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </section>

                    <section class="mc-card">
                        <h2>Couleurs et menu</h2>
                        <p class="mc-lead">La couleur principale sert aux boutons, au menu actif et aux liens.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Couleur principale</th>
                                <td><input type="color" name="<?php echo esc_attr($opt); ?>[primary]" value="<?php echo esc_attr($o['primary']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row">Fond du menu</th>
                                <td><input type="color" name="<?php echo esc_attr($opt); ?>[menu_bg]" value="<?php echo esc_attr($o['menu_bg']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mc-menu-width">Largeur du menu</label></th>
                                <td>
                                    <input type="number" min="160" max="360" step="10" class="small-text" id="mc-menu-width" name="<?php echo esc_attr($opt); ?>[menu_width]" value="<?php echo esc_attr((string) $o['menu_width']); ?>"> px
                                    <p class="description">Entre 160 et 360. 240 par défaut, sur les écrans larges.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Barre du haut</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[adminbar_light]" value="1" <?php checked($o['adminbar'], 'light'); ?>>
                                        Barre blanche à la place de la barre sombre
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </section>

                    <section class="mc-card">
                        <h2>CSS additionnel</h2>
                        <p class="mc-lead">Collé sur toutes les pages de l’admin, après les feuilles des extensions. Il s’applique à tout le monde, y compris les administrateurs. La surcouche est commune ; le champ en dessous ne concerne que ce site et passe en dernier.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Surcouche</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[overlay]" value="1" <?php checked($o['overlay']); ?>>
                                        Charger la surcouche par défaut MidiConcept
                                    </label>
                                    <p class="description">Fichier <code>assets/admin-overlay.css</code> du plugin. Le modifier dans le dépôt, puis publier une version, met à jour tous les sites.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mc-admin-css">Ce site</label></th>
                                <td>
                                    <textarea class="large-text code" rows="10" id="mc-admin-css" name="<?php echo esc_attr($opt); ?>[admin_css]" placeholder="/* règles propres à ce site */"><?php echo esc_textarea((string) $o['admin_css']); ?></textarea>
                                    <p class="description">CSS seul, sans balise <code>&lt;style&gt;</code>.</p>
                                </td>
                            </tr>
                        </table>
                    </section>
                </div>

                <div class="mc-panel<?php echo $tab === 'identite' ? ' is-active' : ''; ?>" data-tab="identite">
                    <section class="mc-card">
                        <h2>Marque</h2>
                        <p class="mc-lead">Ce que le client voit sur la page de connexion et en bas de l’admin.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="mc-brand-name">Nom affiché</label></th>
                                <td>
                                    <input type="text" class="regular-text" id="mc-brand-name" name="<?php echo esc_attr($opt); ?>[brand_name]" value="<?php echo esc_attr($o['brand_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                                    <p class="description">Laisser vide pour utiliser le nom du site.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mc-logo">Logo de connexion</label></th>
                                <td>
                                    <input type="url" class="regular-text" id="mc-logo" name="<?php echo esc_attr($opt); ?>[logo]" value="<?php echo esc_attr($o['logo']); ?>" placeholder="https://…">
                                    <button type="button" class="button" id="mc-logo-pick">Médiathèque</button>
                                    <p class="description">Image déjà présente dans la médiathèque. Vide : le logo WordPress.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mc-footer">Pied de page</label></th>
                                <td>
                                    <textarea class="large-text" rows="3" id="mc-footer" name="<?php echo esc_attr($opt); ?>[footer]"><?php echo esc_textarea($o['footer']); ?></textarea>
                                    <p class="description">HTML simple autorisé (lien, gras). Visible en bas de chaque écran.</p>
                                </td>
                            </tr>
                        </table>
                    </section>
                </div>

                <div class="mc-panel<?php echo $tab === 'dashboard' ? ' is-active' : ''; ?>" data-tab="dashboard">
                    <section class="mc-card">
                        <h2>Accueil</h2>
                        <p class="mc-lead">Le tableau de bord des comptes concernés remplace les widgets WordPress.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="mc-dash-title">Titre</label></th>
                                <td><input type="text" class="regular-text" id="mc-dash-title" name="<?php echo esc_attr($opt); ?>[dash_title]" value="<?php echo esc_attr($o['dash_title']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mc-dash-intro">Introduction</label></th>
                                <td><input type="text" class="regular-text" id="mc-dash-intro" name="<?php echo esc_attr($opt); ?>[dash_intro]" value="<?php echo esc_attr($o['dash_intro']); ?>"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="mc-dash-help">Aide</label></th>
                                <td>
                                    <textarea class="large-text" rows="3" id="mc-dash-help" name="<?php echo esc_attr($opt); ?>[help]"><?php echo esc_textarea($o['help']); ?></textarea>
                                    <p class="description">Phrase en bas du tableau de bord. HTML simple autorisé.</p>
                                </td>
                            </tr>
                        </table>
                    </section>
                    <section class="mc-card">
                        <h2>Raccourcis</h2>
                        <p class="mc-lead">Une ligne par tuile : <code>Libellé | adresse</code>.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="mc-shortcuts">Tuiles</label></th>
                                <td>
                                    <textarea class="large-text code" rows="8" id="mc-shortcuts" name="<?php echo esc_attr($opt); ?>[shortcuts_text]"><?php echo esc_textarea(self::shortcuts_text($o['shortcuts'])); ?></textarea>
                                    <p class="description">Adresse d’admin (<code>upload.php</code>, <code>post-new.php?post_type=page</code>), chemin du site (<code>/contact/</code>) ou URL complète.</p>
                                </td>
                            </tr>
                        </table>
                    </section>
                </div>

                <div class="mc-panel<?php echo $tab === 'menu' ? ' is-active' : ''; ?>" data-tab="menu">
                    <section class="mc-card">
                        <h2>Ordre, nom et masquage</h2>
                        <p class="mc-lead">Glissez une ligne pour la placer. Le champ renomme l’entrée. La case la masque. Masquer un menu ne bloque pas son adresse.</p>
                        <?php
                        $menu_items = self::ordered_menu_items($o);
                        $renames = is_array($o['menu_rename']) ? $o['menu_rename'] : [];
                        if ($menu_items) :
                            ?>
                            <div id="mc-menu-sort" class="mc-menu-list">
                                <?php foreach ($menu_items as $i => $item) : ?>
                                    <div class="mc-menu-row">
                                        <span class="mc-menu-handle" title="Glisser" aria-hidden="true">⋮⋮</span>
                                        <input type="hidden" name="<?php echo esc_attr($opt); ?>[menu_items][<?php echo (int) $i; ?>][slug]" value="<?php echo esc_attr($item['slug']); ?>">
                                        <label class="mc-menu-hide">
                                            <input type="checkbox" name="<?php echo esc_attr($opt); ?>[menu_items][<?php echo (int) $i; ?>][hide]" value="1" <?php checked(in_array($item['slug'], $o['hide_menus'], true)); ?>>
                                            <span class="screen-reader-text">Masquer <?php echo esc_html($item['title']); ?></span>
                                        </label>
                                        <span class="mc-menu-native"><?php echo esc_html($item['title']); ?></span>
                                        <input type="text" class="regular-text" name="<?php echo esc_attr($opt); ?>[menu_items][<?php echo (int) $i; ?>][title]" value="<?php echo esc_attr((string) ($renames[$item['slug']] ?? '')); ?>" placeholder="Nouveau nom">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <div class="mc-roles">
                                <?php foreach (self::MENUS as $slug => $label) : ?>
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[hide_menus][]" value="<?php echo esc_attr($slug); ?>" <?php checked(in_array($slug, $o['hide_menus'], true)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="mc-hide-extra">Autres menus</label></th>
                                <td>
                                    <input type="text" class="large-text" id="mc-hide-extra" name="<?php echo esc_attr($opt); ?>[hide_extra]" value="<?php echo esc_attr($o['hide_extra']); ?>" placeholder="edit.php?post_type=bien">
                                    <p class="description">Slugs absents de la liste, séparés par des virgules.</p>
                                </td>
                            </tr>
                        </table>
                    </section>
                    <section class="mc-card">
                        <h2>Notifications</h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Autres extensions</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[hide_notices]" value="1" <?php checked($o['hide_notices']); ?>>
                                        Regrouper les notifications dans la barre du haut
                                    </label>
                                    <p class="description">Elles restent accessibles, avec leur nombre, au lieu d’encombrer le haut des pages.</p>
                                </td>
                            </tr>
                        </table>
                    </section>
                </div>

                <div class="mc-panel<?php echo $tab === 'listes' ? ' is-active' : ''; ?>" data-tab="listes">
                    <section class="mc-card">
                        <h2>Listes d’articles</h2>
                        <p class="mc-lead">La barre d’outils est regroupée et le champ de recherche affiche un texte d’exemple.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">État</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[status_badges]" value="1" <?php checked($o['status_badges']); ?>>
                                        Colonne État (Publié, Brouillon, Programmé…)
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Actions</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[row_icons]" value="1" <?php checked($o['row_icons']); ?>>
                                        Colonne d’icônes à la place des liens sous le titre
                                    </label>
                                    <p class="description">Voir, modifier, modification rapide, corbeille, puis les liens des extensions : Elementor, IndexNow (éclair), duplication… « Créer avec YOOtheme » et « Modifier avec YOOtheme » sont retirés.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Identifiant</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[show_id]" value="1" <?php checked($o['show_id']); ?>>
                                        Colonne ID, triable
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Taxonomies</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[tax_filters]" value="1" <?php checked($o['tax_filters']); ?>>
                                        Filtres des taxonomies hiérarchiques déjà en place
                                    </label>
                                    <p class="description">Les catégories d’articles restent celles de WordPress. Une colonne de l’onglet Colonnes qui filtre déjà une taxonomie n’est pas doublée.</p>
                                </td>
                            </tr>
                        </table>
                    </section>
                </div>

                <div class="mc-panel<?php echo $tab === 'contenu' ? ' is-active' : ''; ?>" data-tab="contenu">
                    <section class="mc-card">
                        <h2>Duplication</h2>
                        <p class="mc-lead">Un lien « Dupliquer » dans les listes. La copie reprend le texte, les champs et les taxonomies, et s’ouvre en brouillon.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Listes</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[duplicate]" value="1" <?php checked($o['duplicate']); ?>>
                                        Autoriser la duplication
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </section>
                    <section class="mc-card">
                        <h2>Publications programmées</h2>
                        <p class="mc-lead">WordPress rate parfois l’heure prévue. Le contrôle a lieu au plus toutes les quinze minutes.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Calendrier</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[missed_schedule]" value="1" <?php checked($o['missed_schedule']); ?>>
                                        Publier les contenus dont l’heure est dépassée
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </section>
                    <section class="mc-card">
                        <h2>Médiathèque</h2>
                        <p class="mc-lead">Sur la fiche d’un média, un champ permet de remplacer le fichier. L’adresse reste la même si l’extension ne change pas.</p>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">Fichier</th>
                                <td>
                                    <label class="mc-choice">
                                        <input type="checkbox" name="<?php echo esc_attr($opt); ?>[media_replace]" value="1" <?php checked($o['media_replace']); ?>>
                                        Autoriser le remplacement
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </section>
                </div>

                <div class="mc-panel<?php echo $tab === 'colonnes' ? ' is-active' : ''; ?>" data-tab="colonnes">
                    <section class="mc-card">
                        <h2>Colonnes ajoutées</h2>
                        <p class="mc-lead">Une carte par colonne, sur le type de contenu choisi. Miniature, champ ACF, méta ou taxonomie. Une fonction PHP sur mesure reste possible dans un mu-plugin.</p>
                        <div id="mc-columns">
                            <?php foreach ($o['columns'] as $i => $col) {
                                self::column_fields((string) $i, $col);
                            } ?>
                        </div>
                        <p><button type="button" class="button" id="mc-col-add">Ajouter une colonne</button></p>
                    </section>
                    <section class="mc-card">
                        <h2>Colonnes à masquer</h2>
                        <p class="mc-lead">Une ligne : <code>type | slug, slug</code>. Exemple : <code>formation | author, comments</code>.</p>
                        <textarea class="large-text code" rows="5" name="<?php echo esc_attr($opt); ?>[columns_hide_text]"><?php echo esc_textarea(self::hide_text($o['columns_hide'])); ?></textarea>
                    </section>
                </div>

                <?php submit_button('Enregistrer'); ?>
                <p class="description mc-save-note">Tous les onglets sont enregistrés ensemble.</p>
            </form>

            <template id="mc-col-tpl"><?php self::column_fields('__i__', self::blank_column()); ?></template>
        </div>
        <?php
        self::print_style();
    }

    /** @param array<string, mixed> $col */
    private static function column_fields(string $index, array $col): void
    {
        $opt = self::OPTION;
        $base = $opt . '[columns][' . $index . ']';
        ?>
        <div class="mc-col">
            <div class="mc-col-grid">
                <label>Type de contenu
                    <select name="<?php echo esc_attr($base); ?>[post_type]">
                        <?php foreach (self::post_types() as $name => $type) : ?>
                            <option value="<?php echo esc_attr($name); ?>" <?php selected($col['post_type'], $name); ?>><?php echo esc_html($type->labels->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Titre de la colonne
                    <input type="text" name="<?php echo esc_attr($base); ?>[label]" value="<?php echo esc_attr($col['label']); ?>" placeholder="Prix">
                </label>
                <label>Source
                    <select name="<?php echo esc_attr($base); ?>[source]">
                        <?php foreach (['thumbnail' => 'Miniature', 'acf' => 'Champ ACF', 'meta' => 'Méta', 'taxonomy' => 'Taxonomie'] as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($col['source'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Champ, méta ou taxonomie
                    <input type="text" name="<?php echo esc_attr($base); ?>[field]" value="<?php echo esc_attr($col['field']); ?>" placeholder="prix">
                </label>
                <label>Format
                    <select name="<?php echo esc_attr($base); ?>[format]">
                        <?php foreach (['' => 'Texte', 'price' => 'Prix', 'number' => 'Nombre', 'date' => 'Date', 'bool' => 'Oui / Non', 'image' => 'Image'] as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($col['format'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Tri
                    <select name="<?php echo esc_attr($base); ?>[sort]">
                        <?php foreach (['' => 'Aucun', 'alpha' => 'Alphabétique', 'numeric' => 'Numérique', 'date' => 'Date'] as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($col['sort'], $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Largeur
                    <input type="text" name="<?php echo esc_attr($base); ?>[width]" value="<?php echo esc_attr($col['width']); ?>" placeholder="80px">
                </label>
                <label>Après la colonne
                    <input type="text" name="<?php echo esc_attr($base); ?>[after]" value="<?php echo esc_attr($col['after']); ?>" placeholder="title">
                </label>
                <label class="mc-col-check">
                    <input type="checkbox" name="<?php echo esc_attr($base); ?>[filter]" value="1" <?php checked(!empty($col['filter'])); ?>>
                    Proposer un filtre
                </label>
            </div>
            <p class="mc-col-actions"><button type="button" class="button-link-delete mc-col-remove">Retirer cette colonne</button></p>
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

    var tabs = document.querySelectorAll('.mc-settings .nav-tab');
    var panels = document.querySelectorAll('.mc-settings .mc-panel');
    var tabInput = document.getElementById('mc-tab');
    function show(id) {
        tabs.forEach(function (tab) {
            tab.classList.toggle('nav-tab-active', tab.getAttribute('data-tab') === id);
        });
        panels.forEach(function (panel) {
            panel.classList.toggle('is-active', panel.getAttribute('data-tab') === id);
        });
        if (tabInput) tabInput.value = id;
    }
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (e) {
            e.preventDefault();
            show(tab.getAttribute('data-tab'));
        });
    });

    var list = document.getElementById('mc-columns');
    var tpl = document.getElementById('mc-col-tpl');
    $('#mc-col-add').on('click', function () {
        if (!list || !tpl) return;
        list.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(Date.now())));
    });
    if (list) {
        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.mc-col-remove');
            if (!btn) return;
            e.preventDefault();
            var row = btn.closest('.mc-col');
            if (row) row.remove();
        });
    }

    var menuList = document.getElementById('mc-menu-sort');
    if (menuList) {
        var drag = null;
        menuList.querySelectorAll('.mc-menu-row').forEach(function (row) {
            var handle = row.querySelector('.mc-menu-handle');
            if (!handle) return;
            handle.setAttribute('draggable', 'true');
            handle.addEventListener('dragstart', function (e) {
                drag = row;
                row.classList.add('is-dragging');
                if (e.dataTransfer) {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', row.querySelector('input[type="hidden"]').value || 'menu');
                }
            });
            handle.addEventListener('dragend', function () {
                row.classList.remove('is-dragging');
                drag = null;
            });
            row.addEventListener('dragover', function (e) {
                if (!drag || drag === row) return;
                e.preventDefault();
                var rect = row.getBoundingClientRect();
                var after = (e.clientY - rect.top) > rect.height / 2;
                menuList.insertBefore(drag, after ? row.nextSibling : row);
            });
        });
        var form = menuList.closest('form');
        if (form) {
            form.addEventListener('submit', function () {
                menuList.querySelectorAll('.mc-menu-row').forEach(function (row, i) {
                    row.querySelectorAll('[name]').forEach(function (input) {
                        input.name = input.name.replace(/\[menu_items\]\[\d+\]/, '[menu_items][' + i + ']');
                    });
                });
            });
        }
    }
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

        $previous = get_option(self::OPTION, []);
        if (!is_array($previous)) {
            $previous = [];
        }

        $extras = [];
        $extra_raw = sanitize_text_field((string) ($input['hide_extra'] ?? ''));
        foreach (preg_split('/\s*,\s*/', $extra_raw) ?: [] as $slug) {
            $slug = self::menu_slug($slug);
            if ($slug !== '' && !in_array($slug, $extras, true)) {
                $extras[] = $slug;
            }
        }

        $menu_order  = [];
        $menu_rename = [];
        if (!empty($input['menu_items']) && is_array($input['menu_items'])) {
            $hide = [];
            $seen = [];
            foreach ($input['menu_items'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $slug = self::menu_slug((string) ($row['slug'] ?? ''));
                if ($slug === '' || isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;
                $menu_order[] = $slug;
                if (!empty($row['hide'])) {
                    $hide[] = $slug;
                }
                $title = sanitize_text_field((string) ($row['title'] ?? ''));
                if ($title !== '') {
                    $menu_rename[$slug] = $title;
                }
            }
            foreach ($extras as $slug) {
                if (!isset($seen[$slug])) {
                    $hide[] = $slug;
                }
            }
            $hide_extra = [];
            foreach ($extras as $slug) {
                if (!isset($seen[$slug])) {
                    $hide_extra[] = $slug;
                }
            }
        } else {
            $known_menus = array_keys(self::MENUS);
            $hide = array_values(array_intersect((array) ($input['hide_menus'] ?? []), $known_menus));
            foreach ($extras as $slug) {
                if (!in_array($slug, $hide, true)) {
                    $hide[] = $slug;
                }
            }
            $hide_extra = array_values(array_diff($hide, $known_menus));
            if (isset($previous['menu_order']) && is_array($previous['menu_order'])) {
                foreach ($previous['menu_order'] as $slug) {
                    $slug = self::menu_slug((string) $slug);
                    if ($slug !== '') {
                        $menu_order[] = $slug;
                    }
                }
            }
            if (isset($previous['menu_rename']) && is_array($previous['menu_rename'])) {
                foreach ($previous['menu_rename'] as $slug => $title) {
                    $slug  = self::menu_slug((string) $slug);
                    $title = sanitize_text_field((string) $title);
                    if ($slug !== '' && $title !== '') {
                        $menu_rename[$slug] = $title;
                    }
                }
            }
        }

        return [
            'enabled'        => empty($input['enabled']) ? 0 : 1,
            'roles_mode'     => $mode,
            'roles'          => $roles,
            'brand_name'     => sanitize_text_field((string) ($input['brand_name'] ?? '')),
            'logo'           => esc_url_raw((string) ($input['logo'] ?? '')),
            'primary'        => self::hex((string) ($input['primary'] ?? ''), $base['primary']),
            'menu_bg'        => self::hex((string) ($input['menu_bg'] ?? ''), $base['menu_bg']),
            'menu_width'     => self::menu_width($input['menu_width'] ?? $base['menu_width']),
            'adminbar'       => empty($input['adminbar_light']) ? 'dark' : 'light',
            'footer'         => wp_kses_post((string) ($input['footer'] ?? '')),
            'dash_title'     => sanitize_text_field((string) ($input['dash_title'] ?? '')),
            'dash_intro'     => sanitize_text_field((string) ($input['dash_intro'] ?? '')),
            'help'           => wp_kses_post((string) ($input['help'] ?? '')),
            'shortcuts'      => self::parse_shortcuts((string) ($input['shortcuts_text'] ?? '')),
            'hide_menus'     => array_values($hide),
            'hide_extra'     => implode(', ', $hide_extra),
            'menu_order'     => $menu_order,
            'menu_rename'    => $menu_rename,
            'hide_notices'   => empty($input['hide_notices']) ? 0 : 1,
            'status_badges'  => empty($input['status_badges']) ? 0 : 1,
            'row_icons'      => empty($input['row_icons']) ? 0 : 1,
            'show_id'        => empty($input['show_id']) ? 0 : 1,
            'tax_filters'    => empty($input['tax_filters']) ? 0 : 1,
            'duplicate'      => empty($input['duplicate']) ? 0 : 1,
            'missed_schedule'=> empty($input['missed_schedule']) ? 0 : 1,
            'media_replace'  => empty($input['media_replace']) ? 0 : 1,
            'overlay'        => empty($input['overlay']) ? 0 : 1,
            'admin_css'      => self::sanitize_css((string) ($input['admin_css'] ?? '')),
            'columns'        => self::sanitize_columns($input['columns'] ?? []),
            'columns_hide'   => self::sanitize_hide((string) ($input['columns_hide_text'] ?? '')),
        ];
    }

    private static function print_style(): void
    {
        echo '<style>
.mc-settings .nav-tab-wrapper{margin:16px 0 0;}
.mc-settings .mc-panel{display:none;margin-top:22px;}
.mc-settings .mc-panel.is-active{display:block;}
.mc-card{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:6px 28px 18px;margin:0 0 18px;max-width:920px;box-shadow:0 1px 2px rgba(16,24,40,.04);}
.mc-card h2{margin:22px 0 6px;font-size:16px;font-weight:600;}
.mc-lead{margin:0 0 8px;color:#646970;max-width:62em;}
.mc-card .form-table th{width:220px;padding-top:22px;padding-bottom:22px;font-weight:600;}
.mc-card .form-table td{padding-top:18px;padding-bottom:18px;}
.mc-choice{display:block;margin:0 0 10px;}
.mc-roles{display:flex;flex-wrap:wrap;gap:10px 22px;margin-top:14px;}
.mc-roles label{display:inline-flex;align-items:center;gap:6px;}
.mc-col{background:#F9FAFB;border:1px solid #E5E7EB;border-radius:10px;padding:16px 18px 4px;margin:0 0 14px;}
.mc-col-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 22px;}
.mc-col-grid label{display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:600;color:#374151;}
.mc-col-grid input,.mc-col-grid select{font-weight:400;font-size:14px;max-width:100%;}
.mc-col-check{flex-direction:row !important;align-items:center;align-self:end;padding-bottom:6px;}
.mc-col-actions{margin:12px 0 10px;}
.mc-save-note{margin-top:-4px;}
.mc-menu-list{max-width:720px;margin:8px 0 6px;}
.mc-menu-row{display:grid;grid-template-columns:28px 28px minmax(120px,1fr) minmax(160px,1.2fr);gap:10px;align-items:center;padding:8px 10px;margin:0 0 6px;background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;}
.mc-menu-row.is-dragging{opacity:.45;}
.mc-menu-handle{color:#9CA3AF;font-size:16px;line-height:1;text-align:center;cursor:grab;}
.mc-menu-hide{display:flex;align-items:center;justify-content:center;margin:0;}
.mc-menu-native{color:#646970;font-size:13px;}
.mc-menu-row input.regular-text{width:100%;max-width:none;}
@media (max-width:782px){
  .mc-col-grid{grid-template-columns:1fr;}
  .mc-card{padding-left:16px;padding-right:16px;}
  .mc-menu-row{grid-template-columns:28px 28px 1fr;}
  .mc-menu-row input.regular-text{grid-column:1 / -1;}
}
</style>';
    }

    /** @return array<string, WP_Post_Type> */
    private static function post_types(): array
    {
        $types = get_post_types(['show_ui' => true], 'objects');
        unset($types['attachment']);
        return $types;
    }

    /** @return array<string, string> */
    private static function blank_column(): array
    {
        $types = array_keys(self::post_types());
        return [
            'post_type' => $types[0] ?? 'post',
            'key'       => '',
            'label'     => '',
            'source'    => 'acf',
            'field'     => '',
            'format'    => '',
            'sort'      => '',
            'width'     => '',
            'after'     => '',
            'filter'    => 0,
        ];
    }

    /** @param mixed $rows */
    private static function sanitize_columns($rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $types = array_keys(self::post_types());
        $sources = ['thumbnail', 'acf', 'meta', 'taxonomy'];
        $formats = ['', 'price', 'number', 'date', 'bool', 'image'];
        $sorts = ['', 'alpha', 'numeric', 'date'];
        $out = [];
        $used = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $post_type = sanitize_key((string) ($row['post_type'] ?? ''));
            $source = (string) ($row['source'] ?? '');
            $field = sanitize_key((string) ($row['field'] ?? ''));
            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            if (!in_array($post_type, $types, true) || !in_array($source, $sources, true)) {
                continue;
            }
            if ($source !== 'thumbnail' && $field === '') {
                continue;
            }
            if ($label === '' && $source === 'thumbnail') {
                $label = 'Image';
            }

            $key = $source === 'thumbnail' ? 'thumbnail' : $field;
            if ($key === '') {
                $key = sanitize_key($label);
            }
            $base_key = $key !== '' ? $key : 'col';
            $n = 2;
            while (isset($used[$post_type][$key])) {
                $key = $base_key . '_' . $n;
                $n++;
            }
            $used[$post_type][$key] = true;

            $format = (string) ($row['format'] ?? '');
            $sort = (string) ($row['sort'] ?? '');
            $width = strtolower(trim((string) ($row['width'] ?? '')));
            $after = sanitize_key((string) ($row['after'] ?? ''));

            $out[] = [
                'post_type' => $post_type,
                'key'       => $key,
                'label'     => $label,
                'source'    => $source,
                'field'     => $field,
                'format'    => in_array($format, $formats, true) ? $format : '',
                'sort'      => in_array($sort, $sorts, true) ? $sort : '',
                'width'     => preg_match('/^\d{1,4}(px|%)$/', $width) ? $width : '',
                'after'     => $after,
                'filter'    => empty($row['filter']) ? 0 : 1,
            ];
        }
        return $out;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function hide_text(array $rows): string
    {
        $lines = [];
        foreach ($rows as $row) {
            if (!empty($row['post_type']) && !empty($row['keys'])) {
                $lines[] = $row['post_type'] . ' | ' . implode(', ', $row['keys']);
            }
        }
        return implode("\n", $lines);
    }

    /** @return array<int, array{post_type: string, keys: string[]}> */
    private static function sanitize_hide(string $text): array
    {
        $types = array_keys(self::post_types());
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '|') === false) {
                continue;
            }
            [$post_type, $keys] = array_map('trim', explode('|', $line, 2));
            $post_type = sanitize_key($post_type);
            if (!in_array($post_type, $types, true)) {
                continue;
            }
            $clean = [];
            foreach (preg_split('/\s*,\s*/', $keys) ?: [] as $key) {
                $key = sanitize_key($key);
                if ($key !== '') {
                    $clean[] = $key;
                }
            }
            if ($clean) {
                $out[] = ['post_type' => $post_type, 'keys' => array_values(array_unique($clean))];
            }
        }
        return $out;
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
            'enabled'       => 1,
            'roles_mode'    => 'except_admin',
            'roles'         => [],
            'brand_name'    => '',
            'logo'          => '',
            'primary'       => '#2F6FED',
            'menu_bg'       => '#111827',
            'menu_width'    => 240,
            'adminbar'      => 'dark',
            'footer'        => 'Site réalisé par <a href="https://www.midiconcept.fr" target="_blank">Midiconcept</a>',
            'dash_title'    => 'Bienvenue',
            'dash_intro'    => 'Que souhaitez-vous faire aujourd’hui ?',
            'help'          => '',
            'shortcuts'     => [
                ['label' => 'Toutes les pages', 'url' => 'edit.php?post_type=page', 'icon' => 'dashicons-admin-page'],
                ['label' => 'Médiathèque', 'url' => 'upload.php', 'icon' => 'dashicons-format-gallery'],
                ['label' => 'Voir le site', 'url' => '/', 'icon' => 'dashicons-external'],
            ],
            'hide_menus'      => ['edit-comments.php', 'tools.php'],
            'hide_extra'      => '',
            'menu_order'      => [],
            'menu_rename'     => [],
            'hide_notices'    => 1,
            'status_badges'   => 1,
            'row_icons'       => 1,
            'show_id'         => 1,
            'tax_filters'     => 1,
            'duplicate'       => 1,
            'missed_schedule' => 1,
            'media_replace'   => 1,
            'overlay'         => 1,
            'admin_css'       => '',
            'columns'       => [],
            'columns_hide'  => [],
        ];
    }

    public static function print_admin_css(): void
    {
        $o = self::get();
        $parts = [];
        if (!empty($o['overlay'])) {
            $overlay = self::overlay_css();
            if ($overlay !== '') {
                $parts[] = $overlay;
            }
        }
        $custom = $o['admin_css'] ?? '';
        if (is_string($custom) && trim($custom) !== '') {
            $parts[] = $custom;
        }
        if (!$parts) {
            return;
        }
        echo '<style id="mc-admin-custom">' . implode("\n", $parts) . "</style>\n"; // phpcs:ignore -- CSS déjà filtré
    }

    private static function overlay_css(): string
    {
        $file = MC_CORE_DIR . 'assets/admin-overlay.css';
        if (!is_readable($file)) {
            return '';
        }
        $css = file_get_contents($file);
        return is_string($css) ? self::sanitize_css($css) : '';
    }

    private static function sanitize_css(string $css): string
    {
        $css = str_replace("\0", '', $css);
        $css = wp_strip_all_tags($css);
        $css = preg_replace('/<\/\s*style/i', '', $css) ?? $css;
        if (strlen($css) > 50000) {
            $css = substr($css, 0, 50000);
        }
        return trim($css);
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
