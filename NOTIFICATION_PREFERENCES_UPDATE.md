# تحديث نظام الإشعارات - Preferences & All Users

## التحديثات المنجزة

### ✅ 1. إرسال الإشعارات لجميع المستخدمين
- تم تعديل `SendNotificationListener` لإرسال الإشعارات لجميع المستخدمين بدلاً من `admin` و `manager` فقط
- يتم التحقق من تفضيلات المستخدم قبل الإرسال

### ✅ 2. نظام الاشتراكات (Notification Preferences)
- ✅ جدول `notification_preferences` في قاعدة البيانات
- ✅ Model `NotificationPreference` مع دوال مساعدة
- ✅ API endpoints لإدارة التفضيلات
- ✅ Frontend component لإدارة التفضيلات
- ✅ زر Settings في NotificationPanel

## الملفات الجديدة

### Backend:
1. `database/migrations/2025_12_30_211124_create_notification_preferences_table.php`
2. `app/Models/NotificationPreference.php`
3. `app/Http/Controllers/Api/NotificationPreferenceController.php`

### Frontend:
1. `src/services/notificationPreferenceService.ts`
2. `src/components/notifications/NotificationPreferences.tsx`

## الملفات المعدلة

### Backend:
1. `app/Models/User.php` - إضافة relationship للـ preferences
2. `app/Listeners/SendNotificationListener.php` - تحديث لإرسال لجميع المستخدمين مع التحقق من preferences
3. `routes/api.php` - إضافة routes للـ preferences

### Frontend:
1. `src/components/notifications/NotificationPanel.tsx` - إضافة زر Settings

## خطوات الإعداد

### 1. تشغيل Migration
```bash
cd C:\xampp\htdocs\sales-api
php artisan migrate
```

### 2. تهيئة Preferences للمستخدمين الحاليين (اختياري)
يمكنك إضافة هذا الأمر في `tinker` أو إنشاء command:

```php
// في tinker
php artisan tinker
>>> use App\Models\User;
>>> use App\Models\NotificationPreference;
>>> User::all()->each(function($user) { NotificationPreference::initializeForUser($user); });
```

أو يمكنك إنشاء command:
```bash
php artisan make:command InitializeNotificationPreferences
```

## كيفية الاستخدام

### للمستخدمين:
1. اضغط على أيقونة الجرس في TopAppBar
2. اضغط على أيقونة Settings (⚙️) في رأس لوحة الإشعارات
3. اختر أنواع الإشعارات التي تريد استلامها
4. اضغط "حفظ"

### أنواع الإشعارات المتاحة:
- ✅ مخزون منخفض
- ✅ نفاد المخزون
- ✅ بيع جديد
- ✅ استلام مشتريات
- ✅ طلب مخزون
- ✅ تنبيه انتهاء صلاحية
- ✅ إشعارات النظام
- ✅ تحذيرات
- ✅ أخطاء
- ✅ نجاح

## السلوك الافتراضي

- **عند إنشاء مستخدم جديد**: سيتم تهيئة preferences تلقائياً عند أول طلب
- **القيمة الافتراضية**: جميع أنواع الإشعارات مفعلة (`enabled = true`)
- **إذا لم يكن هناك preference**: يعتبر مفعلاً افتراضياً

## API Endpoints

### GET `/api/notifications/preferences`
جلب تفضيلات المستخدم الحالي

### PUT `/api/notifications/preferences`
تحديث تفضيلات المستخدم
```json
{
  "preferences": {
    "low_stock": true,
    "out_of_stock": false,
    "new_sale": true,
    ...
  }
}
```

### POST `/api/notifications/preferences/{type}/toggle`
تبديل حالة نوع إشعار واحد

## ملاحظات مهمة

1. **الإشعارات تُرسل لجميع المستخدمين** الذين لديهم النوع مفعلاً
2. **Preferences تُهيأ تلقائياً** عند أول طلب
3. **القيمة الافتراضية**: جميع الإشعارات مفعلة
4. **يمكن للمستخدم تعطيل أي نوع** من الإشعارات

## الاختبار

1. قم بتسجيل الدخول كمستخدم عادي
2. افتح لوحة الإشعارات
3. اضغط على Settings
4. عطل بعض أنواع الإشعارات
5. احفظ التغييرات
6. قم بإنشاء حدث (مثل بيع جديد)
7. تحقق من أن الإشعارات تظهر فقط للأنواع المفعلة

