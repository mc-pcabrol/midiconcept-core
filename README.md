# MidiConcept Core

Plugin WordPress commun à tous les sites clients Midiconcept.

| Module | Classe | Rôle |
|---|---|---|
| Admin client | `MC_Client_Admin` | Thème admin modernisé, tableau de bord à raccourcis, menu épuré, branding de la connexion |
| Colonnes d'admin | `MC_Admin_Columns` | Colonnes ACF, méta, taxonomie, miniature, callback, avec tri et filtres |

Les mises à jour arrivent dans **Extensions** comme pour un plugin du répertoire officiel, via [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (v6.0, MIT, embarqué dans `lib/`).

---

## 1. Mise en place (une seule fois)

1. Le dépôt public est [mc-pcabrol/midiconcept-core](https://github.com/mc-pcabrol/midiconcept-core).
   - **Public** (cas actuel) : aucun token sur les sites clients.
   - **Privé** : GitHub refuse la lecture anonyme. Il faut alors un token en lecture seule (Contents: Read-only), soit dans le `wp-config.php` de chaque site (voir §3), soit derrière un serveur de mises à jour à vous.
2. Si le dépôt change de compte, modifie `MC_CORE_REPO` et `Update URI` dans `midiconcept-core.php`.
3. Pousse le code :

   ```bash
   git remote add origin git@github.com:mc-pcabrol/midiconcept-core.git
   git push -u origin main
   git push origin v1.0.0
   ```

   Le tag `v1.0.0` déclenche GitHub Actions, qui publie la première release avec `midiconcept-core.zip`.

## 2. Installer sur un site client

Aucun accès FTP. Tout se fait dans l’admin WordPress :

1. Télécharge `midiconcept-core.zip` depuis la release.
2. *Extensions → Ajouter → Téléverser une extension*, puis active.
3. *Réglages → MidiConcept* : nom, logo, couleurs, raccourcis, menus masqués.

Les réglages sont en base : une mise à jour de l’extension ne les écrase pas.

Si WordPress affiche un formulaire « Identifiants FTP » au moment du téléversement, l’hébergeur interdit à PHP d’écrire dans `wp-content`. Ce n’est pas le plugin. Le gestionnaire de fichiers de l’hébergeur (ou un accès SSH) débloque ça ; le FTP n’est pas le mode normal.

## 3. Dépôt privé : token par site

Dans `wp-config.php` :

```php
define('MC_CORE_GITHUB_TOKEN', 'github_pat_xxx');
// Facultatif, si le dépôt change d'adresse :
// define('MC_CORE_REPO', 'https://github.com/autre-compte/midiconcept-core/');
```

## 4. Publier une mise à jour

```bash
# Développer, puis commiter normalement
git add -A && git commit -m "Admin client : tuiles plus grandes"

# Publier
bin/release.sh patch     # 1.0.0 → 1.0.1 (correctif)
bin/release.sh minor     # 1.0.1 → 1.1.0 (nouvelle fonction)
bin/release.sh 2.0.0     # version précise
```

Le script met à jour la version (en-tête + constante), commite, tague et pousse. GitHub Actions vérifie la syntaxe PHP, construit le zip propre (sans `.git`, `bin`, `examples`…) et publie la release.

Les sites la voient lors de leur prochaine vérification (environ toutes les 12 h). Pour forcer la vérification : *Extensions → MidiConcept Core → « Vérifier les mises à jour »*, ou *Tableau de bord → Mises à jour*.

> Sous Windows, lance `bin/release.sh` depuis **Git Bash** (profil disponible dans le terminal de Cursor).

## 5. Configurer un site

Le cas courant : *Réglages → MidiConcept*.

Pour les colonnes d’admin, ou une config que l’écran ne couvre pas, un mu-plugin (ou le `functions.php` du thème) prend le pas sur la page de réglages :

```php
add_action('midiconcept_core_init', function () {
    MC_Client_Admin::init([ /* … */ ]);
    MC_Admin_Columns::add('vehicule', [ /* … */ ]);
});
```

Le modèle est dans `examples/mc-site-config.php`. Les options sont documentées en tête de chaque fichier dans `includes/`. Ce fichier demande un accès au disque : il n’est pas nécessaire pour installer et régler l’admin client.

## Structure

```
midiconcept-core/
├── midiconcept-core.php          plugin principal + mises à jour
├── includes/
│   ├── class-mc-admin-columns.php
│   ├── class-mc-client-admin.php
│   └── class-mc-settings.php      page Réglages → MidiConcept
├── lib/plugin-update-checker/    bibliothèque embarquée (ne pas modifier)
├── examples/mc-site-config.php   modèle facultatif (colonnes, options avancées)
├── bin/release.sh                script de publication
└── .github/workflows/release.yml build + release automatiques
```

## Ajouter un module

1. Crée `includes/class-mc-xxx.php`.
2. Ajoute son `require_once` dans `midiconcept-core.php`.
3. Lance `bin/release.sh minor`.
