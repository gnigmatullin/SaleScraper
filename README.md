# Sale Scraper

A PHP-based scraping tool for collecting product data from sale websites and exporting it to cloud storage services.

## 🚀 Overview

Sale Scraper is designed to automatically extract product information such as:

* Brand
* Product name
* SKU
* Price
* Quantity

The collected data is processed and uploaded to cloud storage platforms like **Google Drive** or **Amazon S3** for further use.

---

## ✨ Features

* Automated scraping from e-commerce / sale websites
* Structured product data extraction
* Cloud export support:

  * Google Drive
  * Amazon S3
* Email notifications support
* MySQL database integration

---

## 🛠 Tech Stack

* PHP (7.0+)
* MySQL (5.6+)
* Composer

### 📦 Dependencies

* google/apiclient
* aws/aws-sdk-php
* phpmailer/phpmailer

---

## ⚙️ Installation

### 1. Clone the repository

```bash
git clone https://github.com/gnigmatullin/SaleScraper.git
cd SaleScraper
```

### 2. Install dependencies

```bash
composer install
```

### 3. Setup database

* Create a MySQL database
* Import schema:

```bash
runwaysale.sql
```

* Configure database connection in project config

---

## ▶️ Usage

1. Configure target website and scraping parameters
2. Run the scraper:

```bash
php index.php
```

3. Data will be:

* Stored in database
* Uploaded to configured cloud storage

---

## 🔮 Future Improvements

* Add support for more cloud providers
* Improve scraping performance
* Add web interface for monitoring
* Implement scheduling (cron jobs)

---

## 👨‍💻 Author

GitHub: https://github.com/gnigmatullin
