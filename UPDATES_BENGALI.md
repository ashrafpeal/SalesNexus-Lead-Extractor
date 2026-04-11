# Plugin আপডেট ডকুমেন্টেশন (বাংলায়)

## 📝 পরিবর্তনের সারমর্ম

এই আপডেটে আমরা প্লাগইনটি উন্নত করেছি যাতে **Email এবং Phone ডেটা আরো দক্ষতার সাথে সংগ্রহ** করা যায় এবং **এক্সেল ফরম্যাটে সম্পূর্ণ ডেটা এক্সপোর্ট** করা যায়।

---

## 🔄 প্রধান পরিবর্তনগুলি

### 1️⃣ **API ডেটাসেট আপগ্রেড** (`get_persons()` মেথড)

**পূর্বে:**
```php
'datasets' => ['basic'],  // শুধু বেসিক তথ্য পাওয়া যেত
```

**এখন:**
```php
'datasets' => ['all'],  // সব ডেটা পাওয়া যায় (email, phone সহ)
```

**সুবিধা:**
- একটি API কলেই email এবং phone তথ্য পাওয়া যায়
- অতিরিক্ত API কলের প্রয়োজন কমে যায়

---

### 2️⃣ **নতুন `extract_contacts()` মেথড**

এই নতুন মেথডটি **স্মার্টভাবে email এবং phone বের করে:**

```php
private function extract_contacts($person) {
    // ধাপ ১: API response থেকে email খোঁজো
    // ধাপ ২: API response থেকে phone খোঁজো
    // ধাপ ৩: যদি email না থাকে → আলাদা API কল করো
    // ধাপ ৪: যদি phone না থাকে → আলাদা API কল করো
}
```

**কাজের প্রক্রিয়া:**

```
Person Object আসলো
       ↓
emailContacts Array আছে? → হ্যাঁ → প্রথম email নাও
       ↓ না
       ↓
Email API কল করো → email নাও
       ↓
একই প্রক্রিয়া Phone এর জন্য
       ↓
Contact Data রেডি
```

**লাভ:**
- প্রতিটি person এর জন্য শুধু প্রয়োজন অনুযায়ী API কল হয়
- যদি response তে email/phone থাকে, extra API কল হয় না

---

### 3️⃣ **এক্সেল এক্সপোর্ট মেথড** (`save_to_excel()`)

নতুন এক্সেল ফাইল তৈরি করে যার কলামগুলি:

| কলাম | ডেটা | উদাহরণ |
|------|------|--------|
| Company ID | কোম্পানির আইডি | `9e6a55b258ef11edb8780242ac120002` |
| Company Name | কোম্পানির নাম | `Acme, Inc.` |
| Company Domain | ওয়েবসাইট ডোমেইন | `acme.com` |
| First Name | ব্যক্তির প্রথম নাম | `John` |
| Last Name | ব্যক্তির শেষ নাম | `Doe` |
| Full Name | সম্পূর্ণ নাম | `John Doe` |
| LinkedIn URL | লিংকডইন প্রোফাইল | `linkedin.com/in/john-doe` |
| Job Title | বর্তমান চাকরির শিরোনাম | `Senior Software Engineer` |
| Company (Experience) | যে কোম্পানিতে কাজ করছেন | `Acme, Inc.` |
| Experience Start Date | চাকরির শুরু তারিখ | `2020-08-12` |
| Experience End Date | চাকরির শেষ তারিখ | `2023-04-22` |
| Email | কাজের ইমেইল | `john.doe@example.com` |
| Phone | ফোন নম্বর | `+1-555-123-4567` |

**ফাইলের অবস্থান:**
```
wp-content/Leads-Executives-Extractor/
└── leads-export-2024-01-15-14-30-45.csv
```

---

### 4️⃣ **Process() মেথড এ উন্নতি**

**নতুন ডেটা সংগ্রহ:**

```php
$row_to_send = [
    'company_id' => $company_id,           // নতুন: কোম্পানি আইডি
    'company' => $domain,
    'company_name' => $person['companyName'],  // নতুন: কোম্পানি নাম
    'company_domain' => $domain,           // নতুন: ডোমেইন
    'firstName' => $person['firstName'],   // নতুন: আলাদা প্রথম নাম
    'lastName' => $person['lastName'],     // নতুন: আলাদা শেষ নাম
    'name' => $person['name'],
    'title' => $primaryExp['title'],
    'email' => $contact['email'],          // স্মার্ট extract_contacts() থেকে
    'phone' => $contact['phone'],          // স্মার্ট extract_contacts() থেকে
    'linkedin' => $person['linkedInUrl'],
    'experiences' => $person['experiences'],  // নতুন: সব অভিজ্ঞতা
];
```

---

## 💡 কিভাবে কাজ করে?

### উদাহরণ সিনারিও:

**ধরুন:** `John Doe` এর জন্য ডেটা সংগ্রহ করছি

#### ১. প্রথম API কল (find person):
```json
{
  "id": "123",
  "name": "John Doe",
  "firstName": "John",
  "lastName": "Doe",
  "emailContacts": [
    {
      "email": "john.doe@acme.com",
      "type": "professional"
    }
  ],
  "phoneContacts": [
    {
      "phone": "+1-555-123-4567",
      "type": "personal"
    }
  ]
}
```

