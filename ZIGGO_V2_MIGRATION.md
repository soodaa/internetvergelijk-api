# Ziggo V2 API Migratie Guide

> **Let op:** Deze guide beschrijft de historische V1→V2 migratie. Sinds de november 2025 cleanup worden providers alleen nog via `config/providers.php` geregistreerd en bestaan de legacy classes (`Supplier::$providerClasses`, `ZiggoPostcodeCheck`, etc.) niet meer. Ook de oude `ziggo:compare`, `/api/ziggo-test` en `/api/ziggo-compare` testingangen zijn verwijderd. Gebruik voor nieuwe aanpassingen de actuele instructies uit `ARCHITECTURE.md` en test via `/opt/plesk/php/8.1/bin/php artisan speedcheck:test ... --provider=Ziggo --fresh --raw`.

## 📋 Overzicht

Deze guide helpt je om van de oude Ziggo V1 API naar de nieuwe V2 API te migreren.

## 🆕 Wat is er nieuw in V2?

### API Verschillen

| Feature | V1 | V2 |
|---------|----|----|
| **Base URL** | `www.ziggo.nl/shop/api` | `api.prod.aws.ziggo.io/v2/api/rfscom/v2` |
| **Endpoints** | `/footprint` | `/footprint`, `/availability`, `/address`, `/health` |
| **Response** | Basic (footprint, maxSpeed) | Detailed (products, technology, tiers) |
| **Error handling** | Limited | Improved with status codes |
| **Rate limiting** | Unknown | Documented |

### Nieuwe Features

✅ **Health endpoint** - Monitor API status  
✅ **Address validation** - Verify address format  
✅ **Detailed availability** - Product-level information  
✅ **Better error messages** - Clear error responses  

## 🚀 Migratie Stappen

### Stap 1: Environment Setup

1. **Voeg nieuwe variables toe aan `.env`:**

```bash
# Kopieer de example file
cp .env.ziggo.example .env.ziggo

# Voeg toe aan je .env:
ZIGGO_V2_API_URL=https://api.prod.aws.ziggo.io/v2/api/rfscom/v2
ZIGGO_V2_API_KEY=your-new-api-key-here
```

2. **Vraag nieuwe API credentials aan bij Ziggo**
   - Contact: [Ziggo API team contact info]
   - Vraag om: V2 API Key voor productie

### Stap 2: Parallel Testing

Test de nieuwe V2 API **zonder** de oude te vervangen:

```bash
# Navigate to project
cd /Users/marnix/Documents/Projects/Internetvergelijk/API/server/var/www/vhosts/internetvergelijk.nl/api

# Test met bekende adressen
php artisan ziggo:compare 2723AB 106
php artisan ziggo:compare 1234AB 10 A

# Test met verschillende adressen
php artisan ziggo:compare {postcode} {huisnummer} {toevoeging}
```

**Verwacht resultaat:**
```
=== Comparing Ziggo V1 vs V2 ===
Address: 2723AB 106

--- Testing V1 API ---
✅ V1 Success - Time: 250ms
   kabel_max: 1000
   max_download: 1000

--- Testing V2 API ---
✅ V2 Success - Time: 180ms
   kabel_max: 1000
   max_download: 1000

=== Comparison ===
✅ Results match: 1000 Mbps
Performance: V1=250ms, V2=180ms
```

### Stap 3: Activeer V2 (Twee Opties)

#### **Optie A: Veilige Migratie (Aanbevolen)** ⭐

Voeg V2 toe als aparte provider voor testing:

```php
// In app/Models/Supplier.php
public $providerClasses = [
    // ... andere providers ...
    'Ziggo' => \App\Libraries\ZiggoPostcodeCheck::class,      // V1 blijft actief
    'Ziggo-v2' => \App\Libraries\ZiggoPostcodeCheckV2::class, // V2 parallel
];
```

Voeg nieuwe supplier toe in database:
```sql
INSERT INTO suppliers (name, is_active, created_at, updated_at) 
VALUES ('Ziggo-v2', 1, NOW(), NOW());
```

Test parallel voor 1-2 weken, vergelijk resultaten.

#### **Optie B: Directe Switch**

Vervang V1 direct met V2:

```php
// In app/Models/Supplier.php
public $providerClasses = [
    // ... andere providers ...
    'Ziggo' => \App\Libraries\ZiggoPostcodeCheckV2::class, // Direct naar V2
];
```

⚠️ **Waarschuwing:** Test grondig eerst!

### Stap 4: Update Health Check

Update de health check om V2 te gebruiken:

```php
// In app/Console/Commands/HealthCheck.php

// Voeg toe aan $providers array:
'Ziggo-v2' => [
    'postcode' => '2723AB',
    'number' => 106,
    'result' => [
        'kabel_max'
    ]
],
```

### Stap 5: Monitoring

Monitor de nieuwe API gedurende de eerste week:

1. **Check logs:**
```bash
tail -f storage/logs/laravel.log | grep "Ziggo V2"
```

2. **Run health checks:**
```bash
# Handmatig
php artisan health:check

# Of check de emails voor failure notifications
```

3. **Vergelijk resultaten:**
```bash
# Voor random adressen
php artisan ziggo:compare 1234AB 10
php artisan ziggo:compare 5678CD 25 B
```

