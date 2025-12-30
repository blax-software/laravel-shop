# Traits
This file documents the key traits used in the package to add functionality to models.

## Product Features
These traits are typically used on the `Product` model or other purchasable entities.

### HasPrices
- Manages the relationship with `ProductPrice`.
- Provides methods to retrieve the current price (`getCurrentPrice`), handling sales and context.

### HasStocks
- Comprehensive stock management system.
- Handles inventory tracking, stock movements (`increase`, `decrease`), and status (`In Stock`, `Out of Stock`).
- Supports date-based availability checking and stock claims (reservations).

### HasCategories
- Manages the relationship with `ProductCategory`.
- Provides scopes for filtering by category.

### HasProductRelations
- Manages relationships between products (e.g., Related, Upsells, Cross-sells, Variations).
- Provides helper methods to get specific relation types (`relatedProducts`, `variantProducts`, etc.).

### MayBePoolProduct
- Adds logic for "Pool" products (products that are collections of other single items).
- Handles complex availability and pricing calculations for pools.
- Includes `HasBookingPriceCalculation` for date-based logic.

### ChecksIfBooking
- Provides a unified way to check if an entity (Product, Cart, Order) is booking-related.
- Used to determine if date ranges are required.

### HasPricingStrategy
- Manages the pricing strategy for a product (e.g., Lowest Price, Highest Price).
- Used primarily for Pool products or complex pricing scenarios.

## Customer/User Features
These traits are designed to be added to the User model (or whatever model represents the customer).

### HasShoppingCapabilities
- A "meta-trait" that bundles `HasCart`, `HasOrders`, and `HasChargingOptions`.
- Provides the main entry point for a user to interact with the shop system.

### HasCart
- Manages the user's shopping cart.
- Provides methods to retrieve or create a cart and access cart items.

### HasOrders
- Manages the relationship with `Order`.
- Provides helper methods to filter orders by status.

### HasPaymentMethods
- Manages stored payment methods and provider identities (e.g., Stripe Customer ID).
- Essential for recurring billing and saved cards.

### HasStripeAccount
- Wraps Laravel Cashier's `Billable` trait.
- Adds Stripe-specific functionality to the user.

## Other
### HasChargingOptions
- *Currently empty/placeholder.*
- Intended for managing charging configurations.
