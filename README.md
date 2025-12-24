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

- PHP 8.4+
- Composer
- Node.js & npm (for frontend assets)

### Installation

```bash
# Clone the repository
git clone https://github.com/marcelorodrigo/freshguard.git

# Install PHP dependencies
composer install

# Install frontend dependencies
npm install

# Set up environment
cp .env.example .env
php artisan key:generate

# Run migrations
php artisan migrate

# Build frontend assets
npm run build
```

### Development

```bash
# Start development server
php artisan serve

# Watch for frontend changes
npm run dev

# Run tests
php artisan test
```

## Contributing

We welcome contributions! Please feel free to submit pull requests or open issues for bugs and feature requests at [marcelorodrigo/freshguard](https://github.com/marcelorodrigo/freshguard).

## License

FreshGuard is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
