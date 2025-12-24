# POS (Point of Sale) Test Plan

Based on analysis of `PosPage.tsx` (frontend) and `SaleController.php` + `ShiftController.php` (backend), here are comprehensive test scenarios we can create.

## 1. Shift Management Tests

### Backend Tests (`ShiftControllerTest.php`)

#### Test: User can open a shift
- **Given**: Authenticated user with no open shift
- **When**: POST `/api/shifts/open`
- **Then**: 
  - Returns 201
  - Shift is created with `opened_at` timestamp
  - `closed_at` is null
  - `user_id` matches authenticated user

#### Test: User cannot open multiple shifts simultaneously
- **Given**: User has an open shift
- **When**: POST `/api/shifts/open`
- **Then**: 
  - Returns 422
  - Error message indicates shift already open
  - No new shift is created

#### Test: User can get current shift
- **Given**: User has an open shift
- **When**: GET `/api/shifts/current`
- **Then**: 
  - Returns 200
  - Returns shift details with `is_open: true`
  - Includes user relationship

#### Test: Returns 204 when no shift exists
- **Given**: User has no shifts
- **When**: GET `/api/shifts/current`
- **Then**: Returns 204 (No Content)

#### Test: User can close current shift
- **Given**: User has an open shift
- **When**: POST `/api/shifts/close`
- **Then**: 
  - Returns 200
  - Shift `closed_at` is set
  - `closed_by_user_id` is set
  - `is_open` is false

#### Test: Cannot close non-existent shift
- **Given**: User has no open shift
- **When**: POST `/api/shifts/close`
- **Then**: Returns 422 with error message

---

## 2. Sale Creation Tests

### Backend Tests (`SaleControllerTest.php`)

#### Test: Create empty sale (draft) requires open shift
- **Given**: Authenticated user with open shift
- **When**: POST `/api/sales/create-empty` with valid data
- **Then**: 
  - Returns 201
  - Sale is created with no items
  - `shift_id` is set to current shift
  - `user_id` matches authenticated user
  - `status` is draft/pending (if applicable)

#### Test: Cannot create sale without open shift
- **Given**: Authenticated user with no open shift
- **When**: POST `/api/sales/create-empty`
- **Then**: 
  - Returns 400
  - Error message indicates shift must be open

#### Test: Create sale with items validates stock availability
- **Given**: Product with stock quantity = 10
- **When**: POST `/api/sales` with quantity = 15
- **Then**: 
  - Returns 422
  - Validation error indicates insufficient stock

#### Test: Create sale with items validates stock per warehouse
- **Given**: Product has 10 units in Warehouse A, 5 in Warehouse B
- **When**: POST `/api/sales` with warehouse_id = A, quantity = 12
- **Then**: 
  - Returns 422
  - Error indicates insufficient stock in warehouse

#### Test: Create sale with valid items reduces stock correctly
- **Given**: Product with stock = 50, sale quantity = 10
- **When**: POST `/api/sales` with valid items
- **Then**: 
  - Returns 201
  - Product stock is reduced by 10
  - Warehouse stock is reduced correctly
  - Sale items are created

#### Test: Create sale uses FIFO batch allocation
- **Given**: Product with multiple batches (oldest first)
- **When**: POST `/api/sales` with quantity = 15
- **Then**: 
  - Oldest batches are allocated first
  - Batch `remaining_quantity` is reduced correctly
  - Sale items reference correct batches

#### Test: Create sale calculates totals correctly
- **Given**: Items with quantities and prices
- **When**: POST `/api/sales` with items
- **Then**: 
  - `total_amount` = sum of (quantity × unit_price)
  - `discount_amount` is calculated correctly
  - `paid_amount` matches payment sum

---

## 3. Adding Items to Sale Tests

### Backend Tests (`SaleItemControllerTest.php`)

#### Test: Add item to existing sale
- **Given**: Existing sale (draft)
- **When**: POST `/api/sales/{sale}/items` with product
- **Then**: 
  - Returns 200
  - Sale item is created
  - Stock is reduced
  - Sale totals are recalculated

