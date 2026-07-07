# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Progetto

Sito Drupal 10 per patrimonio culturale digitale (ASMSA - Archivio Storico). Integra **Omeka-S** (piattaforma GLAM) tramite API REST per visualizzare collezioni digitali su mappe e timeline. Tema **Bootstrap Italia** per conformità al design system della PA italiana.

## Architettura

### Moduli custom (area di sviluppo principale)

- **dog** (`web/modules/custom/dog/`) — Modulo principale "Drupal Omeka Geonode". Gestisce:
  - Cache dedicata Omeka (`cache.omeka` bin)
  - Servizi: `OmekaResourceFetcher` (recupero risorse), `OmekaUrlService` (gestione URL base API), `OmekaResourceViewBuilder` (rendering)
  - Field type/widget/formatter per risorse Omeka
  - Views plugin (filtri per collection, argomenti per resource type)
  - Event subscriber per query risorse
  - Form di configurazione (`SettingsForm`)
- **dog_library** (`web/modules/custom/dog/modules/dog_library/`) — Integrazione Layout Builder, UI selezione risorse Omeka
- **dog_ckeditor5** (`web/modules/custom/dog/modules/dog_ckeditor5/`) — Plugin CKEditor 5 per embedding risorse Omeka inline
- **omeka_utils** (`web/modules/custom/omeka_utils/`) — Utility API legacy (in fase di dismissione, dog lo bypassa progressivamente)
- **omeka_stats_block**, **dog_utils** — Moduli di supporto

### Flusso dati Omeka

API REST Omeka: `https://<base_url>/api/` (la base URL può essere es. `http://storia.dh.unica.it/risorse`, aggiungere `/api` direttamente).

Template chiave per rendering:
- `block--omeka-map.html.twig` — Mappa con oggetti Omeka (coordinate, titolo, immagini)
- `block--omeka-map-timeline.html.twig` — Mappa + timeline (date inizio/fine eventi)
- Template library in `dog_library/templates/` — UI selezione risorse nel backend

**Problema performance attuale**: chiamate API sincrone in tempo reale rallentano con >20 oggetti. Soluzione in corso: servizio batch di pre-caricamento in cache (cron giornaliero + bottone manuale), eliminazione totale chiamate live.

### Tema

`web/themes/custom/italiagov/` — Child theme di Bootstrap Italia 2.6.0, compilato con Webpack.

## Comandi di sviluppo

### Docker

```bash
make up                          # Avvia container (pull + up)
make stop                        # Ferma container
make prune                       # Rimuovi container e volumi
make shell                       # Shell nel container PHP
make logs                        # Log container (opzionale: make logs php)
make ps                          # Lista container attivi
```

### Drupal/Drush (via Make)

```bash
make drush "cr"                  # Cache rebuild
make drush "cim"                 # Import configurazione
make drush "cex"                 # Export configurazione
make drush "upwd admin admin"    # Reset password admin
make drush "watchdog:show"       # Log Drupal
```

### Composer (via Make)

```bash
make composer "install"
make composer "require drupal/modulo"
make composer "update drupal/core --with-dependencies"
```

### Tema (dentro container PHP o con Node 18+)

```bash
cd web/themes/custom/italiagov
npm install
npm run build:prod               # Build produzione
npm run build:dev                # Build sviluppo
npm run watch:dev                # Watch mode
```

### Deploy

```bash
./scripts/deploy.sh              # Deploy
./scripts/backup-remote.sh       # Backup DB remoto
./scripts/update-drupal.sh       # Aggiornamento Drupal
```

## Stack tecnico

| Componente | Versione |
|-----------|---------|
| Drupal | 10.1+ |
| PHP | 8.2 |
| MariaDB | 10.11 |
| Drush | 12.1+ |
| Node.js | 18+ |
| Bootstrap Italia | 2.6.0 |

Porte: Apache su `localhost:6000` (prod), `localhost:7001` (dev con Xdebug). PhpMyAdmin dev su `:8080`.

**URL locale del sito**: la docroot Apache è `/var/www/html/` con `./web` montato come sottocartella `asmsa`, quindi il sito si apre su **`http://localhost:7001/asmsa`** (dev, avvio con `./dev.sh up`) oppure `http://localhost:6000/asmsa` (prod, `make up`). La porta `9000` è quella *interna* di PHP-FPM e **non** è esposta sull'host: non usarla nel browser.

**Ripristino DB locale**: metti il dump in `mariadb-init/` (montata in `/docker-entrypoint-initdb.d/`). L'import automatico avviene solo su volume MariaDB vuoto; se il volume esiste già, reimporta a mano: `docker exec asmsa_dog_mariadb sh -c 'mysql -udrupal -pdrupal drupal < /docker-entrypoint-initdb.d/<dump>.sql'` e poi `make drush "cr"`.

## Convenzioni di sviluppo

- Standard di codifica Drupal 10: dependency injection, configuration schema, Entity API
- Evitare query SQL dirette: usare Entity API e Views
- Plugin CKEditor 5: creare in `js/ckeditor5_plugins/{nome}/src/`, entry point `index.js`, estendere `Plugin`
- La configurazione Drupal è in `web/sites/default/config/` (gestita con `drush cim`/`cex`)
- I dati Omeka devono passare per la cache (`cache.omeka` bin), mai chiamate API live nei template

## Refactoring in corso

Vedi `refactory_dog.md` per lo stato:
1. Bypass di `omeka_utils` per `omeka_map` (completato)
2. Bypass di `omeka_utils` per `omeka_map_timeline` (da fare)
3. Fix JS visualizzazione mappa
4. Verifica funzionamento Layout Builder + selezione oggetti Omeka
5. Crawler batch per pre-caricamento cache
