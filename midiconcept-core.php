<?php
/**
 * Plugin Name:       MidiConcept Core
 * Plugin URI:        https://www.midiconcept.fr
 * Description:       Boîte à outils MidiConcept pour les sites clients : admin client modernisé, colonnes d'admin personnalisées. Mises à jour depuis GitHub.
 * Version:           1.2.2
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Midiconcept
 * Author URI:        https://www.midiconcept.fr
 * Text Domain:       midiconcept-core
 * Update URI:        https://github.com/mc-pcabrol/midiconcept-core
 */

defined('ABSPATH') || exit;

define('MC_CORE_VERSION', '1.2.2');
define('MC_CORE_FILE', __FILE__);
define('MC_CORE_DIR', plugin_dir_path(__FILE__));

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * Dépôt GitHub des mises à jour
 * ─────────────────────────────────────────────────────────────────────────────
 * Surcharge possible dans wp-config.php :
 *   define('MC_CORE_REPO', 'https://github.com/mc-pcabrol/midiconcept-core/');
 *   define('MC_CORE_GITHUB_TOKEN', 'github_pat_…');   // uniquement si le dépôt est privé
 */
if (!defined('MC_CORE_REPO')) {
    define('MC_CORE_REPO', 'https://github.com/mc-pcabrol/midiconcept-core/');
}

/* ─────────────────────────────── Modules ─────────────────────────────────── */

require_once MC_CORE_DIR . 'includes/class-mc-admin-columns.php';
require_once MC_CORE_DIR . 'includes/class-mc-client-admin.php';
require_once MC_CORE_DIR . 'includes/class-mc-settings.php';

MC_Settings::boot();
add_action('admin_enqueue_scripts', ['MC_Settings', 'assets']);

/*
 * Configuration du site.
 * 1. La page Réglages → MidiConcept (aucun fichier à déposer).
 * 2. Un mu-plugin ou le thème peut prendre le pas, pour les colonnes notamment :
 *
 * add_action('midiconcept_core_init', function () {
 *     MC_Client_Admin::init([...]);
 *     MC_Admin_Columns::add('vehicule', [...]);
 * });
 */
add_action('init', static function () {
    do_action('midiconcept_core_init');

    if (MC_Client_Admin::is_ready()) {
        MC_Settings::$from_code = true;
        return;
    }

    $config = MC_Settings::client_config();
    if ($config !== null) {
        MC_Client_Admin::init($config);
    }
}, 1);

/* ─────────────────────────────── Mises à jour ────────────────────────────── */

add_action('plugins_loaded', static function () {
    if (!is_admin() && !wp_doing_cron() && !(defined('WP_CLI') && WP_CLI)) {
        return; // inutile de charger le vérificateur en front
    }

    require_once MC_CORE_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

    $checker = \YahnisElsts\PluginUpdateChecker\v6\PucFactory::buildUpdateChecker(
        MC_CORE_REPO,
        MC_CORE_FILE,
        'midiconcept-core'
    );

    // On installe le zip propre attaché à chaque release GitHub (construit par GitHub Actions).
    $checker->getVcsApi()->enableReleaseAssets('/midiconcept-core\.zip($|[?&#])/i');

    if (defined('MC_CORE_GITHUB_TOKEN') && MC_CORE_GITHUB_TOKEN) {
        $checker->setAuthentication(MC_CORE_GITHUB_TOKEN);
    }
});

/* ─────────────────────────────── Divers ──────────────────────────────────── */

// Lien rapide vers le dépôt dans la liste des extensions.
add_filter('plugin_row_meta', static function (array $links, string $file) {
    if ($file === plugin_basename(MC_CORE_FILE)) {
        $links[] = '<a href="' . esc_url(MC_CORE_REPO . 'releases') . '" target="_blank" rel="noopener">Notes de version</a>';
    }
    return $links;
}, 10, 2);
