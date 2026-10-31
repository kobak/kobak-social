# kobak social — telepítés és beállítás

Jetpacktől független megoldás: publikáláskor automatikusan kiposztol
Bluesky-ra és Mastodonra.

## Újdonságok a 2.1-ben

- **A sikertelen megosztást el lehet tüntetni.** Eddig csak „Újrapróbálás" volt,
  ami nem mindig segít: ha egy poszt véglegesen nem ment ki, a figyelmeztetés
  örökre ott maradt. Most a figyelmeztetésen és a beállítóoldal listájában is
  van egy „Hide" link, plusz egy „Hide all". Az elrejtés csak a listáról veszi
  le — nem jelöli kiküldöttnek a bejegyzést, és a naplóban megmarad, mi történt.
- **Ha lejár a LinkedIn-token, a plugin megmondja.** A LinkedIn 401-es válaszát
  mostantól lejárt tokenként ismeri fel, és néven is nevezi, nem csak annyit ír,
  hogy nem ment ki a poszt.
- **Oda van írva, mit kell csinálni.** A token mezője alatt, az 50 napos
  figyelmeztetésben és a lejáratkor is ott a közvetlen link a LinkedIn
  token-generátorára, és hogy melyik három scope-ot kell bepipálni. A LinkedIn
  standard appoknak nem ad refresh tokent, tehát ezt tényleg kézzel kell
  kéthavonta megtenni — legalább ne kelljen hozzá emlékezni.
- A figyelmeztetés magától eltűnik, amint új, működő tokent mentesz, vagy
  lefuttatod a kapcsolat-ellenőrzést.

## Újdonságok a 2.0.1-ben

- **Amit nem állítottál be, arra nem panaszkodik.** Eddig a Bluesky és a
  Mastodon minden bejegyzésnél megpróbálkozott, akkor is, ha egy sor beállítás
  sem volt hozzá — így aki csak az egyiket használja, piros hibacsíkot kapott az
  adminban olyasmiért, amit nem is akart. Mostantól a be nem állított hálózat
  egyszerűen kimarad.
- **A félbehagyott beállításról viszont szól.** Ha beírtad a Bluesky handle-t,
  de az app jelszót még nem, azt továbbra is hibaként jelzi — mert ott tényleg
  hiányzik valami, nem pedig nem akarod használni.
- A korábban összegyűlt, nem használt hálózatokhoz tartozó hibabejegyzések
  maguktól kitakarítódnak.

## Újdonságok a 2.0-ban

- **Kiválaszthatod, mely bejegyzéstípusok menjenek ki.** A Beállítások lap
  tetején most már ott a lista a publikus típusokkal. Eddig a beállítás létezett
  a kódban, de nem lehetett hozzáférni, és mindig csak a bejegyzések mentek.
- **A reménytelen hibán nem rágódik negyedórát.** Ha hiányzik vagy rossz a
  token, azt azonnal kiírja a hibalistára, mert azon a várakozás nem segít. Az
  időtúllépést és a szerverhibát viszont ugyanúgy háromszor újrapróbálja, mint
  eddig.
- **Belül szétszedtük.** Egy hálózat mostantól egy fájl: a Bluesky, a Mastodon
  és a LinkedIn is külön osztály egy közös interfész mögött. Kívülről semmi nem
  változott, de egy negyedik hálózat hozzáadása már nem négy helyen való
  turkálás. Ha bővítenéd, három szűrő van hozzá: `kobak_social_platforms`,
  `kobak_social_message` és `kobak_social_syndication_urls`.

## Újdonságok az 1.4-ben

- **A tokenek nem látszanak többé a beállítóoldal forrásában.** Eddig minden
  jelszómező `value` attribútumában ott ült a mentett érték. Mostantól a mező
  üresen jelenik meg; ha üresen hagyod, marad a régi érték, a mellette lévő
  jelölőnégyzettel pedig törölhető.
- **Platformonkénti jelölés.** Ha a Bluesky sikerül, de a Mastodon elszáll, a
  bejegyzés eddig véglegesen félkészen maradt. Most külön jelölés kerül minden
  platformra, így az újrapróbálás csak a hiányzót küldi ki.
- **Automatikus újrapróbálás.** Hiba esetén 5, majd 10 perc múlva újra
  megpróbálja, összesen háromszor. Ha úgy sem megy, az adminban figyelmeztetés
  jelenik meg, a beállítóoldalon pedig egy „Újrapróbálás most" gomb.
- **A poszt nyelve az oldal nyelvéből jön**, nem fixen `hu`. A Beállítások →
  Üzenet szakaszban felül lehet bírálni.
- **A mentés nem törli a nem látszó beállításokat** (eddig az egész tömböt
  felülírta).
- **LinkedIn:** a kiemelt képnek a `large` változata megy fel az eredeti
  helyett, és a token mentése után 50 nappal szól, hogy hamarosan lejár.
- **A Mastodon-teszt tényleg kimegy másodszorra is.** Eddig az idempotencia
  kulcs miatt némán eldobta.

## Újdonságok az 1.1-ben

- **Szindikációs linkek.** A plugin eltárolja a Bluesky- és Mastodon-poszt
  URL-jét, és a bejegyzés alá kirak egy dobozt: „Ez a bejegyzés megjelent itt
  is: Bluesky · Mastodon". A linkek `u-syndication` jelölést kapnak — ebből
  tudja a Bridgy összepárosítani az ottani válaszokat a blogbejegyzéssel.
