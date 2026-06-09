<?php
$db = new PDO("sqlite:database/edge-box.sqlite");
echo "Payment 7820: " . ($db->query("SELECT COUNT(*) FROM payments WHERE id = 7820")->fetchColumn() > 0 ? "EXISTS" : "NOT FOUND") . "\n";
echo "Product 1: " . ($db->query("SELECT COUNT(*) FROM products WHERE id = 1")->fetchColumn() > 0 ? "EXISTS" : "NOT FOUND") . "\n";
$stmt = $db->query("SELECT id FROM payments LIMIT 10");
$ids = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $ids[] = $row['id']; }
echo "Payment IDs: " . implode(", ", $ids) . "\n";
$stmt = $db->query("SELECT id, code FROM products LIMIT 10");
$prods = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $prods[] = $row['id'] . "(" . $row['code'] . ")"; }
echo "Products: " . implode(", ", $prods) . "\n";
