# Google Indexing Bulk Plugin

A custom WordPress plugin that automatically submits **new or updated pages** to the **Google Indexing API** for fast indexing.  
Supports bulk submissions, auto-trigger on publish, and manual re-submission.

---

## 🚀 Features
- Auto-submit new posts/pages to Google Indexing API when published.
- Bulk submit multiple URLs.
- Supports re-submission of updated content.
- Clear logging of success/failure.

---

## 📦 Installation

1. Go to the **[Releases page](../../releases/latest)** of this repository.
2. Download the file:
google-indexing-bulk.zip
3. In your WordPress admin:
- Go to **Plugins → Add New → Upload Plugin**.
- Upload the `google-indexing-bulk.zip` file.
- Click **Install Now** and then **Activate**.

---

## 🔑 Google API Setup (required)
1. Go to [Google Cloud Console](https://console.cloud.google.com/).
2. Create a **new project** (or use an existing one).
3. Enable the **Indexing API** for the project.
4. Go to **APIs & Services → Credentials**:
- Create a **Service Account**.
- Download the JSON key file.
5. In Google Search Console:
- Add your **Service Account email** as an **Owner** of your site.
6. In WordPress:
- Go to **Settings → Google Indexing Bulk**.
- Upload your JSON key.
- Save settings.

---

## ✅ Usage
- When you publish a post/page, it is automatically sent to Google for indexing.
- To bulk submit existing URLs:
- Go to **Tools → Google Indexing Bulk**.
- Paste your URLs and click **Submit**.

---

## 📊 Verification
- You can confirm successful indexing in:
- **WordPress logs (Tools → Google Indexing Bulk → Log)**.
- **Google Search Console → URL Inspection Tool**.
- Or check crawl stats in **Search Console > Settings > Crawl Stats**.

---

## ⚠️ Notes
- Google Indexing API has daily limits (200 URLs/day for standard accounts).
- Avoid spamming with unnecessary submissions — focus on important content.

---

## 🤝 Support
For issues or feature requests, open a [GitHub Issue](../../issues).

---

## 📜 License
MIT License — free to use and modify.
