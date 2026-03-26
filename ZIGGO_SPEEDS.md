# Ziggo Speed Tiers Reference

## 📊 Huidige Snelheden (2025)

| Abonnement | Prijs | Download | Upload | Database Waarde |
|------------|-------|----------|--------|-----------------|
| Lite | €43,50 | 125 Mbps | 25 Mbps | `100` ⭐ |
| Start | €48,00 | 250 Mbps | 30 Mbps | `200` ⭐ |
| XXL | €53,00 | 500 Mbps | 40 Mbps | `400` ⭐ |
| Complete/Max | - | 775 Mbps | 60 Mbps | `750` ⭐ |
| Giga/Elite | €60,00 | 1100 Mbps | 100 Mbps | `1000` ⭐ |
| **🆕 Giga Plus** | - | **2000/2200 Mbps** | **200 Mbps** | **`2000`** ✨ |

⭐ = Genormaliseerd naar standaard tier voor consistentie  
✨ = **NIEUW in 2025** - Hoogste snelheid beschikbaar

## 🔄 Normalisatie Logic

De `ZiggoPostcodeCheckV2` class normaliseert automatisch de nieuwe snelheden naar standaard tiers:

```php
125 Mbps  → 100 Mbps   (Lite)
250 Mbps  → 200 Mbps   (Start)
500 Mbps  → 400 Mbps   (XXL)
775 Mbps  → 750 Mbps   (Complete/Max)
1100 Mbps → 1000 Mbps  (Giga/Elite)
2000 Mbps → 2000 Mbps  (Giga Plus) ✅ NO MAPPING
2200 Mbps → 2000 Mbps  (Giga Plus variant)
```

**Waarom normaliseren?**
- ✅ Consistente database waarden
- ✅ Geen breaking changes in API output
- ✅ Frontend hoeft niet aangepast
- ✅ Makkelijker vergelijken met andere providers
- ✅ **2000 Mbps wordt NIET genormaliseerd** - blijft 2000!

## 📝 Database Schema

In de `postcodes` tabel wordt opgeslagen:

```sql
kabel_max: 100, 200, 400, 750, 1000, of 2000 (UPDATED!)
max_download: hoogste waarde van alle technologieën
```

In de `suppliers` tabel:

```sql
-- Ziggo supplier (id: 4)
max_download: 2000  -- MUST be 2000 or higher for 2 Gbit support
```

## 🎯 API Response Voorbeeld

Voor een Ziggo Giga Plus adres (2 Gbit):

```json
{
  "provider": "Ziggo",
  "download": {
    "dsl": null,
    "glasvezel": null,
    "kabel": 2000
  }
}
```

Voor een Ziggo Giga/Elite adres (1 Gbit):

```json
{
  "provider": "Ziggo",
  "download": {
    "dsl": null,
    "glasvezel": null,
    "kabel": 1000
  }
}
```

## 🧪 Test Adressen

Voor testing met verschillende speed tiers:

```bash
# Giga Plus (2000 Mbps) - 2 Gbit tier
/opt/plesk/php/8.1/bin/php artisan speedcheck:test 2723AB 106 --provider=Ziggo --fresh --raw
/opt/plesk/php/8.1/bin/php artisan speedcheck:test 2728AA 1 --provider=Ziggo --fresh --raw

# Giga/Elite (1000 Mbps)
# (Find specific addresses with 1000 Mbps coverage)

# XXL (400 Mbps)
# (Find specific addresses with XXL coverage)

# Start (200 Mbps)
# (Find specific addresses with Start coverage)
```

**Production Test:**
```
https://api.internetvergelijk.nl/speedCheck?postcode=2728AA&nr=3&api_token=YOUR_TOKEN
```

Expected: `"kabel": 2000`

## 📅 Implementation Timeline

| Datum | Event |
|-------|-------|
| **20 okt 2025** | V2 API implementatie gestart |
| **21 okt 2025** | ✅ **2000 Mbps support actief in productie** |
| **3 maart 2026** | Ziggo verhoogt officieel snelheden |
| **Na 3 maart** | Normalisatie blijft werken |

De implementatie is **LIVE** en werkt! 🎉

## 💡 Implementation Notes

- **Complete/Max** pakketten zijn legacy, maar bestaande klanten hebben deze nog
- **Giga Plus (2 Gbit)** is de nieuwe top-tier vanaf 2025
- V2 API retourneert snelheden in **Gbit/s format** (bijv. "2.2" = 2200 Mbps)
- `normalizeZiggoSpeed()` mapt API response naar database tiers
- Check `view_ziggo_logs.php` voor real-time debugging van API calls

## 🔧 Troubleshooting

### speedCheck Returns 1000 Instead of 2000

**Probleem:** API geeft 2000 terug, maar speedCheck endpoint toont 1000.

**Oorzaak:** Queue workers draaien met oude code in geheugen.

**Oplossing:**
```bash
# 1. Check welke workers draaien
ps aux | grep "queue:work"

# 2. Kill alle workers
kill -9 [PID1] [PID2] [PID3]

# 3. Clear OPcache
# Via web: https://api.internetvergelijk.nl/clear_opcache.php

# 4. Start 1 nieuwe worker
cd /var/www/vhosts/internetvergelijk.nl/api
/opt/plesk/php/7.4/bin/php artisan queue:work --daemon &

# 5. Delete cached data
# Via web: https://api.internetvergelijk.nl/delete_test_address.php?postcode=XXX&nr=Y

# 6. Test again
```

**Belangrijke les:** Queue workers laden PHP code bij opstarten en cachen die in geheugen. Artisan queue:restart stuurt alleen een signal, maar workers moeten daadwerkelijk gestopt en herstart worden om nieuwe code te laden!

### Database Still Shows 1000

Check `suppliers.max_download`:
```sql
UPDATE suppliers SET max_download = 2000 WHERE name = 'Ziggo';
```

### Logs Not Showing

Use debugging tools:
- `https://api.internetvergelijk.nl/view_ziggo_logs.php` - Real-time log viewer
- `https://api.internetvergelijk.nl/test_sync.php?postcode=XXX&nr=Y` - Sync test (no queue)
- `https://api.internetvergelijk.nl/check_supplier_config.php` - Verify configuration
