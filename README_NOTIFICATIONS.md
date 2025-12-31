# إعداد نظام الإشعارات - دليل سريع

## الملفات المتوفرة

### 1. `setup-notifications.bat`
**الاستخدام:** تشغيله مرة واحدة لإعداد النظام
- ✅ يشغل migrations
- ✅ ينشئ جدول queue (إذا لزم الأمر)
- ✅ يحدد إعدادات queue

**كيفية الاستخدام:**
```bash
setup-notifications.bat
```

### 2. `run-notification-worker.bat`
**الاستخدام:** لتشغيل queue worker (مطلوب إذا كان `QUEUE_CONNECTION=database`)
- ✅ يشغل Laravel queue worker
- ✅ يعالج الإشعارات من queue
- ✅ يعيد التشغيل تلقائياً عند تغيير الكود

**كيفية الاستخدام:**
```bash
run-notification-worker.bat
```

**ملاحظة:** اترك هذا الملف يعمل في نافذة منفصلة أثناء استخدام النظام.

### 3. `run-notification-worker-sync.bat`
**الاستخدام:** لتعيين queue إلى sync mode (لا يحتاج queue worker)
- ✅ يغير `QUEUE_CONNECTION` إلى `sync`
- ✅ الإشعارات تُعالج فوراً بدون queue worker

**كيفية الاستخدام:**
```bash
run-notification-worker-sync.bat
```

## خطوات الإعداد السريع

### الطريقة 1: مع Queue Worker (موصى به للإنتاج)

```bash
# 1. إعداد النظام
setup-notifications.bat

# 2. تأكد من QUEUE_CONNECTION=database في .env

# 3. شغل queue worker (في نافذة منفصلة)
run-notification-worker.bat
```

### الطريقة 2: بدون Queue Worker (للاختبار)

```bash
# 1. إعداد النظام
setup-notifications.bat

# 2. تعيين إلى sync mode
run-notification-worker-sync.bat
```

## التحقق من الإعداد

### 1. تحقق من Migration
```bash
php artisan migrate:status
```
يجب أن ترى `2025_12_30_192535_create_notifications_table` في القائمة.

### 2. تحقق من Queue Configuration
```bash
php artisan config:show queue.default
```

### 3. اختبار الإشعارات
1. قم بتعديل منتج ليكون مخزونه أقل من `stock_alert_level`
2. يجب أن تظهر إشعارات للمستخدمين الذين لديهم أدوار `admin` أو `manager`
3. تحقق من الجرس في TopAppBar

## استكشاف الأخطاء

### المشكلة: الإشعارات لا تظهر

**الحل:**
1. تحقق من أن migration تم تشغيله:
   ```bash
   php artisan migrate:status
   ```

2. تحقق من queue worker (إذا كان `QUEUE_CONNECTION=database`):
   - تأكد من أن `run-notification-worker.bat` يعمل
   - تحقق من logs في `storage/logs/laravel.log`

3. تحقق من الأدوار:
   - المستخدمون يجب أن يكون لديهم أدوار `admin` أو `manager`
   ```bash
   php artisan tinker
   >>> $user = User::find(1);
   >>> $user->roles; // يجب أن يعرض admin أو manager
   ```

### المشكلة: Queue Worker لا يعمل

**الحل:**
1. تحقق من أن جدول `jobs` موجود:
   ```bash
   php artisan migrate:status | findstr jobs
   ```

2. إذا لم يكن موجوداً:
   ```bash
   php artisan queue:table
   php artisan migrate
   ```

3. استخدم sync mode كبديل:
   ```bash
   run-notification-worker-sync.bat
   ```

## ملاحظات مهمة

- **Queue Worker**: إذا كان `QUEUE_CONNECTION=database`، يجب أن يعمل queue worker دائماً
- **Sync Mode**: إذا كان `QUEUE_CONNECTION=sync`، لا تحتاج queue worker لكن قد يكون أبطأ
- **الأدوار**: حالياً الإشعارات تُرسل فقط للمستخدمين الذين لديهم أدوار `admin` أو `manager`

## الترقية لاحقاً

إذا أردت إضافة WebSockets للإشعارات الفورية، راجع `NOTIFICATION_ARCHITECTURE.md` في مجلد `sales-ui`.

