# Ziggo V2 Production Deployment Guide

**Status:** ✅ **LIVE IN PRODUCTION** (21 oktober 2025)

Dit document beschrijft de volledige deployment procedure voor Ziggo V2 API met 2000 Mbps support.

---

## 📋 Pre-Deployment Checklist

- [x] V2 API credentials verkregen van Ziggo
- [x] `.env` configuratie toegevoegd
- [x] `ZiggoPostcodeCheckV2.php` geïmplementeerd met 2000 Mbps support
- [x] Supplier.php bijgewerkt naar V2 class
- [x] Database `suppliers.max_download` = 2000
- [x] Testing voltooid (2723AB 106, 2728AA 1, etc.)
- [x] Documentatie bijgewerkt

---

## 🚀 Deployment Steps

### 1. Environment Configuration

**File:** `/var/www/vhosts/internetvergelijk.nl/api/.env`

```bash
# Ziggo V2 API Configuration
ZIGGO_V2_API_URL=https://api.prod.aws.ziggo.io/v2/api/rfscom/v2
ZIGGO_V2_API_KEY=<ZIGGO_V2_API_KEY>
ZIGGO_API_KEY=<ZIGGO_API_KEY>
```

**✅ Status:** Configured in production

---

### 2. Upload Files via SFTP

**SFTP Credentials:**
- Host: `45.82.188.214`
- User: `internetvergelijk.nl_ry2xqzrhow`
- Password: `<SFTP_PASSWORD>`

**Files to upload:**

```bash
# Main implementation
/var/www/vhosts/internetvergelijk.nl/api/app/Libraries/ZiggoPostcodeCheckV2.php

# Model configuration
/var/www/vhosts/internetvergelijk.nl/api/app/Models/Supplier.php

# Optional: Debug tools (can be uploaded later)
/var/www/vhosts/internetvergelijk.nl/api/public/test_sync.php
/var/www/vhosts/internetvergelijk.nl/api/public/view_ziggo_logs.php
/var/www/vhosts/internetvergelijk.nl/api/public/check_supplier_config.php
/var/www/vhosts/internetvergelijk.nl/api/public/delete_test_address.php
/var/www/vhosts/internetvergelijk.nl/api/public/clear_opcache.php
```

**Python upload script:**
```python
#!/usr/bin/env python3
	import paramiko
	import os

	hostname = '45.82.188.214'
	username = 'internetvergelijk.nl_ry2xqzrhow'
	password = os.environ['SFTP_PASSWORD']

transport = paramiko.Transport((hostname, 22))
transport.connect(username=username, password=password)
sftp = paramiko.SFTPClient.from_transport(transport)

# Upload main file
sftp.put(
    'app/Libraries/ZiggoPostcodeCheckV2.php',
    '/var/www/vhosts/internetvergelijk.nl/api/app/Libraries/ZiggoPostcodeCheckV2.php'
)

sftp.close()
transport.close()
```

**✅ Status:** Files uploaded successfully

---

### 3. Database Configuration

**Update Ziggo supplier max_download:**

```sql
UPDATE suppliers 
SET max_download = 2000 
WHERE name = 'Ziggo';
```

**Verify:**
```sql
SELECT id, name, max_download, is_active 
FROM suppliers 
WHERE name = 'Ziggo';
```

Expected output:
```
id: 4
name: Ziggo
max_download: 2000
is_active: 1
```

**✅ Status:** Database updated

---

### 4. Clear Caches

**Critical:** This step must be done EVERY time you update PHP code!

#### 4a. Clear OPcache (Web Method)
```
https://api.internetvergelijk.nl/clear_opcache.php
```

Expected output:
```
✓ OPcache cleared successfully
```

#### 4b. Clear OPcache (SSH Method)
```bash
# Connect via SSH
ssh internetvergelijk.nl_ry2xqzrhow@45.82.188.214

# Restart PHP-FPM (if you have sudo)
sudo systemctl restart php7.4-fpm

# OR restart via Plesk panel
```

**✅ Status:** OPcache cleared

---

### 5. Restart Queue Workers

**⚠️ CRITICAL:** This is the most important step! Queue workers cache PHP code in memory.

#### 5a. Check Running Workers
```bash
ps aux | grep "queue:work"
```

Example output:
```
valso    2169541  0.1  0.7 721144 69720 pts/0  S  13:33  0:00 /opt/plesk/php/7.4/bin/php artisan queue:work --daemon
```

