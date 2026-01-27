# 🥗 FreshGuard

> **Keep Your Inventory Fresh**

Your intelligent home stock inventory companion designed to ensure your products reach your table at their peak freshness. Say goodbye to forgotten items, mysterious expiration dates, and wasted food. FreshGuard transforms your pantry into a thriving ecosystem of properly tracked, optimally consumed produce.

## About FreshGuard

FreshGuard is a modern home inventory management system that brings order, awareness, and sustainability to your kitchen. Whether you're a meal-prep enthusiast, a sustainability advocate, or simply someone who hates throwing away good food, FreshGuard puts you in control.

### Key Goals

🎯 **Maximize Freshness** - Track your items with precision, ensuring nothing sits forgotten in the back of your fridge.

📊 **Smart Inventory Management** - Organize items by location, tags, and batches for intuitive access and discovery.

⏰ **Never Miss Expiration** - Stay ahead of expiration dates with intelligent notifications and consumption tracking.

♻️ **Reduce Food Waste** - Transform surplus into savings by knowing exactly what you have and when to use it.

🏠 **Organized Locations** - Categorize your items across multiple storage locations—refrigerator, freezer, pantry, and beyond.

🏷️ **Flexible Tagging** - Create custom tags to classify items by type, dietary preference, or any criteria that matters to you.

## Technical Architecture

FreshGuard is built with modern, battle-tested technologies designed for reliability and maintainability:

### Core Technologies

- **Framework**: Laravel 12 - A powerful PHP framework with expressive syntax and elegant solutions
- **Frontend**: Tailwind CSS v4 - Utility-first CSS for responsive, beautiful interfaces
- **Admin Panel**: Filament - A beautiful TALL stack admin panel built on Laravel
- **Database**: SQLite (development) with support for production databases
- **Language**: PHP 8.4 with strict types and comprehensive type hints

### Architecture Highlights

- **Eloquent ORM** - Elegant database interactions with intuitive relationships
- **Form Requests** - Powerful validation layer for data integrity
- **Service Layer** - Clean separation of concerns with dependency injection
- **PSR-12 Compliance** - Industry-standard PHP coding standards
- **PHPStan Level 10** - Strict static analysis for maximum type safety
- **RESTful Design** - API-first architecture for flexibility and extensibility
- **Modular Structure** - Clear separation between Models, Controllers, and Requests

### Data Models

- **Items** - Your products with metadata, locations, and expiration tracking
- **Batches** - Group items by purchase or storage date for batch management
- **Locations** - Define where items are stored across your home
- **Tags** - Flexible categorization system for intelligent organization
- **Users** - Multi-user support with authentication and authorization

## Getting Started

### Requirements

- PHP 8.5+
- Composer
- Node.js & npm (for frontend assets)

### Installation

The project uses **DDEV** for local development. DDEV is a containerized development environment that simplifies setup and ensures consistency across all developers.

#### Prerequisites

- [DDEV](https://ddev.readthedocs.io/en/stable/) - Install from [here](https://ddev.readthedocs.io/en/stable/#installation)
- Docker (installed automatically with DDEV Desktop)

#### Setup Steps

```bash
# Clone the repository
git clone https://github.com/marcelorodrigo/freshguard.git
cd freshguard

# Start DDEV (automatically creates .env with correct database settings)
ddev start

# Install PHP and Node.js dependencies
ddev composer install && ddev npm install

# Generate application key
ddev artisan key:generate

# Run database migrations
ddev artisan migrate

# Open your website in the browser
ddev launch

# Start the local development server (Vite for asset compilation)
ddev npm run dev
```

Your site will be available at `https://freshguard.ddev.site` (or similar, depending on your DDEV configuration).

### Email Configuration

**Important**: User registration requires email verification. The project is pre-configured to use **Mailpit** when running with DDEV for seamless development email testing.

#### Mailpit (DDEV Development)

When using DDEV, all emails are automatically captured by **Mailpit**, a powerful email testing tool included with DDEV.

**View Sent Emails:**
```bash
# Open Mailpit in your browser to see all captured emails
ddev mailpit
```

This will launch Mailpit in your default browser, where you can:
- View all emails sent from your application
- Inspect email content, headers, and attachments
- Test email functionality without needing an external email provider

#### Alternative Email Providers (Production)

For production or if you prefer a different email provider, configure one of the following in your `.env`:

**SMTP Server:**
```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=freshguard@example.com
MAIL_FROM_NAME=FreshGuard
```

**Third-Party Services:**
- **Mailgun** - Configure `MAIL_MAILER=mailgun` with your Mailgun credentials
- **Postmark** - Configure `MAIL_MAILER=postmark` with your Postmark token
- **Resend** - Configure `MAIL_MAILER=resend` with your Resend API key
- **AWS SES** - Configure `MAIL_MAILER=ses` with your AWS credentials

**Log Driver (Development Only):**
If you want emails written to logs instead of being sent:
```env
MAIL_MAILER=log
MAIL_LOG_CHANNEL=stack
```

Refer to the [Laravel Mail Documentation](https://laravel.com/docs/12.x/mail) for detailed configuration instructions for your chosen email provider.

### User Registration Control

FreshGuard provides a simple yet powerful system-wide flag to control whether new user registrations are allowed on your instance. You can manage this setting either via the Filament admin panel or by modifying your `.env` file.

#### Configuration Methods

FreshGuard supports two methods to control user registrations:

##### Method 1: Filament Admin Panel (Recommended)

The easiest way to toggle registrations is through the Filament admin panel:

1. Log in to your FreshGuard admin panel at `https://freshguard.ddev.site/admin`
2. Navigate to **Users** from the admin sidebar
3. Click the **Enable Registrations** or **Disable Registrations** button in the header
4. The setting is immediately updated and persisted to your `.env` file

**Visual Indicators:**
- 🟢 **Enable Registrations** (green lock-open icon) - Click to allow new user registrations
- 🔴 **Disable Registrations** (red lock-closed icon) - Click to restrict registrations to existing users only

The button label and icon change dynamically to show the current status and indicate the action that will be performed when clicked.

##### Method 2: Environment Variable (.env)

For server-side configuration, set the `FRESHGUARD_REGISTRATIONS_ENABLED` environment variable:

```env
FRESHGUARD_REGISTRATIONS_ENABLED=true
```
### Development

#### Available Commands

All commands should be prefixed with `ddev` when using the DDEV development environment:

**Running the Development Server:**
```bash
# Start the Vite development server with hot module replacement
ddev npm run dev
```

**Testing:**
```bash
# Run all tests with Pest
ddev composer test

# Run tests with coverage report
ddev composer test:coverage
```

**Code Quality:**
```bash
# Run static analysis (PHPStan level 10)
ddev composer phpstan

# Format code with Laravel Pint
ddev composer pint

# Format only changed files
ddev composer pint:dirty
```

**Database:**
```bash
# Run migrations
ddev artisan migrate

# Create a new migration
ddev artisan make:migration create_your_table

# Rollback migrations
ddev artisan migrate:rollback

# Seed the database
ddev artisan db:seed
```

**Building Assets for Production:**
```bash
# Build frontend assets (CSS/JS) for production
ddev npm run build
```

#### DDEV Useful Commands

```bash
# Start the DDEV environment
ddev start

# Stop the DDEV environment
ddev stop

# Stop and remove DDEV containers
ddev delete

# View DDEV logs
ddev logs

# SSH into the DDEV container
ddev ssh

# Open the site in your browser
ddev launch

# Open Mailpit to view sent emails (development only)
ddev mailpit
```

Refer to the [DDEV Documentation](https://ddev.readthedocs.io/) for more information.

