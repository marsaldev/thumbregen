# thumbregen

[![CI](https://github.com/marsaldev/thumbregen/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/marsaldev/thumbregen/actions/workflows/ci.yml)
[![Latest release](https://img.shields.io/github/v/release/marsaldev/thumbregen)](https://github.com/marsaldev/thumbregen/releases/latest)
[![PrestaShop](https://img.shields.io/badge/PrestaShop-8.0%20%7C%208.1%20%7C%208.2%20%7C%209.0-DF0067)](#compatibilità)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.2.5-777BB4?logo=php&logoColor=white)](#compatibilità)
[![License](https://img.shields.io/github/license/marsaldev/thumbregen)](LICENSE)
[![Downloads](https://img.shields.io/github/downloads/marsaldev/thumbregen/total)](https://github.com/marsaldev/thumbregen/releases)

*English version: [README.md](README.md)*

Modulo PrestaShop **senza interfaccia di back office** che aggiunge due comandi Symfony Console per rigenerare i thumbnail delle immagini prodotto in modo selettivo (per prodotto, per categoria, per tipo di immagine), pensato per cataloghi grandi e per essere lanciato da cron.

- PrestaShop **da 8.0 a 9.0**, PHP **>= 7.2.5** (vedi [Compatibilità](#compatibilità))
- Nessun hook, nessuna tabella, nessuna configurazione: l'installazione registra solo i comandi.
- Le immagini originali non vengono mai modificate: si rigenerano solo i derivati.

## Compatibilità

| PrestaShop | PHP supportato da PrestaShop | Formati dei thumbnail generati | Testato in CI con |
|---|---|---|---|
| 8.0.x | 7.2 – 8.1 | jpg | ✅ PHP 7.2, 8.1 |
| 8.1.x | 7.2 – 8.1 | jpg + webp/avif/png abilitati in *Design > Impostazioni immagini* (richiede la feature flag *Multiple image formats*) | ✅ PHP 7.2, 8.1 |
| 8.2.x | 7.2 – 8.1 | come 8.1 | ✅ PHP 7.2, 8.1 |
| 9.0.x | 8.1 – 8.4 | jpg + webp/avif/png abilitati in *Design > Impostazioni immagini* | ✅ PHP 8.1, 8.4 |
| 1.7.x e precedenti | | | ❌ non supportato |

A ogni push, e una volta a settimana, la CI installa in Docker ciascuna versione di PrestaShop della tabella con il catalogo demo ([immagini ufficiali `prestashop/prestashop`](https://hub.docker.com/r/prestashop/prestashop)), installa il modulo e ci lancia i comandi ([`tests/integration/run.sh`](tests/integration/run.sh)). I test coprono:
- rigenerazione per prodotto, per categoria con sottocategorie e su tutto il catalogo (numero di prodotti confrontato con il database);
- `--type`, `--delete-old` e `--dry-run`, più la generazione dei webp da 8.1 in poi;
- argomenti non validi (exit `1`), originali corrotti (saltati, exit `0`) e cartelle immagini non scrivibili (exit `2`).

In più, i file PHP passano il lint su tutte le versioni di PHP dalla 7.2 alla 8.4.

Per lanciare i test di integrazione in locale (serve Docker):

```bash
tests/integration/run.sh 8.2-8.1    # qualsiasi tag dell'immagine prestashop/prestashop, <PrestaShop>-<PHP>
```

## Installazione

1. Scaricare `thumbregen-X.Y.Z.zip` dalle [release su GitHub](https://github.com/marsaldev/thumbregen/releases) ed estrarlo in `modules/`, oppure caricarlo dal back office (*Moduli > Gestione moduli > Carica un modulo*). Lo zip della release include già `vendor/autoload.php`, quindi nessun `composer install` sul server.
   Partendo invece da un clone git, lanciare prima `composer dump-autoload -o` nella cartella del modulo: `vendor/` non è versionata.
2. Installare il modulo dal back office oppure da CLI:
   ```bash
   php bin/console prestashop:module install thumbregen
   ```
3. **Svuotare la cache Symfony**, altrimenti i comandi non compaiono:
   ```bash
   php bin/console cache:clear --no-warmup            # ambiente dev (default della console)
   php bin/console cache:clear --no-warmup --env=prod # se si lancia il comando con --env=prod
   ```
4. Verificare:
   ```bash
   php bin/console list thumbregen
   ```

Lo stesso `cache:clear` serve dopo ogni aggiornamento del modulo.

Per i run lunghi conviene usare `--env=prod --no-debug`: in ambiente dev il debug di Symfony consuma più memoria e trasforma i warning PHP in eccezioni (il modulo le intercetta comunque immagine per immagine).

## Comandi

### `thumbregen:products:list-types`

Elenca i tipi di immagine abilitati per i prodotti (nome, dimensioni, variante 2x se l'alta risoluzione è attiva) e i formati che verranno generati.

```bash
php bin/console thumbregen:products:list-types
```

### `thumbregen:products:regenerate`

```bash
php bin/console thumbregen:products:regenerate [opzioni]
```

| Opzione | Descrizione |
|---|---|
| `--product=ID` | Limita a un singolo prodotto |
| `--category=ID` | Limita ai prodotti associati alla categoria (`category_product`, quindi anche quelli con categoria predefinita diversa) |
| `--with-subcategories` | Con `--category`, include le sottocategorie (nested set `nleft`/`nright`). Va specificato esplicitamente: senza l'opzione le sottocategorie sono escluse |
| `--type=NOME` | Tipo di thumbnail (es. `home_default`). Ripetibile, accetta anche `--type=a,b`. Default: tutti i tipi prodotto |
| `--shop-id=ID` | Contesto shop in multistore. Default: `PS_SHOP_DEFAULT` |
| `--delete-old` | Cancella prima i thumbnail esistenti **dei soli tipi selezionati** (tutte le estensioni jpg/png/webp/avif e le varianti 2x). Default: sovrascrive |
| `--dry-run` | Elenca prodotti, immagini e file che verrebbero generati/cancellati, senza scrivere nulla (vale anche con `--delete-old`) |
| `--batch-size=N` | Prodotti per batch, con liberazione memoria tra un batch e l'altro. Default `50` |
| `--memory-limit=VAL` | `memory_limit` PHP del run. Default `1G` (`-1` = illimitato). Il solo bootstrap di PrestaShop + Symfony occupa già 50-110 MB, quindi non scendere sotto `256M` |

`--product` e `--category` sono mutuamente esclusivi; `--with-subcategories` richiede `--category`. Senza nessuno dei due si elabora tutto il catalogo.

#### Esempi

| Caso | Comando |
|---|---|
| Tutte le immagini di tutti i prodotti | `thumbregen:products:regenerate` |
| Tutte le immagini di un prodotto | `thumbregen:products:regenerate --product=42` |
| Tutte le immagini di una categoria | `thumbregen:products:regenerate --category=12` |
| Idem, con sottocategorie | `thumbregen:products:regenerate --category=12 --with-subcategories` |
| Un thumbnail per tutti i prodotti | `thumbregen:products:regenerate --type=home_default` |
| Un thumbnail per un prodotto | `thumbregen:products:regenerate --product=42 --type=home_default` |
| Un thumbnail per una categoria (± sottocategorie) | `thumbregen:products:regenerate --category=12 --with-subcategories --type=home_default` |
| Anteprima di cosa verrebbe fatto | aggiungere `--dry-run -v` a uno qualunque dei precedenti |

#### Cron

```cron
# Ogni notte alle 3:15: errori nel log, mail di cron solo se l'exit code non è 0
15 3 * * * cd /var/www/shop && php bin/console --env=prod --no-debug thumbregen:products:regenerate --type=home_default -q >> var/logs/thumbregen.log 2>&1 || echo "thumbregen exit $? - vedi var/logs/thumbregen.log"
```

cron non guarda l'exit code: invia la mail (a `MAILTO`) solo se il comando produce output. Con `>> … 2>&1` tutto finisce nel log, quindi senza il `|| echo` un run con errori sarebbe indistinguibile da uno riuscito. Con il `|| echo`, a run ok (`0`) non arriva nulla, con `1` o `2` arriva una mail con il codice e gli errori si leggono nel log.

## Output

- Progress bar sui prodotti (non in `--dry-run`, dove viene stampato l'elenco dei prodotti con le loro immagini).
- Riepilogo finale: prodotti, prodotti senza immagini, immagini, thumbnail generati, vecchi thumbnail cancellati, immagini saltate, warning, errori, riconnessioni DB, tempo totale, picco di memoria. Le immagini saltate e gli errori sono elencati (fino a 200 messaggi ciascuno).
- `-v`: una riga per immagine (in `--dry-run` anche i file previsti); `-vv`: ogni file generato/cancellato; `-q`: solo gli errori (su stderr).

### Exit code

| Codice | Significato |
|---|---|
| `0` | Tutto ok, anche con warning (es. immagini originali mancanti o corrotte, saltate) |
| `1` | Argomenti non validi o errore fatale (es. directory immagini non scrivibile, DB irraggiungibile) |
| `2` | Run completato, ma con errori su alcune immagini (es. resize fallito, permessi) |

Cosa porta a `2` e cosa resta un semplice warning (exit `0`):

| Errore (exit `2`) | Warning / saltata (exit `0`) |
|---|---|
| Resize fallito per un formato o una dimensione | Originale `{id_image}.jpg` mancante, vuoto o corrotto (immagine saltata) |
| Cartella dell'immagine non scrivibile | Modulo watermark che restituisce `false` |
| Vecchio thumbnail non cancellabile (`--delete-old`) | |
| Eccezione lanciata da un modulo watermark (thumbnail generato ma senza watermark) | |

Un errore fatale a metà run (es. DB non più raggiungibile dopo i tentativi di riconnessione) restituisce `1` anche se una parte dei prodotti è già stata elaborata: il riepilogo stampato mostra fin dove è arrivato. In `--dry-run` non si scrive nulla, quindi gli unici esiti possibili sono `0` e `1`.

## Comportamento tecnico

- **Limiti**: all'avvio `set_time_limit(0)` e `ini_set('memory_limit', …)`; se `ini_set` è disabilitata o rifiutata viene stampato un warning e il run prosegue.
- **Selezione**: paginazione per ID prodotto (keyset), mai l'intero catalogo in memoria. In multistore prodotti e immagini sono filtrati con `product_shop` / `image_shop` dello shop scelto.
- **Rigenerazione**: stesso percorso del back office (`AdminImagesController::_regenerateNewImages`): `ImageManager::resize()` sull'originale `{id_image}.jpg` con larghezza/altezza dell'`ImageType`, per ogni formato configurato e, se `PS_HIGHT_DPI` è attivo, anche la variante `2x`. Il formato reale dei `.jpg` resta deciso da `PS_IMAGE_QUALITY`, come nel core.
- **Differenze tra versioni** (isolate in `ThumbnailRegenerator::resolveGenerationFormats()`):
  - PS 8.0: solo `.jpg`.
  - PS 8.1 / 8.2: `jpg` più i formati abilitati in *Design > Impostazioni immagini* (webp, avif, png) tramite `ImageFormatConfiguration`, se la feature flag `multiple_image_format` è attiva.
  - PS 9.0: come 8.1 / 8.2, senza feature flag.
- **Watermark**: dopo la generazione, per ogni immagine si chiama `hookActionWatermark` dei moduli attivi agganciati a `actionWatermark` nello shop, passando in `image_type` solo i tipi selezionati (come fa il core). Il modulo `watermark` ufficiale applica il watermark ai tipi configurati nel suo pannello: se un tipo selezionato è tra quelli, il thumbnail viene sovrascritto con la versione watermarkata, come succede dal back office.
  **Attenzione**: il modulo `watermark` 2.0.0 non filtra davvero per `image_type` (il suo `array_intersect` su array di array combacia sempre), quindi a ogni immagine elaborata riscrive anche gli altri tipi configurati nel suo pannello (`{id}-{tipo}.jpg` e `{id}-{tipo}-{hash}.jpg`), con lo stesso risultato di prima. Il back office si comporta allo stesso modo. Il modulo watermark lavora solo sui `.jpg` (né webp/avif né 2x).
- **Errori per immagine**: originale mancante o corrotto ⇒ warning, immagine saltata, run prosegue. Resize fallito ⇒ errore, run prosegue. Warning PHP ed eccezioni di core/moduli terzi sono intercettati per singola immagine.
- **Risorse**: dopo ogni batch `Cache::clean('*')` e `gc_collect_cycles()`; nessun oggetto `Product` viene istanziato e gli oggetti `Image` sono costruiti senza query e rilasciati subito.
- **Connessione DB**: la connessione si verifica all'avvio e prima di ogni batch. Su "MySQL server has gone away" / "Lost connection" il modulo si riconnette (fino a 3 tentativi, con backoff) e ritenta la query. Se la connessione cade dentro codice di core o di moduli terzi (es. il watermark che istanzia `Image`), anche quella chiamata viene ritentata una volta dopo la riconnessione.
- **Nessuna scrittura**: il modulo non scrive nel database né nei file di configurazione e non tocca le immagini originali.

## Struttura

```
thumbregen/
├── thumbregen.php                 # classe modulo (solo install/uninstall)
├── composer.json                  # autoload PSR-4 Marsaldev\Module\ThumbRegen\ => src/
├── config/services.yml            # servizi + comandi (tag console.command)
├── vendor/                        # autoload generato (composer dump-autoload -o), non versionato, incluso nello zip della release
└── src/
    ├── Command/
    │   ├── RegenerateProductThumbnailsCommand.php
    │   └── ListImageTypesCommand.php
    ├── Model/
    │   ├── ImageResult.php        # esito di una singola immagine
    │   ├── RunReport.php          # contatori e messaggi del run
    │   └── SelectionCriteria.php  # prodotto / categoria (± sottocategorie) / catalogo
    └── Service/
        ├── DatabaseClient.php     # query read-only con riconnessione
        ├── ImageTypeProvider.php  # tipi immagine prodotto e validazione --type
        ├── ProductSelector.php    # selezione prodotti e immagini per batch
        └── ThumbnailRegenerator.php
```

Dopo aver modificato `composer.json` o aggiunto classi: `composer dump-autoload -o` nella cartella del modulo.
