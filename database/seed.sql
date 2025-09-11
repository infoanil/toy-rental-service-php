INSERT INTO users(name,email,phone,password_hash,role,created_at) VALUES
('Admin','admin@example.com','9999999999', '$2y$10$kq0b7vTkM1tG4NnZr1p6/.z0mJgYwTthnK8hve9mGf2Q9Gz6aTt1a','admin', NOW()),
('Test User','user@example.com','8888888888', '$2y$10$kq0b7vTkM1tG4NnZr1p6/.z0mJgYwTthnK8hve9mGf2Q9Gz6aTt1a','customer', NOW());
-- password for both = admin123

INSERT INTO categories(name,slug) VALUES
('Ride-Ons','ride-ons'),('Puzzles','puzzles');

INSERT INTO products(title,slug,category_id,brand,age_min,age_max,description,images_json) VALUES
('Red Ride-On Car','red-ride-on',1,'Kidzo',3,6,'Sturdy ride-on car', JSON_ARRAY('car1.jpg','car2.jpg')),
('Wooden Puzzle - Animals','wooden-puzzle-animals',2,'Smarty',2,4,'Animal puzzle', JSON_ARRAY('p1.jpg','p2.jpg'));

INSERT INTO product_plans(product_id,duration_days,price_inr) VALUES
(1,7,499),(1,15,799),(1,30,1299),
(2,7,199),(2,15,299),(2,30,499);

INSERT INTO inventory_units(product_id,code,status) VALUES
(1,'TOY-0001','AVAILABLE'),
(1,'TOY-0002','AVAILABLE'),
(2,'TOY-0003','AVAILABLE');

-- Default address for user id 2
INSERT INTO addresses(user_id,line1,city,state,pincode,is_default) VALUES
(2,'221B Baker Street','Mumbai','MH','400001',1);
