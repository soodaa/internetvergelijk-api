# Internetvergelijk API - Complete Architecture Documentation

**Version:** 1.2  
**Last Updated:** 23 januari 2026  
**Status:** Production

---

## 📋 Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Provider System](#provider-system)
4. [API Endpoints](#api-endpoints)
5. [Database Schema](#database-schema)
6. [Queue System](#queue-system)
7. [Caching Strategy](#caching-strategy)
8. [Adding New Providers](#adding-new-providers)
9. [Deployment](#deployment)
10. [Troubleshooting](#troubleshooting)

---

## 🎯 Overview

**Internetvergelijk API** is een Laravel 7 applicatie die postcodecontroles uitvoert voor verschillende internet providers in Nederland. Het systeem:

- Checkt beschikbaarheid van internet per adres
- Bepaalt maximale download/upload snelheden
- Ondersteunt meerdere technologieën: DSL, Glasvezel, Kabel
- Cachet resultaten in database (12 uur TTL)
- Verwerkt requests asynchroon via queue workers

**Production URL:** https://api.internetvergelijk.nl

### Vereisten

| Component | Versie |
|-----------|--------|
| PHP | 8.1+ (server: 8.2) |
| Laravel | 7.x |
| MySQL | 5.7+ |

**Lokale PHP setup (macOS):**
```bash
brew install php@8.1
brew link php@8.1 --force
# Voeg toe aan ~/.zshrc:
export PATH="/opt/homebrew/opt/php@8.1/bin:$PATH"
```

---

## 🏗️ Architecture

### High-Level Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                     Client Application                        │
│              (Website / Mobile App / Partners)                │
└────────────────────────┬─────────────────────────────────────┘
                         │ HTTP Request
                         │ GET /speedCheck?postcode=...
                         ▼
┌──────────────────────────────────────────────────────────────┐
│                    Laravel API Server                         │
│                  (api.internetvergelijk.nl)                   │
│                                                               │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  PostcodeController                                  │    │
│  │  - getAllProviders() → Main endpoint                 │    │
│  │  - getProvider() → Single provider                   │    │
│  │  - check() → Queue dispatcher                        │    │
│  └───────────────────┬─────────────────────────────────┘    │
│                      │                                        │
│                      ▼                                        │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  Database (MySQL)                                    │    │
│  │  - Check if data exists (< 12 hours old)            │    │
│  │  - Return cached data OR dispatch jobs              │    │
│  └───────────────────┬─────────────────────────────────┘    │
│                      │                                        │
│                      ▼                                        │
│  ┌─────────────────────────────────────────────────────┐    │
│  │  Queue System (CheckProvider Jobs)                   │    │
│  │  - Asynchronous processing                           │    │
│  │  - One job per supplier per address                  │    │
│  └───────────────────┬─────────────────────────────────┘    │
└────────────────────┬─┴────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────┐
│                  Queue Workers (PHP Daemon)                   │
│  - Picks jobs from queue                                      │
│  - Loads Supplier model                                       │
│  - Routes to correct Provider Library class                   │
└────────────────────────┬─────────────────────────────────────┘
                         │
                         ▼
┌──────────────────────────────────────────────────────────────┐
│              Provider Library Classes                         │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐             │
│  │   KPN      │  │   Ziggo    │  │  Caiway    │  ...        │
│  │ Postcode   │  │ Postcode   │  │ Postcode   │             │
│  │   Check    │  │  CheckV2   │  │   Check    │             │
│  └─────┬──────┘  └─────┬──────┘  └─────┬──────┘             │
└────────┼───────────────┼───────────────┼────────────────────┘
         │               │               │
         ▼               ▼               ▼
┌──────────────────────────────────────────────────────────────┐
│              External Provider APIs                           │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐             │
│  │  KPN API   │  │ Ziggo V2   │  │ Caiway     │  ...        │
│  │            │  │    API     │  │    API     │             │
│  └────────────┘  └────────────┘  └────────────┘             │
└────────────────────────┬─────────────────────────────────────┘
                         │ Results
                         ▼
┌──────────────────────────────────────────────────────────────┐
│               Database (postcodes table)                      │
│  - Save results (kabel_max, adsl_max, glasvezel_max)         │
│  - Set updated_at timestamp                                   │
│  - Cache for 12 hours                                         │
└──────────────────────────────────────────────────────────────┘
```

### Request Flow

1. **Client** sends GET request to `/speedCheck?postcode=2728AA&nr=3`
2. **PostcodeController** receives request
3. **Database check**: Is data cached? (< 12 hours old)
   - YES → Return cached data immediately
   - NO → Continue to step 4
4. **Dispatch jobs**: Create CheckProvider job for each active supplier
5. **Queue workers** process jobs asynchronously
6. **Provider classes** call external APIs
7. **Database** saves results
8. **Client** polls or waits for results

---

## 🔌 Provider System

### Supported Providers (v2)

Alle actieve providers worden geconfigureerd in `config/providers.php`. Iedere entry specificeert een **driver** (kpn/ziggo/odido/etc.) en de bijbehorende V2-driver class die `fetchSpeeds()` retourneert.

| Provider Key | Driver | Klasse | Notities |
|--------------|--------|--------|----------|
| `KPN`, `KPN-all`, `KPN-pairbonded` | `kpn` | `App\Libraries\KPN` | Core KPN providers. `KPN-all` heeft `skip_fiber_filters: true` |
| `Glasdraad`, `Glaspoort`, `FiberFlevo`, `FiberNH`, `Digitale Stad`, `Fryslân Ring`, `Breedband Loosdrecht`, `Breedband Rivierenland`, `Glasvezel Edam-Volendam`, `GlaswebVenray`, `HSLnet`, `CAI Harderwijk`, `WeConnect Waalre` | `kpn` | `App\Libraries\KPN` | KPN third-party netwerken via `thirdparty_name` |
| `Caiway` | `caiway` | `App\Libraries\CaiwayNetworkDirect` | Gemeenschappelijke helper: `CaiwayPostcodeCheck` |
| `Ziggo` | `ziggo` | `App\Libraries\ZiggoPostcodeCheckV2` | Normalizeert 2 Gbit/s → 2000 Mbps |
| `T-Mobile-GOP`, `Odido-OPF` | `odido` | `App\Libraries\OdidoNetworkDirect` | Variant/caps uit config |
| `Jonaz` | `jonaz` | `App\Libraries\JonazNetworkDirect` | SOAP → JSON bridge, name: "Fiber Crew" |
| `L2Fiber`, `EFiber` | `glasnet` | `App\Libraries\GlasnetNetworkDirect` | Meerdere Glasnet-operators |
| `SKV`, `Open Dutch Fiber`, `Primevest`, `KNN`, `Citius Fiber Prinsenbeek`, `Fiberbuiten`, `Glasvezel De Wolden`, `Glasvezel Helmond`, `Glasvezel Noord`, `Glasvezel Pekela`, `Glasvezel Zuidenveld`, `Herman Gorterlaan`, `KempenGlas`, `MaasKantNet`, `Midden-BrabantGlas`, `RE-NET Hoogeveen`, `Rotterdams Glasvezel`, `Stille Wille` | `hgvt` | `App\Libraries\HgvtNetworkDirect` | HGVT labels via `project_tags` |
| `SKP` | `skp` | `App\Libraries\SkpNetworkDirect` | Lokale provider Pijnacker-Nootdorp, pre-filter op city via Glasnet |

> 📋 **Volledige lijst:** Zie `config/providers.php` voor alle 40+ providers met hun configuratie.

### KPN Glasvezel Filtering

De KPN library heeft filtering logica om glasvezel beschikbaarheid te bepalen:

#### Provider Varianten

| Provider | Config key | Filter actief? |
|----------|------------|----------------|
| KPN | `KPN` | ✅ Ja - CONSTRUCTION filter + third-party check |
| KPN-all | `KPN-all` | ❌ Nee (`skip_fiber_filters: true`) |

#### Filtering Logica

1. **CONSTRUCTION Filter**: Glasvezel wordt NIET getoond als `project_status === "CONSTRUCTION"` (netwerk in aanbouw)
2. **Third-party Netwerk Check**: Glasvezel op onbekende third-party netwerken wordt genegeerd + email alert

#### KPN API Velden

| Veld | Betekenis |
|------|-----------|
| `project_status` | `CONSTRUCTION` (in aanbouw), `MAINTENANCE` (actief), of leeg |
| `thirdparty_name` | Netwerk naam: "KPN Netwerk NL" (eigen) of third-party naam |
| `thirdparty_permission` | KPN staat wholesale toe op dit netwerk |
| `sp_diy_allowed` | Service providers mogen verkopen (`"1"` = ja, `"0"` = nee) |

#### Voorbeelden

| Adres | project_status | KPN output | KPN-all output |
|-------|----------------|------------|----------------|
| `5802BZ 23` | MAINTENANCE | ✅ glasvezel: 4000 | ✅ glasvezel: 4000 |
| `2725DP 26` | CONSTRUCTION | ❌ glasvezel: null | ✅ glasvezel: 4000 |

#### Configuratie `skip_fiber_filters`

```php
// config/providers.php
'KPN-all' => [
    'class' => \App\Libraries\KPN::class,
    'driver' => 'kpn',
    'name' => 'KPN-all',
    'skip_fiber_filters' => true,  // Skip CONSTRUCTION en third-party check
    // ...
],
```

### SKP Provider (Pijnacker-Nootdorp)

SKP is een lokale kabel- en glasvezelprovider in de gemeente Pijnacker-Nootdorp.

#### Werking

1. **City pre-filter**: Eerst wordt de plaatsnaam opgehaald via Glasnet API
2. **Whitelist check**: Als city in `['NOOTDORP', 'PIJNACKER', 'DELFGAUW']` → doorgaan
3. **SKP API call**: WordPress admin-ajax endpoint met nonce
4. **Parse connection type**: `glas` → 1000 Mbit, `coax` → 600 Mbit

#### Configuratie

```php
// config/providers.php
'SKP' => [
    'class' => \App\Libraries\SkpNetworkDirect::class,
    'driver' => 'skp',
    'name' => 'SKP',
    'cities' => ['NOOTDORP', 'PIJNACKER', 'DELFGAUW'],
    'nonce' => env('SKP_NONCE', 'f803c3e245'),
    'active' => true,
    'queue' => 'default',
    'cache_ttl' => env('SPEEDCHECK_CACHE_TTL', 43200),
],
```

#### SKP API Response

| Veld | Betekenis |
|------|-----------|
| `found` | Adres bestaat |
| `approved` | SKP kan hier leveren |
| `connection` | `glas` (1000 Mbit) of `coax` (600 Mbit) |

#### Environment Variables

| Variable | Default | Beschrijving |
|----------|---------|-------------|
| `SKP_NONCE` | `f803c3e245` | WordPress nonce voor API authenticatie |

➡️ Alle V1 providerklassen (`TmobileWBA`, `GlasnetL2fiber`, `PostcodeCheck`, …), oude Ziggo compare/test-ingangen en de bijbehorende `Supplier::$providerClasses` mapping zijn verwijderd.

### Driver Contract

V2-drivers leveren ruwe snelheden terug als array—geen database writes:

```php
public function fetchSpeeds(\stdClass $address, int $verbose = 0): array;

// optioneel:
public function fetchSpeedsAsync(\stdClass $address, int $verbose = 0): PromiseInterface;
```

Voorbeeld response:

```php
[
    'status' => 'success',
    'data' => [[
        'provider' => 'Ziggo',
        'download' => [
            'dsl' => null,
            'glasvezel' => null,
            'kabel' => 2000,
        ],
    ]],
    'meta' => [
        'duration_ms' => 185,
        'from_cache' => false,
    ],
];
```

Caching, logging en foutafhandeling gebeuren centraal in `PostcodeController::runConfiguredProvider()` en `dispatchProviderAsync()`.

```sql
INSERT INTO suppliers (name, is_active, max_download) 
VALUES ('ProviderName', 1, 2000);
```

---

## 🌐 API Endpoints

### 1. speedCheck - Get All Providers

**Endpoint:** `GET /speedCheck`

**Parameters:**
- `postcode` (required) - Dutch postcode (e.g., "2728AA")
- `nr` (required) - House number (e.g., "3")
- `nr_add` (optional) - House extension (e.g., "A", "bis")
- `api_token` (required) - Authentication token

**Example:**
```
GET https://api.internetvergelijk.nl/speedCheck?postcode=2728AA&nr=3&api_token=YOUR_TOKEN
```

**Response:**
```json
{
  "kpn": 175,
  "kabel": 2000,
  "glasvezel": 1000,
  "providers": [
    {
      "provider": "KPN",
      "download": {
        "dsl": 175,
        "glasvezel": null,
        "kabel": null
      }
    },
    {
      "provider": "Ziggo",
      "download": {
        "dsl": null,
        "glasvezel": null,
        "kabel": 2000
      }
    }
  ]
}
```

**Controller:** `PostcodeController@getAllProviders`

---

### 2. getProvider - Single Provider Check

**Endpoint:** `GET /getProvider`

**Parameters:**
- `postcode` (required)
- `nr` (required)
- `nr_add` (optional)
- `provider` (required) - Provider name (e.g., "Ziggo")
- `api_token` (required)

**Example:**
```
GET https://api.internetvergelijk.nl/getProvider?postcode=2728AA&nr=3&provider=Ziggo&api_token=YOUR_TOKEN
```

**Response:**
```json
{
  "postcode": "2728AA",
  "huisnummer": "3",
  "toevoeging": null,
  "provider": "Ziggo",
  "download": {
    "dsl": null,
    "glasvezel": null,
    "kabel": 2000
  }
}
```

**Controller:** `PostcodeController@getProvider`

---

## 💾 Database Schema

### Tables

#### 1. `suppliers`
Stores provider information.

```sql
CREATE TABLE suppliers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    logo VARCHAR(255),
    access_token TEXT,
    client_id VARCHAR(255),
    client_secret VARCHAR(255),
    max_download INT DEFAULT 0,      -- Maximum possible speed
    is_active TINYINT(1) DEFAULT 1,  -- Enable/disable provider
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

**Important Fields:**
- `name` - Moet overeenkomen met de sleutel in `config/providers.php`
- `max_download` - Hard cap for speed (e.g., 2000 for Ziggo)
- `is_active` - 0 = disabled, 1 = enabled

---

#### 2. `postcodes`
Caches postcode check results.

```sql
CREATE TABLE postcodes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    postcode VARCHAR(10) NOT NULL,
    house_number INT NOT NULL,
    house_nr_add VARCHAR(10),
    supplier_id INT NOT NULL,
    adsl_max INT DEFAULT 0,          -- DSL speed (Mbps)
    glasvezel_max INT DEFAULT 0,     -- Fiber speed (Mbps)
    kabel_max INT DEFAULT 0,         -- Cable speed (Mbps)
    max_download INT DEFAULT 0,      -- Highest of all technologies
    created_at TIMESTAMP,
    updated_at TIMESTAMP,            -- Used for cache expiry
    
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
    INDEX idx_lookup (postcode, house_number, house_nr_add, supplier_id)
);
```

**Cache Logic:**
- Results cached for **12 hours**
- Check: `updated_at > NOW() - INTERVAL 12 HOUR`
- After 12 hours, new API call is made

---

#### 3. `jobs`
Queue jobs table.

```sql
CREATE TABLE jobs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    queue VARCHAR(255) NOT NULL,
    payload LONGTEXT NOT NULL,       -- Serialized job data
    attempts TINYINT UNSIGNED NOT NULL,
    reserved_at INT UNSIGNED,
    available_at INT UNSIGNED NOT NULL,
    created_at INT UNSIGNED NOT NULL,
    
    INDEX idx_queue (queue)
);
```

---

## ⚙️ Queue System

### Queue Workers

**Start worker:**
```bash
cd /var/www/vhosts/internetvergelijk.nl/api
/opt/plesk/php/8.2/bin/php artisan queue:work --daemon &
```

**Check workers:**
```bash
ps aux | grep "queue:work"
```

**Restart workers:**
```bash
# Kill existing
kill -9 [PID]

# Start new
/opt/plesk/php/8.2/bin/php artisan queue:work --daemon &
```

### CheckProvider Job

**File:** `app/Jobs/CheckProvider.php`

```php
class CheckProvider implements ShouldQueue
{
    public $supplier;
    public $address;
    public $postcodeId;

    public function handle()
    {
        // 1. Load supplier and postcode
        // 2. Get provider class
        // 3. Call provider->request()
        // 4. Save results
    }
}
```

**Dispatching:**
```php
CheckProvider::dispatch($supplier, $address, $postcode->id)
    ->onQueue('default');
```

---

## 🗄️ Caching Strategy

### Database Cache (12 hours)

```php
// Check if fresh data exists
$postcode = Postcode::where('postcode', $pc)
    ->where('house_number', $nr)
    ->where('supplier_id', $supplier->id)
    ->first();

if ($postcode && $postcode->updated_at > Carbon::now()->subHours(12)) {
    // Use cached data
    return $postcode;
} else {
    // Dispatch new job
    CheckProvider::dispatch(...);
}
```

### OPcache (PHP code)

**Critical:** Queue workers cache PHP code in memory!

```bash
# After code changes:
1. Clear OPcache
2. Restart queue workers
3. Test
```

**Clear OPcache:**
- Web: https://api.internetvergelijk.nl/clear_opcache.php
- PHP: `opcache_reset()`

---

## ➕ Adding New Providers

### Step 1: Create Provider Library Class

**File:** `app/Libraries/NewProviderPostcodeCheck.php`

```php
<?php

namespace App\Libraries;

use GuzzleHttp\Client;
use App\Models\Postcode;
use App\Models\Supplier;
use Illuminate\Support\Facades\Log;

class NewProviderPostcodeCheck
{
    private $_base;
    private $_guzzle;
    public $name = 'newprovider';
    public $supplier;

    function __construct()
    {
        $this->_base = env('NEWPROVIDER_API_URL', 'https://api.newprovider.nl');
        
        $this->_guzzle = new Client([
            'base_uri' => $this->_base,
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . env('NEWPROVIDER_API_KEY'),
                'Content-Type' => 'application/json'
            ]
        ]);
        
        $this->supplier = Supplier::where('name', 'NewProvider')->first();
    }

    public function request($address, $result, $verbose = 0)
    {
        try {
            // 1. Call provider API
            $response = $this->_guzzle->get("check/{$address->postcode}/{$address->number}");
            
            if ($response->getStatusCode() !== 200) {
                return false;
            }
            
            $data = json_decode($response->getBody(), true);
            
            // 2. Parse speeds
            $maxSpeed = $data['maxDownload'] ?? 0;
            
            // 3. Determine technology
            if ($data['technology'] === 'fiber') {
                $result->glasvezel_max = $maxSpeed;
            } elseif ($data['technology'] === 'cable') {
                $result->kabel_max = $maxSpeed;
            } else {
                $result->adsl_max = $maxSpeed;
            }
            
            // 4. Set max_download
            $result->max_download = max(
                $result->adsl_max ?? 0,
                $result->glasvezel_max ?? 0,
                $result->kabel_max ?? 0
            );
            
            // 5. Save
            $result->save();
            
            Log::info("NewProvider: Saved speeds for {$address->postcode} {$address->number}");
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("NewProvider Error: " . $e->getMessage());
            return false;
        }
    }
}
```

---

### Step 2: Add Environment Variables

**File:** `.env`

```bash
NEWPROVIDER_API_URL=https://api.newprovider.nl/v1
NEWPROVIDER_API_KEY=your-api-key-here
```

---

### Step 3: Register in `config/providers.php`

```php
'NewProvider' => [
    'class' => \App\Libraries\NewProviderNetworkDirect::class,
    'driver' => 'newprovider',
    'name' => 'New Provider',
    'active' => true,
    'queue' => 'default',
    'cache_ttl' => env('SPEEDCHECK_CACHE_TTL', 43200),
],
```

Zorg dat je `driver`-implementatie wordt afgehandeld in `PostcodeController::dispatchProviderAsync()`.

---

### Step 4: Add to Database (optioneel)

```sql
INSERT INTO suppliers (name, is_active, max_download, created_at, updated_at)
VALUES ('NewProvider', 1, 1000, NOW(), NOW());
```

---

### Step 5: Test

```bash
# Test via Artisan (SpeedCheck v2)
php artisan speedcheck:test 2728AA 1 --provider=NewProvider --fresh --raw

# Test via API (v2 endpoint)
curl "https://api.internetvergelijk.nl/speedCheck?postcode=2728AA&nr=1&provider=NewProvider&api_token=YOUR_TOKEN"
```

---

### Step 6: Deploy

1. Upload `NewProviderNetworkDirect.php`
2. Update `.env` op de server
3. `php artisan config:clear && php artisan cache:clear`
4. Restart queue workers (`php artisan queue:restart`)
5. Test in productie met `speedcheck:test`

---

## 🚀 Deployment

### Complete Deployment Procedure

**⚠️ CRITICAL:** Follow ALL steps in order to avoid issues!

#### Step 1: Upload Files via SFTP

**Credentials:**
```
Host: 45.82.188.214
User: internetvergelijk.nl_ry2xqzrhow
Pass: <SFTP_PASSWORD>
```

**Upload paths:**
```bash
# Provider classes
/var/www/vhosts/internetvergelijk.nl/api/app/Libraries/[ProviderClass].php

# Model updates
/var/www/vhosts/internetvergelijk.nl/api/app/Models/Supplier.php

# Controller updates
/var/www/vhosts/internetvergelijk.nl/api/app/Http/Controllers/PostcodeController.php

# Configuration (careful!)
/var/www/vhosts/internetvergelijk.nl/api/.env
```

**Python upload example:**
```python
	import paramiko
	import os

	transport = paramiko.Transport(('45.82.188.214', 22))
	transport.connect(username='internetvergelijk.nl_ry2xqzrhow', password=os.environ['SFTP_PASSWORD'])
	sftp = paramiko.SFTPClient.from_transport(transport)

sftp.put('local/file.php', '/var/www/vhosts/internetvergelijk.nl/api/app/Libraries/file.php')

sftp.close()
transport.close()
```

---

#### Step 2: Update Environment Variables (if needed)

**File:** `/var/www/vhosts/internetvergelijk.nl/api/.env`

```bash
# Add new provider credentials
NEWPROVIDER_API_URL=https://api.provider.com
NEWPROVIDER_API_KEY=your-key-here
```

**⚠️ Important:** After editing `.env`, you MUST clear OPcache and restart workers!

---

#### Step 3: Update Database (if needed)

```sql
-- Add new supplier
INSERT INTO suppliers (name, is_active, max_download, created_at, updated_at)
VALUES ('NewProvider', 1, 1000, NOW(), NOW());

-- Update existing supplier
UPDATE suppliers 
SET max_download = 2000 
WHERE name = 'ProviderName';

-- Verify
SELECT id, name, max_download, is_active FROM suppliers;
```

---

#### Step 4: Clear OPcache (CRITICAL!)

**Method 1: Web-based (recommended)**
```
https://api.internetvergelijk.nl/clear_opcache.php
```

Expected output:
```
✓ OPcache cleared successfully
```

**Method 2: SSH (if you have sudo)**
```bash
sudo systemctl restart php7.4-fpm
```

**Method 3: Plesk Panel**
- Log into Plesk
- PHP Settings → Restart PHP

**Why critical?** OPcache stores compiled PHP code. Without clearing, the web server will continue serving old code.

---

#### Step 5: Restart Queue Workers (CRITICAL!)

**⚠️ This is THE MOST IMPORTANT step!**

Queue workers cache PHP code in memory at startup. They MUST be killed and restarted to load new code.

**Check running workers:**
```bash
ps aux | grep "queue:work"
```

Example output:
```
valso  2169541  0.1  0.7 721144 69720 pts/0  S  13:33  0:00 /opt/plesk/php/8.2/bin/php artisan queue:work --daemon
```

**Kill workers (FORCE):**
```bash
# Option 1: Kill by PID (safer)
kill -9 2169541

# Option 2: Kill all PHP processes (dangerous!)
killall -9 php
```

**Start new worker:**
```bash
cd /var/www/vhosts/internetvergelijk.nl/api
/opt/plesk/php/8.2/bin/php artisan queue:work --daemon &
```

**Verify new worker started:**
```bash
ps aux | grep "queue:work"
# Should show NEW PID with recent start time
```

**Web method:**
```
https://api.internetvergelijk.nl/force_restart_workers.php
```

**Why critical?**  
- `php artisan queue:restart` only sends a signal (workers may not reload)
- Clearing OPcache does NOT affect running workers
- Workers keep code in memory until process dies
- You MUST force kill + restart to load new code

---

#### Step 6: Clear Test Data Cache

Before testing, remove cached data:

**Web method:**
```
https://api.internetvergelijk.nl/delete_test_address.php?postcode=2728AA&nr=3
```

**Database method:**
```sql
DELETE FROM postcodes 
WHERE postcode = '2728AA' 
AND house_number = 3;

-- Or clear all for a provider
DELETE FROM postcodes 
WHERE supplier_id = 4;
```

---

#### Step 7: Test (Sync First, Then Queue)

**Test 1: Sync test (no queue)**

This bypasses the queue system and tests the provider class directly:

```
https://api.internetvergelijk.nl/test_sync.php?postcode=2728AA&nr=3
```

Expected output:
```json
{
    "provider": "Ziggo",
    "download": {
        "dsl": 0,
        "glasvezel": 0,
        "kabel": 2000
    }
}
```

✅ If sync test works → Provider code is correct  
❌ If sync test fails → Check provider class, API credentials, logs

**Test 2: speedCheck API (with queue)**

Wait 10-15 seconds for queue processing, then:

```
https://api.internetvergelijk.nl/api/speedcheck?postcode=2728AA&nr=3
```

Expected output:
```json
{
    "kabel": 2000
}
```

✅ If matches sync test → Queue workers loaded new code  
❌ If different from sync → Queue workers still running old code (repeat Step 5)

---

#### Step 8: Monitor Logs

**View logs in real-time:**
```bash
# Via web
https://api.internetvergelijk.nl/view_ziggo_logs.php

# Via SSH
tail -f /var/www/vhosts/internetvergelijk.nl/api/storage/logs/laravel.log | grep "Provider"
```

**What to look for:**
- ✅ API calls succeeding
- ✅ Correct speeds being parsed
- ✅ Data saving to database
- ❌ Error messages
- ❌ Timeouts
- ❌ Wrong values

Monitor for at least **1 hour** after deployment.

---

### Deployment Checklist

**Pre-Deployment:**
- [ ] Code tested locally
- [ ] .env variables documented
- [ ] Database changes planned
- [ ] Rollback plan ready
- [ ] Test addresses identified

**Deployment:**
- [ ] Files uploaded via SFTP
- [ ] `.env` updated (if needed)
- [ ] Database updated (if needed)
- [ ] OPcache cleared
- [ ] Queue workers killed
- [ ] New workers started
- [ ] Test data cache cleared
- [ ] Sync test passing
- [ ] speedCheck API passing
- [ ] Logs showing correct behavior

**Post-Deployment:**
- [ ] Monitor logs for 1 hour
- [ ] Test multiple addresses
- [ ] Check database for correct values
- [ ] Verify queue processing
- [ ] Update documentation
- [ ] Notify team

---

### Common Deployment Mistakes

❌ **Mistake 1:** Forget to restart queue workers  
✅ **Fix:** Always kill + restart workers after code changes

❌ **Mistake 2:** Only run `queue:restart` (doesn't reload code)  
✅ **Fix:** Force kill (`kill -9`) and start new workers

❌ **Mistake 3:** Test immediately (queue hasn't processed yet)  
✅ **Fix:** Wait 10-15 seconds after making request

❌ **Mistake 4:** Forget to clear test data cache  
✅ **Fix:** Delete cached postcodes before testing

❌ **Mistake 5:** Only test speedCheck (hides queue issues)  
✅ **Fix:** Test sync endpoint FIRST, then speedCheck

---

### Rollback Procedure

If deployment fails:

1. **Upload previous version** of files via SFTP
2. **Revert database** changes (if any)
3. **Revert .env** changes (if any)
4. **Clear OPcache**
5. **Restart queue workers**
6. **Test** to verify rollback worked
7. **Investigate** what went wrong

**Keep backups:**
```bash
# Before deployment, backup critical files
scp user@host:/path/to/file.php file.php.backup.$(date +%Y%m%d_%H%M%S)
```

---

### Critical Files

```
app/Libraries/                              - Provider classes
app/Models/Supplier.php                     - Provider registration
app/Http/Controllers/PostcodeController.php - API endpoints
app/Jobs/CheckProvider.php                  - Queue job
.env                                        - Configuration (NEVER commit!)
database/migrations/                        - Database schema
```

---

## 🔧 Troubleshooting

### Problem: Queue Workers Not Processing

**Symptoms:**
- API returns empty results
- Jobs pile up in database
- No logs from providers

**Solution:**
```bash
# Check workers
ps aux | grep "queue:work"

# If none running, start
cd /var/www/vhosts/internetvergelijk.nl/api
/opt/plesk/php/7.4/bin/php artisan queue:work --daemon &
```

---

### Problem: Wrong Speed Returned

**Symptoms:**
- API returns old/incorrect speed
- Direct test shows correct speed

**Solution:**
```bash
# 1. Clear cached data
DELETE FROM postcodes WHERE postcode = 'XXXX' AND house_number = X;

# 2. Clear OPcache
# Via: https://api.internetvergelijk.nl/clear_opcache.php

# 3. Restart workers
kill -9 [PID]
/opt/plesk/php/7.4/bin/php artisan queue:work --daemon &

# 4. Test again
```

---

### Problem: Provider API Returns Error

**Check:**
1. API credentials in `.env`
2. IP whitelisting
3. API endpoint URL
4. Request/response format
5. Logs: `storage/logs/laravel.log`

---

## 📊 Monitoring

### Real-Time Tools

```
View Logs:        https://api.internetvergelijk.nl/view_ziggo_logs.php
Queue Status:     https://api.internetvergelijk.nl/check_queue_workers.php
Supplier Config:  https://api.internetvergelijk.nl/check_supplier_config.php
Test Sync:        https://api.internetvergelijk.nl/test_sync.php
```

### Database Queries

**Recent checks:**
```sql
SELECT p.*, s.name as supplier_name
FROM postcodes p
JOIN suppliers s ON p.supplier_id = s.id
WHERE p.updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY p.updated_at DESC
LIMIT 50;
```

**Speed distribution:**
```sql
SELECT 
    s.name,
    COUNT(*) as checks,
    AVG(p.max_download) as avg_speed,
    MAX(p.max_download) as max_speed
FROM postcodes p
JOIN suppliers s ON p.supplier_id = s.id
WHERE p.updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY s.name;
```

**Queue size:**
```sql
SELECT COUNT(*) as pending_jobs FROM jobs;
```

---

## 📚 Provider-Specific Documentation

- **Ziggo V2**: See [ZIGGO_README.md](ZIGGO_README.md)
- **KPN**: (To be documented)
- **T-Mobile**: (To be documented)
- **Caiway**: (To be documented)

---

## 🔗 Quick Links

**Production:**
- API: https://api.internetvergelijk.nl
- speedCheck: https://api.internetvergelijk.nl/speedCheck

**Tools:**
- Clear OPcache: https://api.internetvergelijk.nl/clear_opcache.php
- View Logs: https://api.internetvergelijk.nl/view_ziggo_logs.php

**Repository:**
- Local: `/Users/marnix/Documents/Projects/Internetvergelijk/API/server`

---

## 📝 Changelog

### 2025-10-21
- ✅ Ziggo V2 API implemented with 2000 Mbps support
- ✅ Complete architecture documentation created
- ✅ Provider system documented

---

**End of Documentation**

*For provider-specific details, see individual provider documentation files.*
