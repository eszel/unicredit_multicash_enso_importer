# UniCredit MultiCash ENSO Importer

## Cél

Az ügyfél a UniCredit Bankból érkező utalási tranzakciókat az ERP rendszerében szeretné látni.
Ehhez a MultiCash 3.22 által előállított kimeneti fájlokat egy egyszerű, PHP 7.4 alapú, néhány fájlból álló rendszer dolgozza fel, és továbbítja az ENSO CLOUD API felé.

## Kiindulási pontok

- A feldolgozandó MultiCash fájlokra példák a [`_minta`](/Users/home/Sites/unicredit_multicash_enso_importer/_minta) alkönyvtárban találhatók.
- A jelenlegi minták `.VMK` kiterjesztésű fájlok: `BWMDA000.VMK`, `BWMDA010.VMK`, `BWMDA020.VMK`, `BWMDA040.VMK`.
- Az ENSO API leírására az `ensocloud` projekt gyökerében található `enso-api.yaml` fájl szolgál.
- A megoldás futtatási környezete Windows alapú gép, ahol a PHP szkriptet feladatütemezetten, 1 percenként indítjuk el.

## Feldolgozási elvárások

1. A rendszer egy előre beállított könyvtárat figyel a megfelelő kiterjesztésű új fájlokra.
2. Ha talál ilyen fájlt, azt azonnal át kell mozgatnia egy `_enso_imported` almappába.
3. A feldolgozás már ebből a `_enso_imported` könyvtárból induljon el.
4. Ez azért szükséges, hogy ha a feldolgozás 1 percnél tovább fut, a következő ütemezett indítás se ugyanazt a bejövő fájlt próbálja újra felvenni.
5. A rendszernek érdemes több beérkezett fájlt is tudnia kezelni.
6. Egy futás során a talált fájlokat sorban dolgozza fel.
7. Minden fájlt még a feldolgozás előtt azonnal át kell mozgatni `_enso_imported` alá.
8. Ha bármelyik fájl feldolgozása hibára fut, a hibás fájl kerüljön `_enso_error` alá, majd az importáló álljon le.

## Megvalósítási alapelvek

- A rendszer maradjon egyszerű, könnyen telepíthető, PHP 7.4 kompatibilis, kevés fájlból álló megoldás.
- Ne legyen szükség folyamatos daemon futtatására vagy külön queue komponensre.
- A feldolgozás legyen idempotens a gyakorlatban: ugyanaz a bejövő fájl ne kerüljön véletlenül többször feldolgozásra ugyanabból a figyelt könyvtárból.
- A naplózás legyen emberileg olvasható, mert várhatóan üzemeltető vagy fejlesztő fogja ellenőrizni, nem külön monitorozó rendszer.

## Feldolgozandó fájlok értelmezése

- A minták SWIFT MT940 jellegű kivonatblokkokat tartalmaznak.
- A `:20:` sor a blokk azonosítója.
- A `:25:` sor a számlaszámot tartalmazza.
- A `:61:` sor egy tranzakció fő sora, benne dátum, terhelés vagy jóváírás jel, összeg és referencia.
- A `:86:` sor és az utána következő `?20`, `?21`, `?27`, `?30`, `?31`, `?32` mezők a közleményt, partneradatokat és további részleteket hordozzák.
- A karakterkódolás a minták alapján nem megbízható UTF-8, ezért a beolvasásnál konverzióval és hibatűréssel kell számolni.

## Tervezett architektúra

### 1. Belépési pont

- Egyetlen CLI-ben futó PHP szkript indul a Windows feladatütemezőből percenként.
- Példa hívás: `php import.php`
- A szkript egy futás alatt a talált fájlokat sorban dolgozza fel.

### 2. Konfiguráció

- Egyszerű `config.php` vagy `config.local.php` alapú konfigurációs fájl.
- Minimális beállítások:
  - figyelt bemeneti könyvtár
  - engedélyezett kiterjesztések
  - `_enso_imported` célkönyvtár
  - `_enso_error` könyvtár hibás fájloknak
  - ENSO API base URL
  - ENSO API hitelesítési adatok
  - kérés timeout és retry paraméterek
  - log fájl útvonala

### 3. Fájlfelderítés

- A rendszer a bemeneti könyvtárban a megadott kiterjesztésű fájlokat listázza.
- A találatokat determinisztikusan rendezett sorrendben dolgozza fel.
- Javasolt rendezés: módosítási idő szerint növekvően, azon belül fájlnév szerint.
- Ha nincs találat, a program csendben vagy rövid logbejegyzéssel kilép.

