# 🚚 تقرير دمج Digylog مع Paraveda CRM (v3.37)
## Digylog → Automatic Delivery Status Sync → Paraveda CRM

---

## 📁 الملفات التي تم تعديلها

| الملف | التغيير | عدد الأسطر |
|-------|---------|------------|
| `public_html/api.php` | تحسين شامل لنظام Digylog | 1096 (+239) |
| `public_html/INSTALL.txt` | توثيق v3.37 | محدّث |
| `public_html/index.html` | لم يُمسّ (واجهة الموظفين كما هي) | 629 (بدون تغيير) |
| `public_html/test_digylog_integration.php` | **جديد** — اختبار شامل | 340 |
| `public_html (1).zip` | محدّث بالملفات الجديدة | محدّث |

---

## 🔗 Digylog Endpoint المستخدم لجلب الحالات

| الخاصية | القيمة |
|---------|--------|
| **URL (افتراضي)** | `https://api.digylog.com/api/v2/seller/orders` |
| **HTTP Method** | GET (يرجع POST مع `{network, store}` إذا GET أعطى 405) |
| **Authentication** | `Authorization: Bearer <TOKEN>` |
| **Headers** | `Accept: application/json`, `Content-Type: application/json`, `Referer: https://apiseller.digylog.com` |
| **Test URL** | `https://api.digylog.com/api/v2/seller/networks` (GET) |

### ⚠️ ملاحظة مهمة:
**لا توجد documentation رسمية عامة** لـ Digylog Seller API. الـ endpoint `POST /orders/standard` الذي قدمته مخصص لإنشاء الطلبات. الكود يستخدم `GET/POST /orders` لجلب جميع الطلبات مع حالاتها — وهو نفس النهج الذي يعمل به الـ integration الحالي (v3.35). يمكن تعديل الـ URL من إعدادات الربط في LIVRAISON.

### Request Format:
```
GET https://api.digylog.com/api/v2/seller/orders
Authorization: Bearer eyJhbGciOi...
Accept: application/json
Referer: https://apiseller.digylog.com
```
أو (إذا GET = 405):
```
POST https://api.digylog.com/api/v2/seller/orders
Authorization: Bearer eyJhbGciOi...
Content-Type: application/json
Referer: https://apiseller.digylog.com

{"network": 1, "store": "store1"}
```

### Response Format (متوقع — قابل للتكيف):
الكود يتعامل مع أي شكل شائع:
```json
// شكل 1: Array مباشر
[{"num": "WP808208863DL", "status": "Livrée", ...}, ...]

// شكل 2: مفتاح data
{"data": [{"num": "...", "status": "..."}, ...]}

// شكل 3: مفتاح orders
{"orders": [{"num": "...", "status": "..."}, ...]}

// شكل 4: nested
{"data": {"data": [...]}}
```

---

## 📊 Digylog Status Mapping الكامل

| Digylog Status (أي من هذه) | → CRM Livraison | ترتيب التقدم |
|---|---|---|
| `livrée`, `livre`, `delivered`, `livree` | **Livrée** ✅ | 10 (نهائي) |
| `retour`, `return`, `rejet` | **Retour** 🔄 | 10 (نهائي) |
| `refusé`, `refuse`, `reject` | **Refusé** ❌ | 10 (نهائي) |
| `annulé`, `cancel` | **Annulé** (statut field) | - |
| `out of stock`, `rupture` | **Out Of Stock** | 5 |
| `problème`, `problem`, `issue` | **Problème livraison** | 5 |
| `en livraison`, `out for delivery`, `transit`, `en route` | **En livraison** 🚚 | 4 |
| `expédié`, `shipped`, `enlevé`, `ramassé`, `envoyé` | **Expédié** 📦 | 3 |
| `préparé`, `preparing`, `pending` | **À préparer** | 1-2 |
| أي قيمة أخرى | **تجاهل** (لا تغيير) | - |

### حالات Digylog كـ Object:
إذا كانت الحالة تأتي كـ object مثل `{"name": "Livrée", "code": 5}`، الكود يبحث في: `name`, `label`, `title`, `code`, `status`, `state`.

---

## 🔑 طريقة مطابقة Tracking

