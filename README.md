# SaleScraper

A PHP-based distributed scraping system for collecting product and pricing data from e-commerce sale platforms. Built to handle multiple sources concurrently with automated data export to cloud storage.

## Overview

Scrapes structured product data (brand, name, SKU, price, quantity) from 60+ retail and sale websites. Data is processed and exported to Google Drive or Amazon S3 for downstream use.

**Sources include:** Adidas, Nike, Puma, Reebok, Lacoste, Tommy Hilfiger, Diesel, Vans, Converse, Timberland, Under Armour, New Balance, Skechers, and 50+ more.

## Architecture

Each source has its own isolated scraper module under `/[source-name]/`, sharing a common library in `/lib/`. This allows independent updates per source without affecting others.

```
SaleScraper/
├── lib/              # Shared scraping utilities
├── adidas/           # Source-specific scrapers
├── nike/
├── [60+ sources]/
├── index.php         # Entry point
└── crontabs          # Scheduling config
```

## Stack

`PHP 7.0+` `MySQL` `Google Drive API` `Amazon S3` `PHPMailer` `Composer`

## Features

- Per-source scraper modules — easy to add or update individual sources
- Structured data extraction: brand, product name, SKU, price, quantity
- Cloud export: Google Drive and Amazon S3
- MySQL storage with schema included
- Email notifications on completion or errors
- Cron-based scheduling

## Installation

```bash
git clone https://github.com/gnigmatullin/SaleScraper.git
cd SaleScraper
composer install
```

Import the database schema:

```bash
mysql -u root -p your_database < runwaysale.sql
```

Configure your database connection and cloud credentials in the project config, then run:

```bash
php index.php
```

Or schedule via cron — see `crontabs` for reference configuration.

## Related projects

This scraper is part of a broader data infrastructure background. For larger-scale distributed scraping architecture (100K+ pages/week, RabbitMQ, Kubernetes), see my [Upwork portfolio](https://www.upwork.com/freelancers/gazizn).

---

[LinkedIn](https://www.linkedin.com/in/gaziz-nigmatullin/) · [Upwork](https://www.upwork.com/freelancers/gazizn)