### 4. Azonnali áthelyezés

- A kiválasztott fájlt a rendszer azonnal áthelyezi a `_enso_imported` almappába.
- Az áthelyezés legyen lehetőleg atomi `rename` művelet ugyanazon a köteten belül.
- Ha a célfájl már létezik, egyértelmű névütközés-kezelés szükséges.
- Javasolt stratégia: időbélyeges vagy sorszámos új név képzése.

### 5. Parse és normalizálás

- A parser blokk-szinten dolgozza fel a fájlt.
- Egy blokk több tranzakciót is tartalmazhat.
- Minden tranzakcióból egységes belső adatszerkezet készül.
- Javasolt mezők:
  - forrásfájl neve
  - blokkazonosító
  - bankszámla
  - könyvelési dátum
  - értéknap, ha kinyerhető
  - irány: `credit` vagy `debit`
  - összeg
  - pénznem, ha a forrásból kinyerhető
  - banki referencia
  - közlemény összefűzve
  - partner név
  - partner számlaszám
  - nyers `:61:` és `:86:` tartalom audit célra

### 6. ENSO API integráció

- A normalizált tranzakciókból API-kompatibilis payload készül.
- A tényleges endpoint és payload szerkezet az `enso-api.yaml` alapján véglegesíthető.
- A kommunikációhoz PHP 7.4 alatt `curl` alapú egyszerű HTTP kliens elegendő.
- Minden futásban egy teljes fájl teljes tartalma megy egyetlen ENSO API kérésben.
- A feldolgozás technikai sikerének feltétele az ENSO API sikeres HTTP válasza.

### 7. Naplózás és visszakövethetőség

- Minden futásról készüljön log.
- Minimális log események:
  - indulás
  - talált fájl vagy nincs fájl
  - áthelyezés eredménye
  - parse-olt tranzakciók száma
  - ENSO API hívás eredménye
  - hiba oka
- A log tartalmazza a forrásfájl nevét és a feldolgozás időpontját.

## Javasolt minimális fájlszerkezet

- `import.php`
  - belépési pont, ütemezetten ezt hívjuk
- `config.php`
  - alap konfiguráció
- `src/FileFinder.php`
  - feldolgozandó fájlok kiválasztása és áthelyezése
- `src/MultiCashParser.php`
  - `.VMK` fájl olvasása és tranzakciók kinyerése
- `src/EnsoApiClient.php`
  - ENSO API hívások
- `src/Logger.php`
  - egyszerű fájlos logolás
- `src/ErrorGuard.php`
  - ellenőrzi, hogy az `_enso_error` könyvtár üres-e induláskor
- `src/Importer.php`
  - teljes folyamat vezérlése

Ha tényleg a lehető legkevesebb fájl a cél, akkor ez tovább egyszerűsíthető 3-4 fájlra is, de a fenti bontás még mindig kicsi és karbantartható.

## Feldolgozási folyamat

1. Indul a `php import.php`.
2. Betölti a konfigurációt.
3. Ellenőrzi, hogy az `_enso_error` könyvtár üres-e.
4. Ha nem üres, logol és hibával kilép.
5. Megkeresi az összes megfelelő fájlt a figyelt könyvtárban.
6. A fájlokat determinisztikus sorrendben, egyenként veszi fel.
7. Az aktuális fájlt azonnal áthelyezi a `_enso_imported` könyvtárba.
8. Beolvassa az áthelyezett fájlt, karakterkódolást normalizál.
9. Blokkokra és tranzakciókra bontja.
10. A teljes fájl tartalmát egy közös ENSO API payload formára alakítja.
11. Meghívja az ENSO API-t egyetlen HTTP kéréssel.
12. HTTP siker esetén logol, majd továbblép a következő fájlra.
13. Parse vagy API hiba esetén logol, a fájlt áthelyezi az `_enso_error` könyvtárba, majd hibával kilép.
14. Ha minden talált fájl sikeresen lefutott, a program kilép.

## Hibakezelési stratégia

