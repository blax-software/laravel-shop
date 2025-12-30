# Models
The goal of this file is to not miss any important model traits, relationships, or details when working with the main models in the package.

## Core Catalog
### Product
- Central purchasable entity.
- **Types**: `Simple`, `Variable`, `Grouped`, `External`, `Booking`, `Variation`, `Pool`.
- **Status**: `Draft`, `Published`, `Archived`.
- **Key Attributes**: `sku`, `slug`, `manage_stock`, `virtual`, `downloadable`.
- **Relationships**: `prices`, `stocks`, `categories`, `attributes`, `relations`.

### ProductPrice
- Defines the cost of a product.
- **Types**: `One Time`, `Recurring` (Subscriptions).
- **Billing**: `Per Unit`, `Tiered`.
- **Key Attributes**: `currency`, `amount`, `compare_at_amount`.
- Supports multi-currency and sale prices.

### ProductStock
- Manages inventory levels.
- **Types**: `Claimed`, `Return`, `Increase`, `Decrease`.
- **Status**: `In Stock`, `Out of Stock`, `Backorder`.
- **Key Attributes**: `quantity`, `sku` (optional override).

### ProductCategory
- Hierarchical organization for products.
- **Relationships**: `parent`, `children`, `products`.

### ProductAttribute
- Custom properties (e.g., Color, Size, Material).
- **Types**: `Text`, `Select`, `Boolean`.
- Can be used for variations or information.

## Shopping Experience
### Cart
- Represents a shopping session.
- **Status**: `Active`, `Abandoned`, `Converted`, `Expired`.
- **Key Attributes**: `currency`, `total`, `tax_total`.
- Can belong to a User or be anonymous (Guest).

### CartItem
- An item within a Cart.
- Links a `Product` and a specific `ProductPrice`.
- **Key Attributes**: `quantity`, `dates` (for bookings), `configuration`.

## Order Management
### Order
- Represents a finalized transaction.
- **Status**: `Pending`, `Processing`, `On Hold`, `In Preparation`, `Ready for Pickup`, `Shipped`, `Delivered`, `Completed`.
- **Key Attributes**: `order_number`, `amount_total`, `amount_paid`, `billing_address`, `shipping_address`.
- Links to User, Cart, and Purchases.

### ProductPurchase
- An individual line item within an Order.
- Represents the immutable record of the product/price at the time of purchase.
- **Status**: `Pending`, `Unpaid`, `Completed`, `Refunded`, `Cart`, `Failed`.
- Tracks fulfillment status.

### OrderNote
- Comments or logs attached to an order.
- Can be internal or customer-visible.

## Payments & Identity
### PaymentMethod
- Stored payment details (e.g., last 4 digits, brand).
- Tokenized reference to external provider.

### PaymentProviderIdentity
- Links a local User to an external payment provider (e.g., Stripe Customer ID).