#### Test: Add multiple items at once
- **Given**: Existing sale
- **When**: POST `/api/sales/{sale}/items/multiple` with array of items
- **Then**: 
  - All items are added
  - Stock is reduced for all
  - Totals are recalculated

#### Test: Cannot add item with insufficient stock
- **Given**: Product with stock = 5, sale quantity = 10
- **When**: POST `/api/sales/{sale}/items`
- **Then**: 
  - Returns 422
  - Error indicates insufficient stock

#### Test: Add item with batch selection
- **Given**: Product with multiple batches
- **When**: POST `/api/sales/{sale}/items` with `purchase_item_batch_id`
- **Then**: 
  - Correct batch is allocated
  - Batch stock is reduced
  - Sale item references batch

---

## 4. Updating Sale Items Tests

### Backend Tests

#### Test: Update item quantity increases stock correctly
- **Given**: Sale item with quantity = 5, product stock = 20
- **When**: PUT `/api/sales/{sale}/items/{item}` with quantity = 3
- **Then**: 
  - Stock increases by 2 (5-3)
  - Item quantity is updated
  - Totals are recalculated

#### Test: Update item quantity decreases stock correctly
- **Given**: Sale item with quantity = 5, product stock = 20
- **When**: PUT `/api/sales/{sale}/items/{item}` with quantity = 8
- **Then**: 
  - Stock decreases by 3 (8-5)
  - Validates sufficient stock available
  - Item quantity is updated

#### Test: Update item unit price
- **Given**: Sale item with unit_price = 100
- **When**: PUT `/api/sales/{sale}/items/{item}` with unit_price = 120
- **Then**: 
  - Unit price is updated
  - Totals are recalculated
  - Sale total_amount reflects new price

#### Test: Update item batch
- **Given**: Sale item with batch A, product has batch B available
- **When**: PUT `/api/sales/{sale}/items/{item}` with new batch_id
- **Then**: 
  - Old batch stock is restored
  - New batch stock is reduced
  - Item references new batch

#### Test: Cannot update paid sale items
- **Given**: Sale with payments (status = paid)
- **When**: PUT `/api/sales/{sale}/items/{item}`
- **Then**: 
  - Returns 403 or 422
  - Error indicates sale is paid and cannot be modified

---

## 5. Removing Items Tests

### Backend Tests

#### Test: Remove item from sale restores stock
- **Given**: Sale item with quantity = 5, product stock = 20
- **When**: DELETE `/api/sales/{sale}/items/{item}`
- **Then**: 
  - Stock increases by 5
  - Item is deleted
  - Totals are recalculated

#### Test: Cannot remove item from paid sale
- **Given**: Paid sale
- **When**: DELETE `/api/sales/{sale}/items/{item}`
- **Then**: 
  - Returns 403 or 422
  - Error indicates sale cannot be modified

#### Test: Removing last item keeps sale as draft
- **Given**: Sale with one item
- **When**: DELETE `/api/sales/{sale}/items/{item}`
- **Then**: 
  - Sale remains (not deleted)
  - Sale has 0 items
  - Totals are reset

---

## 6. Payment Processing Tests

### Backend Tests (`SalePaymentTest.php`)

#### Test: Add single payment to sale
- **Given**: Sale with total = 100
- **When**: POST `/api/sales/{sale}/payments/single` with amount = 50
- **Then**: 
  - Payment is created
  - `paid_amount` = 50
  - `payment_status` = 'partial'

#### Test: Complete payment sets status to paid
- **Given**: Sale with total = 100, paid = 0
- **When**: POST `/api/sales/{sale}/payments/single` with amount = 100
- **Then**: 
  - `paid_amount` = 100
  - `payment_status` = 'paid'

#### Test: Overpayment is rejected
- **Given**: Sale with total = 100
- **When**: POST `/api/sales/{sale}/payments/single` with amount = 150
- **Then**: 
  - Returns 422
  - Error indicates payment exceeds total

