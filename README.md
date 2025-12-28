# WP Etik Events

Gestion d'événements pour WordPress — inscriptions côté front, affichage via un module Divi, et intégrations de paiements (travail en cours).

## Description
WP Etik Events est un plugin WordPress développé par Le Web Etik pour gérer des événements, permettre des inscriptions depuis le front-end et fournir un module Divi pour l'affichage des événements. Le plugin contient des handlers AJAX, un loader d'architecture modulaire (src/Loader.php), et des composants d'administration (ex. duplication d'événement).

Version courante (branche dev) : travail actif sur les intégrations de paiement (Stripe, Mollie) et ajout d'options de sécurité (hCaptcha).

## Fonctionnalités principales
- Création et gestion d'événements (backend).
- Inscriptions depuis le front-end (formulaires gérés via AJAX).
- Module Divi pour afficher les événements (si Divi est présent).
- Hooks et point d'extension via `src/Loader.php`.
- Préparations pour intégrations de paiements (Stripe, Mollie — en cours).
- Options hCaptcha (clé site & secret ajoutées via options WordPress).

## Pré-requis
- WordPress 5.8+ recommandé.
- PHP 7.4+ (ou version PHP supportée par votre WordPress).
- Divi Builder (optionnel, nécessaire pour utiliser le module Divi).
- Clés API Stripe / Mollie si vous activez les paiements (fonctionnalité en cours).

## Installation
1. Copier le dossier du plugin dans `wp-content/plugins/wp-etik-events` (branche `dev` si vous testez les dernières évolutions).
2. Activer le plugin depuis le tableau de bord WordPress > Extensions.
3. Vérifier les journaux si vous développez : le plugin affiche des logs de vérification Divi (utile en développement).

Alternativement, empaquetez en ZIP et installez via l'interface "Ajouter" > "Téléverser une extension".

## Configuration
- hCaptcha : le plugin ajoute les options `wp_etik_hcaptcha_sitekey` et `wp_etik_hcaptcha_secret`. Remplissez ces options via votre mécanisme d'administration (à implémenter dans l'admin si non présent).
- Paiements : des commits récents indiquent l'ajout de code pour Stripe/Mollie. Vérifiez les fichiers dans `includes/admin/` et la présence d'un fichier de configuration. Pour l'instant, la configuration complète est en développement — ne mettez pas en production sans tests.

## Structure du dépôt (aperçu)
- wp-etik-events.php — fichier principal du plugin (bootstrap).
- includes/
  - ajax-handlers.php — gestion des requêtes AJAX.
  - admin/duplicate-event.php — outil d'administration pour dupliquer un événement.
  - (potentiellement d'autres fichiers admin / réglages)
- src/
  - Loader.php — chargeur/initialiseur du plugin et point d'extension principal.
- assets/ — ressources publiques (CSS/JS/images) — à compléter.
- LICENSE — GNU GPL v3.0

## Utilisation
- Après activation, créez vos événements (la structure CPT/UI dépend de l'implémentation backend).
- Ajouter le module Divi sur vos pages si vous utilisez Divi — le plugin détecte Divi et enfile certains packages JS de WP nécessaires au builder.
- Les formulaires d'inscription front s'appuient sur AJAX (voir `includes/ajax-handlers.php`) : vérifiez que les endpoints sont corrects et sécurisés avant mise en production.

## Développement & contribution
- Branche de développement : `dev`.
- Architecture : privilégier l'ajout de classes dans `src/` et les inclure via `Loader`.
- Logging : le plugin utilise `error_log()` pour certaines étapes de débogage Divi. Enlevez ou ajustez ces logs pour la production.
- Tests : pas de suite de tests détectée — ajouter PHPUnit / WP_Test pour la CI est recommandé.
- Pour contribuer : ouvrir une issue ou un PR vers la branche `dev`. Voir la page Issues du dépôt : https://github.com/le-web-etik/wp-etik-events/issues

## Limitations connues / Work in progress
- Intégration Stripe / Mollie : plusieurs commits récents montrent travail actif — fonctionnalité non marquée comme stable.
- Interface d'administration pour config (clefs API, hCaptcha) : à vérifier/compléter.
- Enqueue des assets pour l'inscription front : la fonction d'enqueue est présente mais vide dans la branche analysée.
- Tests, documentation fonctionnelle et exemples d'utilisation (shortcodes / hooks) manquants.

## Sécurité
- Validez et échappez toutes les entrées frontend (non vérifié automatiquement).
- Protégez les endpoints AJAX contre CSRF (non vérifié dans l'analyse).
- Ne stockez pas de clés secrètes en clair dans le code; utilisez les options WP ou l'intégration d'un gestionnaire de secrets.

## Licence
GPL-3.0 (voir fichier LICENSE).

## Points d'amélioration recommandés
- Ajouter une page d'options admin pour gérer hCaptcha, Stripe et Mollie.
- Documenter les hooks/shortcodes/shortcuts exposés par `src/Loader.php`.
- Ajouter une suite de tests et une CI (GitHub Actions).
- Compléter l'enqueue des assets et minimifier les dépendances pour la production.
- Ajouter des exemples d'utilisation du module Divi (capture d'écran / instructions).

---

Si vous voulez, je peux :
- 1) Générer et proposer un fichier README.md dans la branche `dev` (PR ou commit direct selon vos droits).  
- 2) Générer une checklist d'issues/actions techniques à partir des points "work in progress" (PRs, tâches), prête à copier en Issues GitHub.  
- 3) Inspecter plus en détail des fichiers précis (ex. `includes/ajax-handlers.php`, `src/Loader.php`) et produire une doc développeur ou des suggestions de refactor.

Dites-moi quelle action vous préférez faire ensuite.
