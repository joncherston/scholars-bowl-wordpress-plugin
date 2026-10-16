# Scholars' Bowl Registration System

A complete WordPress plugin for managing Scholars' Bowl competition registrations with payment processing and administrative tools.

## Features

- **Complete Registration System**: Frontend form with all required fields
- **Admin Backend**: Easy-to-use settings page for event configuration
- **Automatic Pricing**: Early bird vs standard pricing based on deadline
- **Payment Processing**: Stripe integration for credit card payments
- **Check/Cash Options**: Alternative payment methods with instructions
- **Session Management**: Automatic assignment based on grade level
- **Email Confirmations**: Automated confirmation emails
- **Admin Dashboard**: View and manage all registrations
- **Responsive Design**: Mobile-friendly forms and admin interface

## Installation

1. Upload the `scholars-bowl-registration` folder to your `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Configure the plugin settings under 'Scholars' Bowl' in the admin menu
4. Add your Stripe API keys for payment processing (optional)
5. Use the shortcode `[scholars_bowl_registration]` on any page to display the registration form

## Configuration

### Basic Event Settings
- Event name, date, and location
- Early bird deadline and pricing
- Standard pricing
- Deposit amount (flat fee option)
- Maximum students and attendees per team
- Free coach limits

### Contact Information
- Event organizer details
- Payment mailing address for check/cash payments

### Payment Settings
- Stripe publishable and secret keys
- Enable credit card processing

## Usage

### Frontend Registration
- Schools fill out the registration form with team details
- Automatic calculation of costs based on attendee numbers
- Choice between deposit or full payment
- Credit card processing via Stripe or check/cash instructions
- Email confirmations sent automatically

### Admin Management
- View all registrations in an organized table
- Track payment status and amounts
- Export registration data
- Contact registrants directly via email links

## Session Assignment

The plugin automatically assigns teams to sessions based on grade level:

- **Morning Session (8:30 AM)**: Private, 1A, 2A, 3A schools
- **Afternoon Session (12:15 PM)**: 4A, 5A, 6A schools

## Payment Processing

### Credit Card Payments
- Secure processing via Stripe
- Automatic payment confirmation
- Receipt emails sent to customers

### Check/Cash Payments
- Detailed payment instructions emailed to registrants
- Manual payment tracking in admin dashboard

## Database

The plugin creates a custom table `wp_scholars_bowl_registrations` to store all registration data including:
- School and contact information
- Team composition details
- Payment information and status
- Registration timestamps

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- MySQL 5.6 or higher
- SSL certificate (recommended for payment processing)

## Stripe Setup

1. Create a Stripe account at https://stripe.com
2. Get your API keys from the Stripe dashboard
3. Add the keys to the plugin settings
4. Test with Stripe's test keys before going live

## Support

For support and feature requests, please contact the plugin administrator.

## Security

- All user inputs are sanitized and validated
- Payment processing uses Stripe's secure infrastructure
- Database queries use WordPress prepared statements
- Nonce verification for all form submissions

## Customization

The plugin includes CSS classes for easy styling customization:
- `.sbr-header` - Event header section
- `.form-section` - Form section containers
- `.cost-summary` - Cost calculation display
- `.payment-section` - Payment method sections

## Changelog

### Version 1.0.0
- Initial release
- Complete registration system
- Stripe payment integration
- Admin management dashboard
- Email confirmation system