#### Test: Multiple payment methods
- **Given**: Sale with total = 100
- **When**: POST `/api/sales/{sale}/payments` with multiple payments (cash=50, visa=50)
- **Then**: 
  - Both payments are created
  - `paid_amount` = 100
  - `payment_status` = 'paid'

#### Test: Payment with discount calculation
- **Given**: Sale with subtotal = 100, discount = 10%
- **When**: POST `/api/sales/{sale}/payments/single` with amount = 90
- **Then**: 
  - Net amount = 90
  - Payment of 90 completes sale

#### Test: Cannot add payment to non-existent sale
- **Given**: Invalid sale ID
- **When**: POST `/api/sales/999/payments/single`
- **Then**: Returns 404

---

## 7. Discount Management Tests

### Backend Tests

#### Test: Apply fixed discount
- **Given**: Sale with total = 100
- **When**: PUT `/api/sales/{sale}/discount` with amount = 10, type = 'fixed'
- **Then**: 
  - `discount_amount` = 10
  - Net total = 90
  - Totals are recalculated

#### Test: Apply percentage discount
- **Given**: Sale with total = 100
- **When**: PUT `/api/sales/{sale}/discount` with amount = 10, type = 'percentage'
- **Then**: 
  - `discount_amount` = 10 (10% of 100)
  - Net total = 90

#### Test: Discount cannot exceed total
- **Given**: Sale with total = 100
- **When**: PUT `/api/sales/{sale}/discount` with amount = 150, type = 'fixed'
- **Then**: 
  - Returns 422 or clamps to 100
  - Discount = 100 (max)

#### Test: Remove discount
- **Given**: Sale with discount = 10
- **When**: PUT `/api/sales/{sale}/discount` with amount = 0
- **Then**: 
  - `discount_amount` = 0
  - Net total = gross total

---

## 8. Client Management Tests

### Backend Tests

#### Test: Assign client to sale
- **Given**: Sale without client, existing client
- **When**: PUT `/api/sales/{sale}` with client_id
- **Then**: 
  - `client_id` is set
  - Client relationship is loaded

#### Test: Remove client from sale
- **Given**: Sale with client
- **When**: PUT `/api/sales/{sale}` with client_id = null
- **Then**: 
  - `client_id` is null
  - Sale is still valid

#### Test: Cannot assign non-existent client
- **Given**: Sale, invalid client_id
- **When**: PUT `/api/sales/{sale}` with client_id = 999
- **Then**: Returns 422 validation error

---

## 9. Today's Sales Tests

### Backend Tests

#### Test: Get today's sales by created_at
- **Given**: Sales created today and yesterday
- **When**: GET `/api/sales/today-by-created-at`
- **Then**: 
  - Returns only today's sales
  - Includes items and payments
  - Ordered by created_at desc

#### Test: Filter today's sales by user
- **Given**: Sales by User A and User B today
- **When**: GET `/api/sales?for_current_user=true`
- **Then**: 
  - Returns only current user's sales
  - Excludes other users' sales

#### Test: Filter today's sales by date
- **Given**: Sales on different dates
- **When**: GET `/api/sales?start_date=2024-01-01&end_date=2024-01-01`
- **Then**: 
  - Returns only sales on that date
  - Excludes other dates

---

## 10. Batch Selection Tests

### Backend Tests

#### Test: Get available batches for product
- **Given**: Product with multiple batches
- **When**: GET `/api/products/{product}/available-batches`
- **Then**: 
  - Returns batches with stock > 0
  - Ordered by expiry date (FIFO)
  - Includes batch details

#### Test: Batch selection respects warehouse
- **Given**: Product with batches in different warehouses
- **When**: GET `/api/products/{product}/available-batches?warehouse_id=1`
- **Then**: 
  - Returns only batches in that warehouse
  - Stock quantities are warehouse-specific

---

## 11. Sale Completion Tests

### Backend Tests

