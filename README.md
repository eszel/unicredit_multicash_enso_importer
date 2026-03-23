# UniCredit MultiCash ENSO Importer

Egyszeru PHP 7.4 CLI importalo, ami a MultiCash 3.22 kimeneti fajlokat feldolgozza, majd a normalizalt bejovo tetelsorokat az ENSO CLOUD `/bankkivonat-tetelek/bulk` vegpontra kuldi.

## Fobb mukodes

- a bemeneti konyvtarban a megadott kiterjesztesu fajlokat keresi
- indulaskor ellenorzi, hogy az `_enso_error` konyvtar ures-e
- a talalt fajlokat azonnal atmozgatja `_enso_imported` ala
- a fajlokat MT940/MultiCash logika szerint tranzakciokra bontja
- a normalizalt bankkivonat sorokat egyetlen bulk HTTP keressel kuldi az ENSO API-nak
- tetelenkent diagnosztikai logot ir a felismert, hianyzo es nyers mezokrol, valamint a kepzett API payloadrol
- hiba eseten a hibas fajlt `_enso_error` ala mozgatja, majd leall

## Hasznalat

```bash
php import.php
php import.php --dry-run
php import.php --limit=1
```

## Konfiguracio

Az alapbeallitasokat a [config.php](/Users/home/Sites/unicredit_multicash_enso_importer/config.php) tartalmazza.
Helyi felulirashoz opcionalisan letrehozhato egy `config.local.php`, ami rekurzivan felulirja az alap konfiguraciot.

Legfontosabb beallitasok:

- `importer.input_dir`
- `importer.imported_dir`
- `importer.error_dir`
- `importer.allowed_extensions`
- `enso.base_url`
- `enso.endpoint`
- `enso.bearer_token`
- `payload.forras_format`
- `payload.only_credit_transactions`
- `payload.default_currency`
- `payload.max_items_per_request`
- `logging.file`

## Megjegyzes

Az aktualis implementacio a YAML-ben leirt `BankkivonatTetelBulkInput` schemahoz igazodik. Alapertelmezes szerint csak a bejovo (`credit`) sorokat kuldi, mert a backend a nem bejovo teteleket hibaskent jeloli.
