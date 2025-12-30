# KAIZEN

## Goal
The editing agent should improve the quality of the prompts in .github/ for the next editing agent if there are any breaking changes in the repository structure, requirements or testing framework.

## Instructions
1. If the user says something like "Do not do this, do X instead" permanentize it into the repository prompts
2. "Log" important details into the prompts for future agents
3. Ensure that all instructions are clear, concise, and unambiguous
4. Avoid redundancy and ensure that the prompts are well-organized
5. Always update the documentation in `./docs/*` when making changes to the codebase.
6. You always aim to change .github/copilot-instructions.md or `./docs/*` if applicable
7. **NEVER use `git checkout` or `git reset` commands** - manually revert changes using replace_string_in_file instead

## Session Log

### 2025-12-30: CRITICAL MISTAKE - Misunderstood Pool Pricing Strategy

**WRONG Understanding (DO NOT IMPLEMENT):**
❌ Pricing strategy compares pool price vs single price
❌ LOWEST: min(poolPrice, singlePrice)
❌ Example: Pool=5000, Single=10000 → use 5000 (WRONG!)

**CORRECT Understanding:**
✅ Pricing strategy determines allocation ORDER of singles
✅ Singles ALWAYS use their own price if they have one
✅ Pool price is ONLY a fallback when single has NO price
✅ Example: Pool=5000, Singles=10000,50000 → use 10000 and 50000 (singles' prices)

**Correct Pricing Logic:**
```php
if ($singlePrice !== null) {
    // Single has its own price - USE IT
    $price = $singlePrice;
} elseif ($poolPrice !== null) {
    // Single has NO price - fallback to pool price
    $price = $poolPrice;
}
```

**What Pricing Strategy Actually Does:**
- LOWEST: Allocate singles with lowest prices first (10000 before 50000)
- HIGHEST: Allocate singles with highest prices first (50000 before 10000)
- AVERAGE: Calculate average price of all available singles
- Strategy affects WHICH single is allocated, NOT the price used

**Files That Were Incorrectly Modified (REVERTED):**
- `src/Models/CartItem.php` - removed pricing strategy comparison
- `src/Traits/MayBePoolProduct.php` - removed pricing strategy comparison
- `src/Models/Cart.php` - removed pricing strategy comparison

**Key Learning:** ALWAYS verify understanding of business logic before implementing. Pool pricing strategy is about allocation order, not price comparison.