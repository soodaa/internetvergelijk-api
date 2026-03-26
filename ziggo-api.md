# Ziggo RFSCOM API v2.0 – Developer Handleiding

> Bestand: `ziggo-api.md`  
> Laatste update: v2.0

Deze handleiding beschrijft de **RFSCOM API** van Ziggo (VodafoneZiggo). De API levert informatie over adressen, netwerkbeschikbaarheid (COAX/FTTH), maximale snelheden en servicestatus voor sales- en vergelijkingsplatforms. De API is RESTful en retourneert JSON.

---

## Inhoud
1. [Overzicht](#overzicht)
2. [Omgevingen & Base URLs](#omgevingen--base-urls)
3. [API Endpoints](#api-endpoints)
   - [Footprint](#footprint)
   - [Availability](#availability)
   - [Address](#address)
   - [Health](#health)
4. [Responsevelden](#responsevelden)
5. [Voorbeelden](#voorbeelden)
6. [Integratie-checklist](#integratie-checklist)
7. [Release Notes v2.0 (samenvatting)](#release-notes-v20-samenvatting)

---

## Overzicht

Belangrijkste use-cases:
- Controleren of een adres binnen Ziggo’s footprint valt
- Bepalen van beschikbare technologie: `COAX` (HFC) of `FTTH`
- Ophalen van maximale up-/downloadsnelheden
- Controleren van online-verkoopbaarheid van een adres

Authenticatie verloopt via een (interne) API Gateway token. Voorbeeld-aanroepen hieronder zijn illustratief.

---

## Omgevingen & Base URLs

**Development (DEV)**
```
https://api.dev.aws.ziggo.io/v2/api/rfscom/v2/footprint
https://api.dev.aws.ziggo.io/v2/api/rfscom/v2/availability
https://api.dev.aws.ziggo.io/v2/api/rfscom/v2/address
https://api.dev.aws.ziggo.io/v2/api/rfscom/v2/health
```

**Production (PROD)**
```
https://api.prod.aws.ziggo.io/v2/api/rfscom/v2/footprint
https://api.prod.aws.ziggo.io/v2/api/rfscom/v2/availability
https://api.prod.aws.ziggo.io/v2/api/rfscom/v2/address
https://api.prod.aws.ziggo.io/v2/api/rfscom/v2/health
```

---

## API Endpoints

### Footprint
**Doel:** check of een adres binnen Ziggo-gebied valt en welk verbindingstype wordt gebruikt.  
**Methode:** `GET`  
**Query (voorbeeld):**
```
/footprint?postalCode=2725DN&houseNumber=27
```
**Resultaat (indicatie):**
- `inFootprint: boolean`
- `connectionType: COAX | FTTH`

---

### Availability
**Doel:** detailstatus voor verkoopbaarheid, technologie en snelheid.  
**Methode:** `GET`  
**Query (voorbeeld):**
```
/availability?postalCode=2725DN&houseNumber=27
```
**Resultaat:** Zie [Responsevelden](#responsevelden).

---

### Address
**Doel:** validatie/adresverrijking (o.a. Ziggo-ID’s).  
**Methode:** `GET`  
**Query (voorbeeld):**
```
/address?postalCode=2725DN&houseNumber=27
```
**Resultaat:** gestructureerde adresinfo met Ziggo-identifiers.

---

### Health
**Doel:** basis healthcheck van de RFSCOM-dienst.  
**Methode:** `GET`  
**Voorbeeld-antwoord:**
```json
{ "status": "OK", "timestamp": "2025-10-20T13:00:00Z" }
```

---

## Responsevelden

| Field | Type | Example | Required for Online Sales? |
|------|------|---------|-----------------------------|
| `ID` | String | `6221KX300A01` | **Yes** |
| `PAID` | String | `PAID-180.096.880` | No |
| `NETWORKTECHNOLOGY` | String | `HFC` | No |
| `LINESTATUS` | String | `INSERVICE` | No |
| `CONNECTIONTYPE` | String | `COAX` or `FTTH` | **Yes** |
| `MAXNETWORKUPLOADSPEED` | Number | `0.1` | **Yes** *(0.1 = 100 Mbit/s)* |
| `MAXNETWORKDOWNLOADSPEED` | Number | `1` | **Yes** *(1 = 1000 Mbit/s)* |
| `IS_CATV_AVAILABLE` | Boolean | `true` | No |
| `IS_DTV_AVAILABLE` | Boolean | `true` | No |
| `IS_VOIP_AVAILABLE` | Boolean | `true` | No |
| `IS_INTERNET_AVAILABLE` | Boolean | `true` | **Yes** |

**Opmerking snelheden**  
De waarden voor `MAXNETWORK*` zijn in **Gbit/s** uitgedrukt (bijv. `1` = **1000 Mbit/s**, `0.1` = **100 Mbit/s**).

---

## Voorbeelden

### cURL – availability
```bash
curl -X GET "https://api.prod.aws.ziggo.io/v2/api/rfscom/v2/availability?postalCode=2725DN&houseNumber=27"   -H "Authorization: Bearer <token>"
```

**Response (voorbeeld):**
```json
{
  "id": "6221KX300A01",
  "connectionType": "COAX",
  "networkTechnology": "HFC",
  "maxNetworkDownloadSpeed": 1,
  "maxNetworkUploadSpeed": 0.1,
  "isInternetAvailable": true,
  "lineStatus": "INSERVICE"
}
```

### cURL – health
```bash
curl -X GET "https://api.prod.aws.ziggo.io/v2/api/rfscom/v2/health"
```

---

## Integratie-checklist

- [ ] **Inputvalidatie:** postcode + huisnummer normaliseren (casus, spaties) vóór verzoek.  
- [ ] **Timeouts & retries:** stel client timeouts in en implementeer beperkte retries met jitter.  
- [ ] **Rate limiting:** behandel 429 met backoff; cache succesvolle resultaten per adres.  
- [ ] **Mapping:** vertaal `connectionType`/`networkTechnology` intern naar je productlogica.  
- [ ] **Snelheidsweergave:** toon `maxNetwork*` als Mbit/s (×1000).  
- [ ] **Sales-gating:** valideer verplichte velden (zie tabel) vóór je online-verkoopstap.  
- [ ] **Monitoring:** log `lineStatus` en afwijkende combinaties (bijv. `INSERVICE` maar `isInternetAvailable = false`).  
- [ ] **Healthcheck:** integreer `/health` in je uptime-monitoring.  

---

## Release Notes v2.0 (samenvatting)

Het meegeleverde v2.0-release document bevat puntsgewijze wijzigingen en fixes. De bron-PDF is grotendeels grafisch/bullet-based; daarom is hieronder een beknopte, generieke samenvatting opgenomen. Raadpleeg het interne document voor volledige details.

- Diverse verbeteringen en verfijningen in RFSCOM v2.0
- Bugfixes en consistentie-verbeteringen tussen omgevingen
- Kleine tekstuele/formatting aanpassingen in responses

---

© 2025 VodafoneZiggo — RFSCOM API v2.0
