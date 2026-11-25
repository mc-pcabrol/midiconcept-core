<?php
/**
 * Plugin Name: Config site – MidiConcept Core
 * Description: Configuration propre à ce site (admin client, colonnes). Nécessite le plugin MidiConcept Core.
 *
 * À copier dans wp-content/mu-plugins/ et à adapter pour chaque client.
 * (Variante : coller le add_action() dans le functions.php du thème enfant.)
 */

defined('ABSPATH') || exit;

add_action('midiconcept_core_init', static function () {

    /* ── Admin client ─────────────────────────────────────────────────── */
    MC_Client_Admin::init([
        'roles' => ['editor'],

        'theme' => [
            'primary' => '#2F6FED',
            'menu_bg' => '#111827',
        ],

        'brand' => [
            'name'   => 'Nom du client',
            'logo'   => content_url('uploads/logo-client.svg'),
            'footer' => 'Site réalisé par <a href="https://www.midiconcept.fr" target="_blank">Midiconcept</a> · Besoin d’aide ? 05 00 00 00 00',
        ],

        'dashboard' => [
            'title'     => 'Bienvenue',
            'shortcuts' => [
                ['label' => 'Ajouter une actualité', 'url' => 'post-new.php', 'icon' => 'dashicons-megaphone', 'cap' => 'edit_posts'],
                ['label' => 'Toutes les pages',      'url' => 'edit.php?post_type=page', 'icon' => 'dashicons-admin-page'],
                ['label' => 'Médiathèque',           'url' => 'upload.php', 'icon' => 'dashicons-format-gallery'],
                ['label' => 'Voir le site',          'url' => home_url('/'), 'target' => '_blank', 'icon' => 'dashicons-external'],
            ],
            'help' => 'Une question ? Appelez Midiconcept au 05 00 00 00 00.',
        ],

        'menu' => [
            'hide'   => ['edit-comments.php', 'tools.php'],
            'rename' => ['edit.php' => 'Actualités'],
        ],

        'hide_notices' => true,
    ]);

    /* ── Colonnes d'admin ─────────────────────────────────────────────── */
    // MC_Admin_Columns::add('post', [
    //     'photo' => ['label' => '', 'thumbnail' => true, 'after' => 'cb', 'width' => '70px'],
    // ]);
});