### Stap 6: Rollback Plan (als iets misgaat)

Als V2 problemen geeft:

1. **Terug naar V1:**
```php
// app/Models/Supplier.php
'Ziggo' => \App\Libraries\ZiggoPostcodeCheck::class, // Terug naar V1
```

2. **Cache clear:**
```bash
php artisan cache:clear
php artisan queue:restart
```

3. **Database cleanup (optioneel):**
```sql
-- Verwijder V2 resultaten (als die fout zijn)
DELETE FROM postcodes 
WHERE supplier_id = (SELECT id FROM suppliers WHERE name = 'Ziggo-v2')
AND updated_at > '2025-10-20';
```

## 🧪 Test Scenarios

### Basis Tests

```bash
# Bekende Ziggo dekking
php artisan ziggo:compare 2723AB 106        # Verwacht: 1000 Mbps

# Geen dekking
php artisan ziggo:compare 9999ZZ 1          # Verwacht: false/0 Mbps

# Met toevoeging
php artisan ziggo:compare 1234AB 10 A       # Test house extension
```

### Performance Tests

```bash
# Run multiple times, check timing
for i in {1..10}; do 
    php artisan ziggo:compare 2723AB 106; 
done
```

### Edge Cases

- Postcode met spatie: `1234 AB`
- Hoog huisnummer: `9999`
- Speciale toevoegingen: `A`, `II`, `bis`

## 📊 Verwachte Resultaten

### Success Metrics

✅ Response time: < 300ms (gemiddeld)  
✅ Success rate: > 95%  
✅ Accuracy: 100% match met V1 (voor bekende adressen)  
✅ Cache hit rate: > 80% (na warmup)  

### Known Issues

⚠️ **Response structure verschillen:**
- V2 response format kan anders zijn dan V1
- Mogelijk moeten `parseSpeed()` method worden aangepast
- Check de PDF documentatie voor exacte response structure

⚠️ **Rate limiting:**
- V2 heeft mogelijk rate limits
- Implementeer exponential backoff als nodig
- Monitor 429 responses

## 🔧 Troubleshooting

### API Key Issues

**Error:** `401 Unauthorized`

**Oplossing:**
```bash
# Check .env file
cat .env | grep ZIGGO_V2

# Verify API key is correct
# Contact Ziggo for new key if needed
```

### Timeout Issues

**Error:** `Connection timeout`

**Oplossing:**
```php
// In ZiggoPostcodeCheckV2.php, increase timeout:
'timeout' => 15,  // Was: 10
'connect_timeout' => 10, // Was: 6
```

### Wrong Response Format

**Error:** Speed = 0, but address has coverage

**Oplossing:**
1. Enable verbose mode: `php artisan ziggo:compare {postcode} {nr} --verbose`
2. Check response structure
3. Update `parseSpeed()` method in ZiggoPostcodeCheckV2.php

### Health Check Fails

**Error:** Health check reports V2 failures

**Oplossing:**
```bash
# Manual health check
php artisan ziggo:compare 2723AB 106

# Check API status
curl -H "X-Api-Key: YOUR_KEY" \
  https://api.prod.aws.ziggo.io/v2/api/rfscom/v2/health
```

## 📞 Support

Als je problemen tegenkomt:

1. **Check logs:**
   ```bash
   tail -f storage/logs/laravel.log | grep "Ziggo"
   ```

2. **Run diagnostic:**
   ```bash
   php artisan ziggo:compare {postcode} {number}
   ```

3. **Contact:**
   - Internal: marnix@sooda.nl
   - Ziggo API Support: [contact info from PDF]

## 📝 Checklist

- [ ] V2 API credentials ontvangen
- [ ] Environment variables toegevoegd
- [ ] ZiggoPostcodeCheckV2.php getest
- [ ] Compare script uitgevoerd (10+ adressen)
- [ ] Resultaten matchen met V1
- [ ] Performance is acceptabel (< 300ms)
- [ ] Health check updated
- [ ] Monitoring ingesteld
- [ ] Team geïnformeerd
- [ ] Rollback plan klaar
- [ ] Documentatie bijgewerkt
- [ ] V2 geactiveerd in productie
- [ ] Eerste week monitoring

## 🎯 Timeline (Voorbeeld)

| Dag | Activiteit |
|-----|------------|
| **Dag 1** | Setup: Credentials, env vars, testing |
| **Dag 2-3** | Parallel testing: Compare V1 vs V2 |
| **Dag 4** | Analyse: Check results, fix issues |
| **Dag 5** | Activeer V2 als 'Ziggo-v2' (parallel) |
| **Dag 6-12** | Monitor: Beide versies parallel |
| **Dag 13** | Decision: Switch to V2 or rollback |
| **Dag 14** | Cleanup: Remove V1 code (if successful) |

## ✅ Success Criteria

Migratie is succesvol als:

1. ✅ V2 API response time < V1 response time
2. ✅ V2 accuracy = 100% (vergeleken met V1)
3. ✅ Geen errors in logs (> 95% success rate)
4. ✅ Health check passes consistent
5. ✅ Frontend output blijft hetzelfde
6. ✅ No customer complaints

**Good luck with the migration! 🚀**