- A futás előfeltétele, hogy az `_enso_error` könyvtár üres legyen.
- Ha az `_enso_error` könyvtár nem üres, az importáló azonnal álljon le, és ne vegyen fel új fájlt.
- Ha a fájl nem mozgatható át, a futás hibával álljon meg.
- Ha a fájl parse-olhatatlan, kerüljön át az `_enso_error` könyvtárba.
- Ha az ENSO API nem elérhető vagy hibát ad, a fájl kerüljön át az `_enso_error` könyvtárba.
- Ha a hibás fájl nem mozgatható át az `_enso_error` könyvtárba, a futás hibával álljon meg.
- A hibák kezelése szándékosan blokkoló jellegű: amíg van feldolgozatlan hibás fájl az `_enso_error` könyvtárban, új import nem indulhat.

## Ismételt futások és duplikáció kezelése

- Mivel a fájl átvétele már a futás elején megtörténik, ugyanaz a bemeneti fájl nem kerül újra kiválasztásra a figyelt könyvtárból.
- Külön lokális duplikációvédelem nem szükséges.
- A duplikációvédelem ENSO CLOUD oldalon történik.
- Ettől függetlenül hasznos lehet a payloadban forrásazonosítók átadása audit és visszakereshetőség céljára.

## Megvalósítási terv

### 1. fázis: technikai alap

- projekt váz létrehozása
- konfigurációs fájl kialakítása
- CLI belépési pont elkészítése
- könyvtárfigyelés helyett egyszeri polling logika megírása

### 2. fázis: fájlkezelés

- feldolgozandó fájlok listázása
- azonnali áthelyezés `_enso_imported` alá fájlonként
- névütközés és hibás áthelyezés kezelése

### 3. fázis: parser

- `.VMK` fájlolvasás
- blokkok felismerése
- `:61:` és `:86:` tranzakciós egységek összekapcsolása
- karakterkódolási normalizálás
- belső DTO vagy tömbstruktúra kialakítása

### 4. fázis: ENSO integráció

- `enso-api.yaml` alapján endpoint kiválasztása
- teljes fájlra vonatkozó request payload leképezés
- hitelesítés beépítése
- hibatűrő HTTP hívások

### 5. fázis: üzemi stabilitás

- részletes logolás
- `_enso_error` előellenőrzés beépítése
- hibás fájlok `_enso_error` könyvtárba mozgatása
- kézi újrafeldolgozás szabályainak rögzítése
- mintafájlokon végzett ellenőrzés

## Tesztelési terv

- mintafájlokkal parser teszt
- üres könyvtár eset teszt
- 1 darab feldolgozható fájl eset teszt
- több feldolgozható fájl soros feldolgozása
- több fájl eset közbenső hibánál leállás
- hibás kiterjesztésű fájl figyelmen kívül hagyása
- nem üres `_enso_error` könyvtár eset azonnali leállás
- ENSO API hiba eset a fájl `_enso_error` könyvtárba mozgatása
- duplikált vagy névütköző célfájl kezelése
- 1 percnél hosszabb futás szimulációja logikai szinten

## Üzemeltetési szabályok

- A feladatütemező csak a `php import.php` parancsot hívja.
- A futtató felhasználónak írási joga kell legyen a figyelt könyvtárra, az `_enso_imported` könyvtárra és a log könyvtárra.
- A futtató felhasználónak írási joga kell legyen az `_enso_error` könyvtárra is.
- A `_enso_imported` könyvtár nem ideiglenes mappa, hanem az auditálhatóság része.
- Az `_enso_error` könyvtár kiürítése vagy rendezése operátori feladat, enélkül új import nem indulhat.
- A kézi újrafeldolgozás csak tudatos operátori lépés legyen.

## Nyitott döntések

Jelenleg nincs nyitott üzleti döntés, a terv implementálásra kész.

## Javasolt első implementációs döntések

Ha nincs eltérő üzleti igény, érdemes az alábbi konzervatív alapértelmezésekkel indulni:

- csak `.VMK` fájlokat dolgozunk fel
- egy futás alatt az összes talált fájlt sorban dolgozzuk fel
- a fájl azonnal átkerül `_enso_imported` alá
- a teljes fájlt egy kérésben küldjük az ENSO API-ba
- HTTP siker válasz esetén a feldolgozást sikeresnek tekintjük
- sikertelen parse vagy API hívásnál a fájl `_enso_error` könyvtárba kerül
- ha az `_enso_error` könyvtár nem üres, a futás nem indul el
- a pénznemet a forrásfájlból próbáljuk meghatározni, ha az rendelkezésre áll
- a duplikációvédelmet az ENSO CLOUD oldalára bízzuk