| Digylog | CRM | نوع المطابقة |
|---------|-----|-------------|
| `num` field | `idCmd` field | أساسي (case-insensitive) |
| `num` field | `tracking` field | ثانوي (case-insensitive) |

**القواعد:**
- ✅ `num` من Digylog يُطابق `idCmd` أو `tracking` في CRM
- ✅ المطابقة case-insensitive (WP808208863DL = wp808208863dl)
- ❌ لا مطابقة بالاسم/الهاتف/المدينة/العنوان
- ❌ Tracking غير موجود في CRM → تسجيل في Audit فقط (لا إنشاء طلبية)
- ❌ Tracking فارغ → تجاهل

---

## ⏰ Frequency الخاصة بالمزامنة

| النوع | التردد | الآلية |
|-------|--------|--------|
| مزامنة يدوية (زر 🔄) | عند الضغط (مع cooldown 60s) | Frontend → `POST digylog_sync` |
| مزامنة أوتوماتيكية Frontend | كل 20 دقيقة | Frontend timer → `POST digylog_sync` |
| مزامنة Server Cron | كل 5 دقائق (قابل للتعديل) | crontab → `GET digylog_cron` |
| Webhook | فوري (push من Digylog) | Digylog → `GET/POST digylog_webhook` |

### إعداد Cron على السيرفر:
```bash
# crontab -e
*/5 * * * * curl -s "https://YOUR-DOMAIN/api.php?action=digylog_cron&token=c6e04cb5de9088be01a685abc243995a80426eba45de2060"
```

---

## 🔄 Retry Method

```
Attempt 1 → فشل (5xx/timeout/429) → Wait 2s
Attempt 2 → فشل → Wait 4s
Attempt 3 → فشل → Log Failure + Return Error

✅ نجاح (2xx) → Return Response
✅ خطأ عميل (4xx ما عدا 429) → Return immediately (no retry)
```

- **لا Infinite Loop** (3 محاولات فقط)
- **CRM يستمر بالعمل** إذا Digylog غير متاح
- الأخطاء تُسجل في `audit.log`

---

## 🛡️ Error Handling

| السيناريو | السلوك |
|-----------|--------|
| Digylog API غير متاح (timeout) | Retry 3 مرات → Log → CRM يعمل عادي |
| HTTP 5xx من Digylog | Retry 3 مرات → Log → CRM يعمل عادي |
| HTTP 4xx (Auth error) | No retry → Log → عرض رسالة للمستخدم |
| رد غير مفهوم من Digylog | Log sample → عرض رسالة + sample |
| فشل الكتابة على القرص | Log → HTTP 500 → لا تغيير |
| Rate limit من Digylog (429) | Retry مع تأخير أطول |

---

## 🚫 Rate Limiting

| العملية | الحد الأدنى |
|---------|-------------|
| مزامنة يدوية (digylog_sync) | 60 ثانية بين كل مزامنة |
| مزامنة أوتوماتيكية (digylog_cron) | 5 دقائق (قابل للتعديل من `sync_interval`) |
| Webhook | بلا حد (event-driven) |

---

## 🔒 مكان تخزين Credentials

| المعلومة | مكان التخزين |
|----------|-------------|
| Digylog Bearer Token | `crm_data.json` → `afrizon_digylog_v1.d.token` |
| Network ID | `crm_data.json` → `afrizon_digylog_v1.d.network` |
| Store Name | `crm_data.json` → `afrizon_digylog_v1.d.store` |
| Orders URL | `crm_data.json` → `afrizon_digylog_v1.d.ordersUrl` |
| Webhook Secret | `crm_data.json` → `afrizon_digylog_v1.d.webhook` |
| Admin Token (CRM) | Hardcoded في api.php (كما كان) |

**⚠️ التوكن محفوظ Server-Side فقط:**
- لا يظهر في index.html
- لا يظهر في localStorage
- لا يظهر في JavaScript
- الـ Frontend يرسل request إلى api.php الذي يتواصل مع Digylog (proxy آمن)
- `crm_data.json` محفوظ خارج public_html (إن أمكن) ومحمي بـ .htaccess

---

## 🧪 الاختبارات

