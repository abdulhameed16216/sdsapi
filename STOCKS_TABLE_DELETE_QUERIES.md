SET FOREIGN_KEY_CHECKS = 0;

-- Step 1: Delete from child tables first (order matters due to foreign key constraints)
DELETE FROM stocks_product;           -- Child of stocks
DELETE FROM stock_availability;       -- Child of stock_availability_groups
DELETE FROM customer_returns;         -- Child of stocks (must be before stocks)
DELETE FROM delivery_products;       -- Child of delivery
DELETE FROM internal_stocks_products; -- Child of internal_stocks

-- Step 2: Delete from parent tables
DELETE FROM stock_availability_groups; -- Parent of stock_availability
DELETE FROM stocks;                    -- Parent of stocks_product and customer_returns
DELETE FROM delivery;                  -- Parent of delivery_products
DELETE FROM internal_stocks;           -- Parent of internal_stocks_products

-- Step 3: Reset auto-increment to 1
ALTER TABLE stocks_product AUTO_INCREMENT = 1;
ALTER TABLE stock_availability AUTO_INCREMENT = 1;
ALTER TABLE stock_availability_groups AUTO_INCREMENT = 1;
ALTER TABLE customer_returns AUTO_INCREMENT = 1;
ALTER TABLE stocks AUTO_INCREMENT = 1;
ALTER TABLE delivery_products AUTO_INCREMENT = 1;
ALTER TABLE delivery AUTO_INCREMENT = 1;
ALTER TABLE internal_stocks_products AUTO_INCREMENT = 1;
ALTER TABLE internal_stocks AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;
