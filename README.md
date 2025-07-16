# Tapbuy Alma Module

A Magento 2 module that extends the Alma Monthly Payments functionality to integrate with Tapbuy's GraphQL payment processing system.

## Overview

This module provides a plugin that intercepts and modifies payment data for Alma Monthly Payments, specifically to handle custom return URLs provided by Tapbuy's additional payment information.

## Features

- **Payment Data Enhancement**: Automatically adds custom return URLs and cancellation URLs to Alma payment requests
- **GraphQL Integration**: Designed to work seamlessly with Magento 2 GraphQL API
- **Error Handling**: Graceful handling of serialization errors to prevent payment process interruption
- **Flexible Configuration**: Uses additional payment information to customize payment flow URLs

## Requirements

- Magento 2.x
- PHP 7.4+ or 8.x
- Alma Monthly Payments module
- Magento GraphQL module

## Installation

### Composer Installation (Recommended)

1. Add the module to your Magento project:
```bash
composer require tapbuy/alma
```

2. Enable the module:
```bash
php bin/magento module:enable Tapbuy_Alma
```

3. Run setup upgrade:
```bash
php bin/magento setup:upgrade
```

4. Compile if needed:
```bash
php bin/magento setup:di:compile
```

5. Clear cache:
```bash
php bin/magento cache:clean
```

### Manual Installation

1. Create the directory structure:
```
app/code/Tapbuy/Alma/
```

2. Copy all module files to the directory
3. Follow steps 2-5 from the Composer installation

## How It Works

### Plugin Architecture

The module uses Magento's plugin system to intercept the `Alma\MonthlyPayments\Gateway\Request\PaymentDataBuilder::build()` method using an `afterBuild` plugin.

### Payment Data Modification

When a payment is processed, the plugin:

1. **Extracts Tapbuy Information**: Retrieves serialized Tapbuy data from payment additional information
2. **Deserializes Data**: Safely unserializes the Tapbuy data using Magento's serializer
3. **Maps URLs**: Maps Tapbuy URLs to Alma payment parameters:
   - `accept_url` → `return_url`
   - `cancel_url` → `customer_cancel_url` and `failure_return_url`
4. **Updates Payment Data**: Modifies the payment array with the new URL configurations

### Error Handling

The plugin includes robust error handling:
- Catches serialization exceptions
- Prevents payment process interruption
- Maintains original payment flow if Tapbuy data is unavailable

## Configuration

### Payment Additional Information Format

The module expects Tapbuy data to be stored in the payment's additional information under the key `tapbuy` as a serialized array:

```php
$payment->setAdditionalInformation('tapbuy', $serializer->serialize([
    'accept_url' => 'https://your-domain.com/success',
    'cancel_url' => 'https://your-domain.com/cancel'
]));
```

### URL Mapping

| Tapbuy Parameter | Alma Parameter | Description |
|------------------|----------------|-------------|
| `accept_url` | `return_url` | Success redirect URL |
| `cancel_url` | `customer_cancel_url` | Customer cancellation URL |
| `cancel_url` | `failure_return_url` | Payment failure URL |

## File Structure

```
Tapbuy/Alma/
├── Plugin/
│   └── PaymentDataBuilderPlugin.php    # Main plugin class
├── etc/
│   ├── di.xml                          # Dependency injection configuration
│   └── module.xml                      # Module declaration
├── composer.json                       # Composer configuration
├── registration.php                    # Module registration
└── README.md                          # This file
```

## Dependencies

### Module Dependencies
- `Magento_GraphQl`: Required for GraphQL functionality

### Plugin Target
- `Alma\MonthlyPayments\Gateway\Request\PaymentDataBuilder`: The Alma module's payment data builder

## Development

### Plugin Configuration

The plugin is configured in `etc/di.xml`:

```xml
<type name="Alma\MonthlyPayments\Gateway\Request\PaymentDataBuilder">
    <plugin name="tapbuy_alma_payment_data_builder" 
            type="Tapbuy\Alma\Plugin\PaymentDataBuilderPlugin"
            sortOrder="10"/>
</type>
```

### Key Classes

#### PaymentDataBuilderPlugin
- **Namespace**: `Tapbuy\Alma\Plugin`
- **Purpose**: Modifies Alma payment data with Tapbuy-specific URLs
- **Method**: `afterBuild()` - Plugin method that runs after the original build method

## Troubleshooting

### Common Issues

1. **Module Not Loading**
   - Verify module is enabled: `php bin/magento module:status Tapbuy_Alma`
   - Check registration.php path is correct

2. **Plugin Not Working**
   - Ensure DI compilation is up to date: `php bin/magento setup:di:compile`
   - Verify Alma module is installed and enabled

3. **Serialization Errors**
   - Check that Tapbuy data is properly serialized before storage
   - Verify JSON format if using JSON serialization

### Logging

The module handles errors gracefully but doesn't log them by default. For debugging, you can modify the exception handling in `PaymentDataBuilderPlugin.php` to add logging:

```php
} catch (\Exception $e) {
    // Add logging here if needed
    $this->logger->error('Tapbuy Alma plugin error: ' . $e->getMessage());
}
```