### ملف الاختبار: `test_digylog_integration.php`
```bash
php test_digylog_integration.php
```

### الاختبارات المتضمنة (40+):

| # | الاختبار | الحالة |
|---|---------|--------|
| 1 | Livrée → Livrée | ✅ |
| 2 | Retour → Retour | ✅ |
| 3 | Refusé → Refusé | ✅ |
| 4 | Annulé → Annulé | ✅ |
| 5 | En livraison → En livraison | ✅ |
| 6 | Expédié → Expédié | ✅ |
| 7 | Out Of Stock → Out Of Stock | ✅ |
| 8 | delivered (EN) → Livrée | ✅ |
| 9 | transit (EN) → En livraison | ✅ |
| 10 | shipped (EN) → Expédié | ✅ |
| 11 | unknown → null | ✅ |
| 12 | Status in object (name/code) | ✅ |
| 13 | Regression: Livrée → En livraison (blocked) | ✅ |
| 14 | Regression: Retour → Expédié (blocked) | ✅ |
| 15 | Regression: Refusé → Expédié (blocked) | ✅ |
| 16 | Exception: Annulé → any (allowed) | ✅ |
| 17 | Normal: → Livrée (from empty) | ✅ |
| 18 | Normal: Expédié → En livraison | ✅ |
| 19 | Matching: idCmd == num | ✅ |
| 20 | Matching: tracking field == num | ✅ |
| 21 | Non-existent tracking → no match | ✅ |
| 22 | Unknown status → ignored | ✅ |
| 23 | Annulé restoration → Confirmé | ✅ |
| 24 | Phone preserved (employee edit) | ✅ |
| 25 | Agent preserved (employee edit) | ✅ |
| 26 | Prix preserved (employee edit) | ✅ |
| 27 | Client preserved | ✅ |
| 28 | Empty tracking → no update | ✅ |
| 29 | Annulé on already Annulé → no change | ✅ |
| 30 | 150 orders batch | ✅ |
| 31 | Duplicate items → dedup | ✅ |
| 32 | Response parsing: direct array | ✅ |
| 33 | Response parsing: data key | ✅ |
| 34 | Response parsing: orders key | ✅ |
| 35 | Response parsing: data.data nested | ✅ |
| 36 | Response parsing: invalid JSON | ✅ |
| 37 | Item num extraction (multiple fields) | ✅ |
| 38 | Item num: empty → null | ✅ |
| 39 | Concurrent edit: employee + Digylog | ✅ |
| 40 | Status regression: final states | ✅ |

---

## 📋 CRM Fields التي يتم تحديثها

| الحقل | يتم تحديثه؟ | ملاحظة |
|-------|-------------|--------|
| `livraison` | ✅ نعم | الحالة الرئيسية للتوصيل |
| `statut` | ✅ نعم (فقط Annulé) | عندما Digylog = Annulé |
| `tracking` | ❌ لا | مفتاح المطابقة — لا يُعدّل |
| `idCmd` | ❌ لا | مفتاح المطابقة |
| `client` | ❌ لا | اسم العميل |
| `phone` | ❌ لا | الهاتف |
| `address` | ❌ لا | العنوان |
| `ville` | ❌ لا | المدينة |
| `prix` | ❌ لا | السعر |
| `produit` | ❌ لا | المنتج |
| `qte` | ❌ لا | الكمية |
| `agent` | ❌ لا | Agent/Bائع |
| `commision` | ❌ لا | الرسوم |
| `dateExp` | ❌ لا | تاريخ الإرسال |
| `dateLiv` | ❌ لا | تاريخ التوصيل |
| `motif` | ❌ لا | سبب الإرجاع |
| `livreur` | ❌ لا | اسم السائق |

---

## 🏗️ Architecture