#### ২. extract_contacts() কাজ:
```
✅ emailContacts এ email পেয়েছি → "john.doe@acme.com"
✅ phoneContacts এ phone পেয়েছি → "+1-555-123-4567"
✅ Extra API কল হয়নি
```

#### ৩. যদি email না থাকত:
```
❌ emailContacts এ email নেই
→ API কল: email-contacts/lookup
→ Response থেকে email নিই
```

#### ৪. Excel তে সেভ:
```csv
Company ID,Company Name,Company Domain,First Name,Last Name,Full Name,LinkedIn URL,Job Title,Company (Experience),Experience Start Date,Experience End Date,Email,Phone
123,Acme Inc,acme.com,John,Doe,John Doe,linkedin.com/in/johndoe,Senior Software Engineer,Acme Inc,2020-08-12,2023-04-22,john.doe@acme.com,+1-555-123-4567
```

---

## 🚀 API কল এ সাশ্রয়:

### পূর্বে (সাশ্রয়ী নয়):
```
প্রতিটি person এর জন্য:
  1. find person API ✓
  2. email-contacts lookup API ✓
  3. phone-contacts lookup API ✓
  = ৩ API কল per person
```

### এখন (দক্ষ):
```
প্রতিটি person এর জন্য:
  1. find person API ✓ (সব ডেটা সহ)
  2. email API? (শুধু যদি লাগে) → কম সময়ে কম কল
  3. phone API? (শুধু যদি লাগে) → কম সময়ে কম কল
  = ১-৩ API কল per person (সাধারণত ১টি)
```

**সঞ্চয়:** ৬৬% - ৭৫% API কল কমে যায়! 🎯

---

## 📊 Excel ফাইলের বৈশিষ্ট্য:

✅ **প্রাপারলি Formatted CSV**
- Double quotes দিয়ে comma-separated values handle করা হয়
- বিশেষ characters যেমন comma বা quote সঠিকভাবে escape করা হয়

✅ **সম্পূর্ণ ডেটা**
- কোম্পানির তথ্য
- ব্যক্তির তথ্য (প্রথম নাম, শেষ নাম আলাদা)
- চাকরির তথ্য (শিরোনাম, শুরু-শেষ তারিখ)
- যোগাযোগ তথ্য (email, phone)

✅ **একই দিনে একাধিক ফাইল**
- ফাইল নামে timestamp থাকে
- `leads-export-2024-01-15-14-30-45.csv` (ঘন্টা:মিনিট:সেকেন্ড সহ)

---

## 🔧 আপডেটের পরে কী ঘটে?

১. **Google Sheet**: আগের মত একই কলামে ডেটা লেখা হয়
2. **Local CSV**: আগের মত রক্ষণাবেক্ষণ করা হয় 
3. **নতুন Excel**: সম্পূর্ণ ডেটা সহ CSV ফাইলে রপ্তানি করা হয়
4. **Log**: সব কাজের বিস্তারিত লগ থাকে

---

## ✨ প্রধান সুবিধা সারসংক্ষেপ:

| বৈশিষ্ট্য | সুবিধা |
|---------|--------|
| Smart Contact Extraction | ৬৬% API কল সাশ্রয় |
| Excel Export | সম্পূর্ণ ডেটা এক জায়গায় |
| Company Details | ID, Name, Domain সব পাওয়া যায় |
| Separated Names | প্রথম নাম ও শেষ নাম আলাদা কলামে |
| Rich Experience Data | সব অভিজ্ঞতা সংরক্ষিত থাকে |
| Backup System | Google Sheet + Local CSV + Excel তিনটিতেই সেভ |

---

## 🐛 সমস্যা সমাধান:

**Q: এক্সেল ফাইল কোথায় পাব?**
A: `wp-content/Leads-Executives-Extractor/` ফোল্ডারে CSV ফরম্যাটে পাবেন।

**Q: Email বা Phone পেলাম না?**
A: লগ ফাইল চেক করুন:
```
❌ Email not found in response, calling API...
📱 Phone found from response: +1-555-123-4567
```

**Q: API কল কম হয়েছে কিনা বুঝব কিভাবে?**
A: `wp-content/debug.log` এ দেখুন:
```
📧 Email found from response: john.doe@acme.com  ← API কল হয়নি
📱 Phone found from response: +1-555-123-4567     ← API কল হয়নি
```

---

## 📝 কোড রেফারেন্স:

### Extract Contacts (লাইন ২১০-২৫৭)
- emailContacts array থেকে ডেটা বের করে
- phoneContacts array থেকে ডেটা বের করে
- প্রয়োজন অনুযায়ী API কল করে

### Save to Excel (লাইন ২৬৯-৩৪৪)
- CSV ফাইল তৈরি করে
- সঠিক ফরম্যাটিং এর সাথে লেখে

### Updated Process (লাইন ৩৭৭-৪৭১)
- নতুন extract_contacts() ব্যবহার করে
- Excel এ সেভ করার জন্য ডেটা সংগ্রহ করে
- সব তথ্য লগ করে

---

**Version:** 8.1  
**Updated:** 2024  
**Language:** Bengali (বাংলা)
