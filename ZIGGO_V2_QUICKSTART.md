# Ziggo V2 Quick Start

## 🚀 Snelle Setup (5 minuten)

### 1. Voeg API credentials toe

Bewerk je `.env` file:

```bash
# Voeg toe aan .env:
ZIGGO_V2_API_URL=https://api.prod.aws.ziggo.io/v2/api/rfscom/v2
ZIGGO_V2_API_KEY=jouw-api-key-hier
```

### 2. Test de V2 API

```bash
cd /Users/marnix/Documents/Projects/Internetvergelijk/API/server/var/www/vhosts/internetvergelijk.nl/api

# Test met bekende adres
/opt/plesk/php/8.1/bin/php artisan speedcheck:test 2723AB 106 --provider=Ziggo --fresh --raw
```

### 3. Activeer V2

**Optie A: Parallel (veilig)** ⭐
```php
// In app/Models/Supplier.php, voeg toe:
'Ziggo-v2' => \App\Libraries\ZiggoPostcodeCheckV2::class,
```

**Optie B: Direct switch**
```php
// In app/Models/Supplier.php, vervang:
'Ziggo' => \App\Libraries\ZiggoPostcodeCheckV2::class, // was: ZiggoPostcodeCheck
```

### 4. Restart queue workers

```bash
php artisan queue:restart
```

### 5. Monitor

```bash
# Check logs
tail -f storage/logs/laravel.log | grep "Ziggo V2"

# Test endpoint
curl "http://your-domain/api/speedCheck?postcode=2723AB&nr=106"
```

## ✅ Klaar!

Je Ziggo V2 API is nu actief. 

### 📊 Verwachte Snelheden

De API geeft deze waarden terug in `kabel_max`:
- **100** Mbps (Lite)
- **200** Mbps (Start)
- **400** Mbps (XXL)
- **750** Mbps (Complete/Max)
- **1000** Mbps (Giga/Elite)
- **2000** Mbps (Giga Plus) ✨ **NIEUW**

Nieuwe snelheden (125, 250, 500, 775, 1100, 2200) worden automatisch genormaliseerd.

Check `ZIGGO_SPEEDS.md` voor details en `ZIGGO_V2_MIGRATION.md` voor volledige migratie guide.

### 🧪 Test Adressen

```bash
# Giga Plus (2000 Mbps)
/opt/plesk/php/8.1/bin/php artisan speedcheck:test 2728AA 1 --provider=Ziggo --fresh --raw
/opt/plesk/php/8.1/bin/php artisan speedcheck:test 2723AB 106 --provider=Ziggo --fresh --raw

# Test via production API
curl "https://api.internetvergelijk.nl/speedCheck?postcode=2728AA&nr=1&api_token=YOUR_TOKEN"
# Expected: "kabel": 2000
```

## 🔥 Commands

```bash
# Compare V1 vs V2
/opt/plesk/php/8.1/bin/php artisan speedcheck:test {postcode} {number} --provider=Ziggo --fresh --raw

# Test provider (V2)
php artisan provider:speedcheck Ziggo Ziggo-v2 2723AB 106 ""

# Health check
php artisan health:check
```