- **Helyi kommentelés kikapcsolása.** Elrejti a látogatói hozzászólás-űrlapot
  és elutasítja a közvetlen beküldést, **de a webmentiont nem bántja**: a
  Bridgy-n érkező lájkok, megosztások és válaszok továbbra is bejönnek és
  megjelennek a bejegyzés alatt. (A webmention a REST végponton érkezik, ott
  a plugin nem nyúl a komment-státuszhoz.)
- Mindkettő külön ki-be kapcsolható a beállításokban.

## 1. Telepítés

1. WP-admin → Bővítmények → Új bővítmény hozzáadása → **Bővítmény feltöltése**
2. Töltsd fel a `kobak-social.zip` fájlt → Telepítés → Aktiválás
3. Beállítások → **kobak social**

## 2. Bluesky app jelszó

Fontos: **ne** a rendes jelszavadat add meg, hanem app jelszót.

1. Bluesky → Settings → Privacy and security → **App passwords**
2. Add app password → nevezd el (pl. „kobak.org")
3. A kapott `xxxx-xxxx-xxxx-xxxx` kódot másold a plugin **App jelszó** mezőjébe
4. Handle: `kobak.bsky.social`

Ha később bármi gond van, ezt az egy app jelszót vissza tudod vonni anélkül,
hogy a fiókodhoz hozzá kellene nyúlni.

## 3. Mastodon access token

1. Mastodon (mastodon.social) → Preferences → **Development** → New application
2. Név: „kobak.org", jogosultság: elég a **`write:statuses`**
3. Submit → nyisd meg az alkalmazást → másold **a „Your access token"** értéket
4. Instance: `https://mastodon.social`

## 4. Bekapcsolás és teszt

1. Pipáld be az **Automatikus megosztás** jelölőt, Mentés
2. Kattints a **Teszt megosztás most** gombra — ez a legutóbbi megjelent
   bejegyzést posztolja ki. **Valódi poszt lesz belőle**, tehát látszani fog.
3. Nézd meg a **Naplót** a lap alján: platformonként kiírja, hogy OK vagy hiba,
   és a hiba szövegét is.

## Hogyan működik

- Csak **új** bejegyzés publikálásakor fut (draft/ütemezett → megjelent).
  Régi poszt szerkesztése **nem** posztol újra.
- Minden bejegyzésre csak egyszer megy ki (jelölést tesz rá).
- 30 másodperc késleltetéssel fut, hogy ne lassítsa a mentést.
- Bluesky-n 300, Mastodonon 500 karakter a limit. A kivonat automatikusan
  rövidül, a **cím és a link mindig megmarad**.
- A linkek Bluesky-n kattinthatók (facet).

## Szöveg sablon

Alapértelmezés:

```
{title}

{excerpt}

{url}
```

Változók: `{title}`, `{excerpt}`, `{url}`. Nyugodtan írd át, pl. ha
hashtageket akarsz a végére.

## Ami szándékosan kimaradt

**Facebook oldal és LinkedIn.** Mindkettő OAuth-alkalmazás regisztrációt
igényel (Meta for Developers, LinkedIn Developer), ami hetekig tartó
review-val járhat, és a tokenek 60 naponta lejárnak. A stratégia szerint
ezen a két helyen úgyis a **kézzel írt, személyes felvezetés** teljesít
jobban — 2 perc posztonként, cserébe sokkal nagyobb eléréssel.

Ha később mégis kell az automatizálás ide is, a Make.com RSS-alapú
megoldása a legegyszerűbb út.

---

## LinkedIn (1.2.0-tól)

A korábbi WP LinkedIn Auto Publish plugin azért nem működött, mert közvetlenül
`curl_exec()`-et hívott, azt viszont a tárhely letiltotta a `disable_functions`
listán. Ez a modul a WordPress saját `wp_remote_post()` hívását használja, ami
cURL hiányában automatikusan stream alapú átvitelre vált.

### Amit be kell állítani

1. **LinkedIn app**: developer.linkedin.com → Create app. Az apphoz hozzá kell
   kötni egy céges oldalt, de posztolni a személyes profilodra fogsz.
2. **Products**: kérd hozzá a *Share on LinkedIn* és a *Sign In with LinkedIn
   using OpenID Connect* terméket. Ezek automatikusan jóváhagyásra kerülnek.
3. **Jogosultságok**: `w_member_social`, `openid`, `profile`.
4. **Access token**: az app Auth fülén generálj tokent az OAuth folyamattal,
   és másold be a Beállítások → kobak social → LinkedIn mezőbe.
5. A **Szerző URN** mezőt hagyd üresen, az első megosztásnál a plugin lekéri
   a `/v2/userinfo` végpontról és beírja magától.

### Amire figyelni kell

- **A token kb. 60 nap után lejár.** Utána a napló `LinkedIn: ERROR - ... 401`
  bejegyzést fog mutatni, ilyenkor új tokent kell generálni. 1.4-től az admin
  50 nap után magától figyelmeztet.
- Az **API verzió** mező ÉÉÉÉHH formátumú (jelenleg `202607`). A LinkedIn
  nagyjából egy évig támogat egy verziót, azután itt kell átírni.
- A poszt **link-kártyaként** megy ki: a kiemelt kép feltöltődik az Images
  API-val, és a kártya képe lesz. Ha nincs kiemelt kép vagy a feltöltés
  hibázik, a poszt akkor is kimegy, csak kép nélkül.
- A LinkedIn „little" szövegformátuma miatt a foglalt karaktereket
  (`( ) [ ] { } @ # * _ ~ < > |`) a plugin automatikusan escapeli.
