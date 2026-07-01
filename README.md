# Varnish Network Purge

Plugin WordPress **multisite** pour vider le cache **Varnish** de tous les sites du réseau — ou d'un seul — sans passer par la ligne de commande.

Conçu pour un réseau multisite en **domain mapping** hébergé derrière Varnish (ex. Infomaniak), où chaque site possède son propre domaine. La liste des domaines est récupérée **dynamiquement** via `get_sites()` : tout nouveau site du réseau est pris en charge automatiquement.

## Fonctionnalités

- **Admin Réseau** (`Réseau → Réglages → Cache Varnish`)
  - Purge globale de tous les domaines du réseau en une passe.
  - Purge site par site (tableau listant chaque domaine).
  - URL secrète (jeton) régénérable.
- **Réglages du site** (`Réglages → Cache Varnish`)
  - Bouton de purge du **site courant** uniquement (accessible aux administrateurs de site, capacité `manage_options`).
- **Barre d'administration** (topbar)
  - Raccourci « Cache Varnish » avec *Purger ce site* et, pour les super-admins, *Purger tout le réseau*.
- **Déclenchement par URL** (curl / cron / marque-page)
  - `https://exemple.com/?varnish_purge=JETON` → purge tout le réseau.
  - `…&host=DOMAINE` → purge un seul site.

## Fonctionnement

Pour chaque cible, le plugin envoie en parallèle (`curl_multi`) deux requêtes HTTP `PURGE` :

```
PURGE https://exemple.com/
PURGE https://exemple.com/*
```

`/*` couvre l'ensemble du site (y compris les sous-sites en sous-dossier partageant le domaine).

## Sécurité

- Purges en back-office : capacités WordPress + nonce.
  - Purge globale / par site / régénération du jeton : `manage_network` (super-admin).
  - Purge du site courant : `manage_options` (admin de site).
- Endpoint URL : jeton secret comparé en `hash_equals` (anti-timing), en-têtes `nocache`, et **limitation par cible** (10 s entre deux purges d'une même cible) pour éviter le *cache stampede*.

## Installation

1. Copier le dossier `varnish-network-purge/` dans `wp-content/plugins/`.
2. Dans l'admin **Réseau → Extensions**, cliquer sur **Activer sur le réseau**.
3. Ouvrir **Réseau → Réglages → Cache Varnish** pour récupérer l'URL/jeton.

## Prérequis

- WordPress multisite (5.2+), PHP 7.2+ avec l'extension cURL.
- Un frontal Varnish acceptant la méthode `PURGE` depuis le serveur d'origine.

## Licence

GPL-2.0-or-later.