#### Test: Complete sale with full payment
- **Given**: Sale with items, total = 100
- **When**: POST `/api/sales/{sale}/payments/single` with amount = 100
- **Then**: 
  - Sale is marked as paid
  - Stock is permanently reduced
  - Sale cannot be modified

#### Test: Complete sale triggers invoice generation
- **Given**: Completed sale
- **When**: GET `/api/sales/{sale}/invoice-pdf`
- **Then**: 
  - Returns PDF
  - Contains sale details
  - Contains items and totals

#### Test: Complete sale triggers thermal invoice
- **Given**: Completed sale
- **When**: GET `/api/sales/{sale}/thermal-invoice-pdf`
- **Then**: 
  - Returns thermal PDF
  - Formatted for thermal printer
  - Contains essential sale info

---

## 12. Stock Validation Tests

### Backend Tests (`SaleStockValidationTest.php`)

#### Test: Stock check before sale creation
- **Given**: Product with stock = 10
- **When**: POST `/api/sales` with quantity = 15
- **Then**: 
  - Returns 422 before transaction
  - No stock is reduced
  - No sale is created

#### Test: Concurrent sales prevent overselling
- **Given**: Product with stock = 10
- **When**: Two simultaneous requests for quantity = 8 each
- **Then**: 
  - One succeeds, one fails
  - Total stock reduction = 10 (not 16)
  - Database constraints prevent overselling

#### Test: Stock validation per warehouse
- **Given**: Product: 10 in Warehouse A, 5 in Warehouse B
- **When**: Sale in Warehouse A with quantity = 12
- **Then**: 
  - Returns 422
  - Error indicates insufficient stock in warehouse

---

## 13. Integration Tests

### Frontend-Backend Integration Tests

#### Test: Complete POS flow
1. Open shift
2. Create empty sale
3. Add products to sale
4. Apply discount
5. Add payment
6. Complete sale
7. Verify stock reduced
8. Verify sale is paid

#### Test: POS flow with batch selection
1. Open shift
2. Create sale
3. Add product with multiple batches
4. Select specific batch
5. Complete sale
6. Verify correct batch stock reduced

---

## 14. Edge Cases & Error Handling Tests

### Backend Tests

#### Test: Sale with zero items
- **Given**: Empty sale
- **When**: Attempt to complete
- **Then**: 
  - Returns 422
  - Error indicates sale must have items

#### Test: Sale date in future
- **Given**: Sale with sale_date = tomorrow
- **When**: Create sale
- **Then**: 
  - Returns 422 or allows (business rule)
  - Validates date appropriately

#### Test: Sale with negative quantities
- **Given**: Sale item
- **When**: Update quantity to -5
- **Then**: 
  - Returns 422
  - Validation error

#### Test: Sale with zero unit price
- **Given**: Sale item
- **When**: Set unit_price = 0
- **Then**: 
  - Returns 422 or allows (business rule)
  - Validates appropriately

---

## Test File Structure

```
tests/Feature/
├── ShiftControllerTest.php          # Shift management
├── SaleControllerTest.php            # Sale CRUD operations
├── SaleItemControllerTest.php        # Adding/updating items
├── SalePaymentTest.php               # Payment processing
├── SaleStockValidationTest.php       # Stock validation
├── SaleDiscountTest.php              # Discount management
├── SaleBatchSelectionTest.php        # Batch selection
└── POSIntegrationTest.php            # End-to-end POS flows
```

---

## Priority Testing Order

1. **High Priority** (Core functionality):
   - Shift management
   - Sale creation with stock validation
   - Adding items to sale
   - Payment processing
   - Stock reduction on sale completion

2. **Medium Priority** (Important features):
   - Discount management
   - Batch selection
   - Updating sale items
   - Today's sales filtering

3. **Low Priority** (Edge cases):
   - Error handling
   - Concurrent sales
   - Edge case validations

---

## Notes

- All tests should use `RefreshDatabase` trait
- Authenticate users with `Sanctum::actingAs()`
- Use factories for creating test data
- Test both success and failure scenarios
- Include validation error tests
- Test stock calculations accurately
- Verify database state after operations


