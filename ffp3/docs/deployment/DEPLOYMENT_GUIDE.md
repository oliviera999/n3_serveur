# 🚀 FFP3 Deployment Guide

**Version**: 4.4.0  
**Last Update**: October 11, 2025

---

## 📋 Table of Contents

1. [Quick Deployment](#quick-deployment)
2. [Step-by-Step Deployment](#step-by-step-deployment)
3. [Post-Deployment Verification](#post-deployment-verification)
4. [Troubleshooting](#troubleshooting)
5. [Server Commands Reference](#server-commands-reference)

---

## 🚀 Quick Deployment

### Connection

```bash
ssh oliviera@toaster
```

### Option 1: Automatic Script (RECOMMENDED)

```bash
cd /home4/oliviera/iot.olution.info/ffp3
git pull origin main
bash DEPLOY_NOW.sh
```

### Option 2: One-Line Deploy

```bash
cd /home4/oliviera/iot.olution.info/ffp3 && git pull && rm -rf vendor/ && composer update --no-dev --optimize-autoloader && php -r "require 'vendor/autoload.php'; echo '\n✅ DEPLOYMENT OK\n';" && cat VERSION
```

---

## 📝 Step-by-Step Deployment

### 1. Connect to Server

```bash
ssh oliviera@toaster
```

### 2. Navigate to Project Directory

```bash
cd /home4/oliviera/iot.olution.info/ffp3
```

### 3. Pull Latest Changes

```bash
echo "📥 Pulling from GitHub..."
git pull origin main
```

### 4. Clean Vendor Directory

```bash
echo "🗑️  Removing corrupted vendor/..."
rm -rf vendor/
```

**Why?** Sometimes the vendor/ directory can become corrupted or contain stale dependencies.

### 5. Install Dependencies

```bash
echo "📦 Installing dependencies..."
composer update --no-dev --optimize-autoloader
```

**Flags explained:**
- `--no-dev`: Don't install development dependencies (PHPUnit, etc.)
- `--optimize-autoloader`: Create optimized autoloader for production

### 6. Verify Installation

```bash
echo "🔍 Verifying installation..."
ls vendor/php-di/ > /dev/null 2>&1 && echo "✅ PHP-DI installed" || echo "❌ PHP-DI missing"
ls vendor/minishlink/ > /dev/null 2>&1 && echo "✅ web-push installed" || echo "❌ web-push missing"
ls vendor/bacon/ > /dev/null 2>&1 && echo "✅ bacon-qr-code installed" || echo "❌ bacon-qr-code missing"
```

### 7. Test Autoload

```bash
echo "🧪 Testing autoload..."
php -r "require 'vendor/autoload.php'; use DI\ContainerBuilder; echo 'OK\n';"
```

**Expected**: `OK` (no errors)

### 8. Check Version

```bash
echo "📌 Deployed version:"
cat VERSION
```

### 9. Verify Permissions

```bash
chmod -R 755 public/
chmod -R 775 var/cache/
```

---

## ✅ Post-Deployment Verification

### 1. Check Dependencies

```bash
ls vendor/ | grep -E "php-di|minishlink|bacon"
```

**Expected output:**
```
bacon/
minishlink/
php-di/
```

### 2. Check Version

```bash
cat VERSION
```

**Expected**: Current version number (e.g., `4.4.0`)

### 3. Test Realtime API

```bash
curl https://iot.olution.info/ffp3/api/realtime/system/health
```

**Expected**: JSON response with `{"online":true,...}`

### 4. Test ESP32 Endpoint

```bash
curl https://iot.olution.info/ffp3/api/outputs/state
```

**Expected**: JSON response with GPIO states `{"4":1,"5":0,...}`

### 5. Test Main Site

```bash
curl -I https://iot.olution.info/ffp3/
```

**Expected**: `HTTP/2 200` or `HTTP/2 301`

### 6. Browser Testing

Open in browser: https://iot.olution.info/ffp3/

**Verify:**
- ✅ Page loads (no 500 error)
- ✅ LIVE badge visible in top-right
- ✅ Badge turns green after 15 seconds
- ✅ System health dashboard displays metrics
- ✅ Console (F12) shows `[RealtimeUpdater]` logs
- ✅ No JavaScript errors

---

## 🐛 Troubleshooting

### Error: "Class DI\ContainerBuilder not found"

**Cause**: Incomplete or corrupted vendor/ directory

**Solution:**
```bash
cd /home4/oliviera/iot.olution.info/ffp3
rm -rf vendor/
composer install --no-dev --optimize-autoloader
composer dump-autoload --optimize
```

### Error: "composer: command not found"

**Check if composer exists:**
```bash
which composer
```

**If absent, install:**
```bash
curl -sS https://getcomposer.org/installer | php
php composer.phar update --no-dev --optimize-autoloader
```

### Error: "Memory limit exceeded"

```bash
php -d memory_limit=512M /usr/local/bin/composer update --no-dev
```

### Error: "Permission denied"

```bash
# Check current permissions
ls -la

# Fix permissions
chmod -R 755 .
chmod -R 775 var/cache/
```

### Error: "Fatal error" in PHP

**Check error logs:**
```bash
tail -n 50 /home4/oliviera/iot.olution.info/ffp3/error_log
tail -n 50 /home4/oliviera/iot.olution.info/ffp3/public/error_log
tail -n 50 /home4/oliviera/iot.olution.info/ffp3/cronlog.txt
```

### Database Connection Issues

**Test database connection:**
```bash
mysql -u oliviera_iot -p -e "SELECT 1"
```

**Check .env configuration:**
```bash
grep -E "DB_HOST|DB_NAME|DB_USER" .env
```

### Apache/Web Server Issues

**Check Apache status:**
```bash
systemctl status httpd
# or
systemctl status apache2
```

**Restart if needed:**
```bash
sudo systemctl restart httpd
```

**Check open ports:**
```bash
netstat -tlnp | grep -E '80|443'
```

---

## 📚 Server Commands Reference

### Check Logs

```bash
# PHP error logs
tail -n 50 error_log

# Public error logs
tail -n 50 public/error_log

# CRON logs
tail -n 50 cronlog.txt

# Follow logs in real-time (Ctrl+C to stop)
tail -f error_log
```

### Check Configuration

```bash
# Check API Key
grep "API_KEY" .env

# Check database config
grep -E "DB_HOST|DB_NAME|DB_USER" .env

# Check environment (prod/test)
grep "ENV=" .env
```

### Database Commands

```bash
# Connect to MySQL
mysql -u oliviera_iot -p

# Check last received data
mysql -u oliviera_iot -p -e "
USE oliviera_iot;
SELECT 
    reading_time, 
    sensor,
    TIMESTAMPDIFF(MINUTE, reading_time, NOW()) as minutes_ago
FROM ffp3Data 
ORDER BY reading_time DESC 
LIMIT 1;"
```

### Restart Services

```bash
# Restart Apache
sudo systemctl restart httpd

# Check Apache status
sudo systemctl status httpd

# Restart MySQL (if necessary)
sudo systemctl restart mysql
```

### Disk Space

```bash
# Check disk usage
df -h

# Check project directory size
du -sh /home4/oliviera/iot.olution.info/ffp3
```

---

## 📦 Dependencies

The following packages will be installed:

| Package | Version | Size | Usage |
|---------|---------|------|-------|
| php-di/php-di | ^7.0 | ~1 MB | Dependency injection container (required) |
| minishlink/web-push | ^8.0 | ~500 KB | Push notifications |
| bacon/bacon-qr-code | ^2.0 | ~300 KB | QR code generation |
| + transitive dependencies | - | ~2 MB | Support |

**Total**: ~3-5 MB

---

## ✅ Deployment Checklist

Before considering deployment complete:

- [ ] SSH connection established
- [ ] `git pull origin main` completed
- [ ] `rm -rf vendor/` executed
- [ ] `composer update` completed without errors
- [ ] `ls vendor/php-di/` shows directory exists
- [ ] `php -r "require 'vendor/autoload.php';"` no errors
- [ ] Website opens in browser
- [ ] LIVE badge visible
- [ ] System health dashboard displays metrics
- [ ] No errors in browser console
- [ ] ESP32 continues to function

---

## 🔄 Rollback Procedure

If deployment fails and you need to rollback:

```bash
# 1. Go to previous commit
git log --oneline -5  # Find previous version
git checkout <previous-commit-hash>

# 2. Reinstall dependencies
rm -rf vendor/
composer install --no-dev --optimize-autoloader

# 3. Verify
php -r "require 'vendor/autoload.php'; echo 'OK\n';"
```

---

## 📖 Additional Documentation

- **ESP32 API**: See `ESP32_GUIDE.md`
- **README**: Main project documentation
- **CHANGELOG**: Version history
- **ENVIRONNEMENT_TEST**: PROD/TEST environment guide

---

## 🎯 Quick Reference Card

### Essential Commands

```bash
# Deploy
cd /home4/oliviera/iot.olution.info/ffp3 && git pull && bash DEPLOY_NOW.sh

# Check version
cat VERSION

# Test autoload
php -r "require 'vendor/autoload.php'; echo 'OK\n';"

# View logs
tail -f error_log

# Restart Apache
sudo systemctl restart httpd
```

### Essential URLs

- **Main Site**: https://iot.olution.info/ffp3/
- **Dashboard**: https://iot.olution.info/ffp3/dashboard
- **Control**: https://iot.olution.info/ffp3/aquaponie-control
- **System Health API**: https://iot.olution.info/ffp3/api/realtime/system/health
- **GPIO State API**: https://iot.olution.info/ffp3/api/outputs/state

---

**End of Deployment Guide**  
**© 2025 olution | FFP3 Aquaponie IoT System**