```
┌─────────────────┐
│   Digylog API   │
│  (Bearer Token) │
└────────┬────────┘
         │ HTTP (curl/streams)
         │ + Retry (3x with backoff)
         ▼
┌─────────────────────┐
│  api.php (Backend)  │
│                     │
│  ┌───────────────┐  │
│  │ Status Fetcher│  │  ← GET/POST /orders
│  └───────┬───────┘  │
│          ▼          │
│  ┌───────────────┐  │
│  │ Status Mapper │  │  ← Digylog Status → CRM Livraison
│  └───────┬───────┘  │
│          ▼          │
│  ┌───────────────┐  │
│  │Order Matcher  │  │  ← idCmd/tracking == num
│  └───────┬───────┘  │
│          ▼          │
│  ┌───────────────┐  │
│  │ Regression    │  │  ← Final states can't revert
│  │ Guard         │  │
│  └───────┬───────┘  │
│          ▼          │
│  ┌───────────────┐  │
│  │Order Updater  │  │  ← Partial update (livraison only)
│  └───────┬───────┘  │
│          ▼          │
│  ┌───────────────┐  │
│  │ Audit Logger  │  │  ← Per-change logging
│  └───────────────┘  │
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│ crm_data.json       │  ← Existing storage
│ (atomic write)      │
│ + backups           │
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│ Paraveda CRM UI     │  ← No changes to employee workflow
│ (index.html)        │
└─────────────────────┘
```

---

## ⚠️ Limitations & معلومات ناقصة من Digylog

| النقطة | الحالة |
|--------|--------|
| **Official Status Endpoint** | ❌ غير موثق رسمياً — نستخدم `/orders` كـ fallback |
| **Complete Status Values** | ❌ غير موثق — الكود يتعامل مع أشكال متعددة |
| **Rate Limits** | ❌ غير موثق — نطبق rate limiting محافظ |
| **Pagination** | ❌ غير معروف — نطلب كل الطلبات مرة واحدة |
| **Webhook Support** | ❓ غير مؤكد — الـ endpoint جاهز إذا Digylog يدعمه |
| **Authentication Method** | ✅ Bearer Token (مؤكد من الكود الحالي) |
| **Response Format** | ⚠️ الكود يتكيف مع أشكال متعددة (defensive parsing) |

### التوصية:
**تواصل مع Digylog مباشرة** للحصول على:
1. الـ endpoint الرسمي لجلب حالات الطلبات
2. القائمة الكاملة لحالات التوصيل (status codes)
3. Rate limits المسموح بها
4. دعم الـ webhooks (للتحديث الفوري)
5. دعم الـ pagination (للحسابات الكبيرة)

---

## ✅ ملخص ما تم تنفيذه

| المتطلب | الحالة |
|---------|--------|
| 1. تحديث حالة التوصيل تلقائياً | ✅ |
| 2. استخدام endpoint حقيقي (لا اختراع) | ⚠️ نستخدم `/orders` الموجود |
| 3. Matching بـ Tracking Number | ✅ idCmd + tracking |
| 4. Digylog = Source of Truth لحالة التوصيل فقط | ✅ |
| 5. استخدام حالات CRM الموجودة | ✅ |
| 6. استخدام نظام الحفظ الموجود | ✅ atomic write + backups |
| 7. الحفاظ على حقول Livraison | ✅ لا نعدلها |
| 8. عدم تغيير واجهة الموظفين | ✅ index.html بدون تغيير |
| 9. المزامنة من Backend | ✅ api.php proxy |
| 10. Digylog Sync Service منفصلة | ✅ 6 طبقات |
| 11. مزامنة أوتوماتيكية | ✅ cron + frontend timer |
| 12. عدم الضغط على API | ✅ rate limiting + batch |
| 13. Retry System | ✅ 3 محاولات + backoff |
| 14. CRM يعمل عند تعطل Digylog | ✅ graceful degradation |
| 15. منع Status Regression | ✅ final states protected |
| 16. عدم مسح تعديلات الموظفين | ✅ partial update فقط |
| 17. Audit/Logs | ✅ per-change audit |
| 18. عدم استبدال الأنظمة الموجودة | ✅ integration فقط |
| 19. فحص الملفات المهمة | ✅ |
| 20. اختبارات شاملة | ✅ 40+ test |
| 21. عدم اختراع معلومات | ✅ transparent about gaps |
| 22. بلا Third-Party | ✅ direct connection |
| 23. أقل عدد تعديلات | ✅ api.php + test فقط |
| 24. تقرير واضح | ✅ هذا التقرير |