#### 5b. Kill All Workers
```bash
# Method 1: Kill by PID (safer)
kill -9 2169541

# Method 2: Kill all (dangerous - kills ALL PHP processes!)
killall -9 php
```

#### 5c. Start New Worker
```bash
cd /var/www/vhosts/internetvergelijk.nl/api
/opt/plesk/php/7.4/bin/php artisan queue:work --daemon &
```

#### 5d. Verify Worker Started
```bash
ps aux | grep "queue:work"
```

Should show new PID with recent start time.

**Web Method:**
```
https://api.internetvergelijk.nl/force_restart_workers.php
```

**✅ Status:** Workers restarted with new code

---

### 6. Clear Cached Test Data

Before testing, remove any old cached results:

```bash
# Via web
https://api.internetvergelijk.nl/delete_test_address.php?postcode=2728AA&nr=3
```

Or via database:
```sql
DELETE FROM postcodes 
WHERE postcode = '2728AA' 
AND house_number = 3;
```

**✅ Status:** Test data cleared

---

### 7. Testing & Verification

#### 7a. Test Sync (No Queue)
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
        "kabel": 2000  ✅
    }
}
```

#### 7b. Test speedCheck API (With Queue)
Wait 10 seconds for queue processing, then:
```
https://api.internetvergelijk.nl/api/speedcheck?postcode=2728AA&nr=3
```

Expected output:
```json
{
    "kabel": 2000  ✅
}
```

#### 7c. View Logs
```
https://api.internetvergelijk.nl/view_ziggo_logs.php
```

Should show:
- 📊 **PARSE**: speedGbit=2.0, maxSpeed_mbps=2000
- 🔍 **TRACE**: maxSpeed_before_save=2000
- ✅ **SAVED**: kabel_max_after_save=2000

#### 7d. Test Multiple Addresses
```
2723AB 106 → Expected: 2000 Mbps (API returns 2200, normalized to 2000)
2728AA 1   → Expected: 2000 Mbps
2728AA 3   → Expected: 2000 Mbps
```

**✅ Status:** All tests passing

---

## 🔧 Troubleshooting Guide

### Problem 1: speedCheck Returns 1000 Instead of 2000

**Symptom:** Sync test shows 2000, but speedCheck API returns 1000.

**Cause:** Queue workers running old code in memory.

**Solution:**
1. Kill all queue workers: `killall -9 php`
2. Clear OPcache: Visit `clear_opcache.php`
3. Start new worker: `/opt/plesk/php/7.4/bin/php artisan queue:work --daemon &`
4. Delete cached data: Visit `delete_test_address.php`
5. Wait 10 seconds
6. Test again

**Why this happens:** Queue workers load PHP code once at startup and keep it in memory. Even after uploading new files and clearing OPcache, workers continue using old code until they restart.

---

### Problem 2: API Returns 403 Forbidden

**Symptom:** Ziggo V2 API returns HTTP 403.

**Cause:** Missing or incorrect API key, or IP not whitelisted.

**Solution:**
1. Check `.env` has correct `ZIGGO_V2_API_KEY`
2. Clear OPcache after updating `.env`
3. Restart queue workers
4. Contact Ziggo to whitelist server IP: Check via `curl ifconfig.me`

---

### Problem 3: No Logs Appearing

**Symptom:** `view_ziggo_logs.php` shows "No Ziggo V2 logs found".

**Cause:** Old code without logging still running in queue workers.

**Solution:**
1. Verify file uploaded: Check file modified date
2. Restart queue workers (see above)
3. Make a fresh request
4. Check logs again

---

### Problem 4: Database Shows 1000

**Symptom:** Database `suppliers.max_download` is 1000.

**Solution:**
```sql
UPDATE suppliers SET max_download = 2000 WHERE name = 'Ziggo';
```

Then clear cached postcodes:
```sql
DELETE FROM postcodes WHERE supplier_id = 4 AND updated_at < NOW();
```

---

## 📊 Monitoring

### Real-Time Monitoring

**Log Viewer:**
```
https://api.internetvergelijk.nl/view_ziggo_logs.php
```

Refresh every few minutes to see new API calls.

**Queue Status:**
```
https://api.internetvergelijk.nl/check_queue_workers.php
```

Shows running workers and job count.

### Log Files

**Laravel logs:**
```bash
tail -f /var/www/vhosts/internetvergelijk.nl/api/storage/logs/laravel.log | grep "Ziggo V2"
```

Look for:
- `Ziggo V2 PARSE` - Raw API responses
- `Ziggo V2 NORMALIZE` - Speed mapping
- `Ziggo V2 TRACE` - Pre-save data
- `Ziggo V2 SAVED` - Final database values
- `Ziggo V2 Error` - Any failures

### Database Monitoring

**Check recent Ziggo speeds:**
```sql
SELECT postcode, house_number, kabel_max, max_download, updated_at
FROM postcodes
WHERE supplier_id = 4
AND updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
ORDER BY updated_at DESC
LIMIT 20;
```

**Count by speed tier:**
```sql
SELECT kabel_max, COUNT(*) as count
FROM postcodes
WHERE supplier_id = 4
GROUP BY kabel_max
ORDER BY kabel_max DESC;
```

---

## 🎯 Success Criteria

Deployment is successful if:

- [x] ✅ speedCheck API returns 2000 for 2 Gbit addresses
- [x] ✅ Sync test matches queue test results
- [x] ✅ Logs show correct parsing (2.0 Gbit → 2000 Mbps)
- [x] ✅ Database saves 2000, not 1000
- [x] ✅ No errors in Laravel logs
- [x] ✅ Queue workers running with new code
- [x] ✅ All test addresses return expected speeds

**Status:** ✅ **ALL CRITERIA MET** - Deployment successful!

---

## 📝 Post-Deployment Tasks

### 1. Monitor for 24 Hours

Check logs and API responses periodically:
- Hour 1, 2, 4, 8, 24 after deployment
- Look for unexpected errors or wrong speeds

### 2. Test Various Addresses

Test different speed tiers:
```bash
# Find addresses with different tiers
# 100, 200, 400, 750, 1000, 2000 Mbps
```

### 3. Update Documentation

- [x] ✅ ZIGGO_SPEEDS.md updated
- [x] ✅ ZIGGO_V2_MIGRATION.md updated
- [x] ✅ This deployment guide created
- [ ] Update API documentation for clients (if needed)
- [ ] Notify team of changes

### 4. Cleanup (Optional)

After 1 week of successful operation:
- Consider removing debug tools from public/ (test_sync.php, etc.)
- Or move them to a password-protected directory
- Remove old V1 code if no longer needed

### 5. Future Updates

**When updating ZiggoPostcodeCheckV2.php:**

1. Upload new file via SFTP
2. Clear OPcache: `clear_opcache.php`
3. Kill queue workers: `kill -9 [PID]`
4. Start new workers
5. Test with sync test first
6. Then test speedCheck API
7. Monitor logs

**Remember:** Queue workers MUST be restarted to load new code!

---

## 🔗 Quick Links

**Production Tools:**
- Clear OPcache: https://api.internetvergelijk.nl/clear_opcache.php
- View Logs: https://api.internetvergelijk.nl/view_ziggo_logs.php
- Test Sync: https://api.internetvergelijk.nl/test_sync.php
- Delete Cache: https://api.internetvergelijk.nl/delete_test_address.php
- Check Config: https://api.internetvergelijk.nl/check_supplier_config.php
- Queue Status: https://api.internetvergelijk.nl/check_queue_workers.php
- Restart Workers: https://api.internetvergelijk.nl/force_restart_workers.php

**speedCheck API:**
```
https://api.internetvergelijk.nl/api/speedcheck?postcode={PC}&nr={NR}
```

**SFTP:**
```
Host: 45.82.188.214
User: internetvergelijk.nl_ry2xqzrhow
Pass: <SFTP_PASSWORD>
```

---

## 👥 Support

**Internal Contact:**
- Developer: Marnix
- Email: marnix@sooda.nl

**External:**
- Ziggo API Support: (see ziggo-api.md)

---

## 📅 Changelog

### 2025-10-21: Initial Deployment ✅
- Ziggo V2 API implemented
- 2000 Mbps support added
- Normalization logic: 2200 → 2000, 2000 → 2000
- Queue worker restart procedure documented
- All tests passing
- **Status: LIVE IN PRODUCTION**

---

**End of Deployment Guide**

*For migration details, see ZIGGO_V2_MIGRATION.md*  
*For speed tiers, see ZIGGO_SPEEDS.md*  
*For quick reference, see ZIGGO_V2_QUICKSTART.md*
