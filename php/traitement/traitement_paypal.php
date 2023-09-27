<?php
require_once('../class/user.php');
require_once('../class/address.php');
require_once('../class/cart.php');
require_once('../class/order.php');
session_start();
require_once('../include/bdd.php');
require_once('../include/function.php');

$data = json_decode(file_get_contents('php://input'), true);

if (isset($data)) {
    $cart = new Cart(null, $_SESSION['user']->user_id, null, null);
    $result_cart = $cart->returnCart($bdd);

    // Récupère les stock dans un tableau
    $stock = [];
    foreach ($result_cart as $product) {
        array_push($stock, $product->product_stock);
    }

    $address = new Address(null, $_SESSION['user']->user_id, null, null, null, null, null, null, null);
    $allUserAddresses = $address->returnAddressesByUser($bdd);

    if (!empty($result_cart)) {
        if (!in_array(0, $stock)) {

            $date = date("Y-m-d H:i:s");
            $order = new Order(null, $_SESSION['user']->user_id, $date, null, null, null);
            $order->addOrder($bdd);

            $lastInsertId = $bdd->lastInsertId();

            // Récupère les prix dans un tableau
            $prices = [];
            foreach ($result_cart as $cartProduct) {
                // SI la quantité est supérieur à 1, ajoute le nombre de fois le prix dans le tableau
                if ($cartProduct->cart_quantity > 1) {
                    for ($i = 1; $i < (int)$cartProduct->cart_quantity; $i++) {
                        array_push($prices, $cartProduct->product_price);
                    }
                }
                array_push($prices, $cartProduct->product_price);

                // Mise à jour des stock du produit à l'achat
                $updateStock = $bdd->prepare('UPDATE products SET product_stock = :product_stock WHERE product_id = :product_id');
                $updateStock->execute([
                    'product_stock' => $cartProduct->product_stock - (int)$cartProduct->cart_quantity,
                    'product_id' => $cartProduct->product_id
                ]);
                // Insertion du nombre de produit dans le panier, dans la table de liaison
                for ($i = 0; $i < (int)$cartProduct->cart_quantity; $i++) {
                    $insertLiaison = $bdd->prepare('INSERT INTO liaison_product_order (order_id,product_id) VALUES (:order_id,:product_id)');
                    $insertLiaison->execute([
                        'order_id' => $lastInsertId,
                        'product_id' => $cartProduct->product_id
                    ]);
                }
            }
            $total = array_sum($prices);

            // Récupération du code promo rentrer par l'utilisateur
            // $returnCode = $bdd->prepare('SELECT * FROM codes WHERE code_name = :code_name');
            // $returnCode->execute(['code_name' => $_SESSION['code']]);
            // $result_code = $returnCode->fetch(PDO::FETCH_OBJ);
            // var_dump($result_code);

            // Si le code promo existe, nouveau prix avec la réduction
            if (isset($_SESSION['code'])) {
                $discount = (intval($_SESSION['code']) * $total) / 100;
                $total = $total - $discount;
                unset($_SESSION['code']);
            }
            // Création de numéro de commande
            $orderNumber = str_replace(".", "-", strtoupper(uniqid('', true)));

            // Mise à jour de l'objet Order
            $order->setId($lastInsertId);
            $order->setTotal($total);
            $order->setAddress($data['adress']);
            $order->setNumber($orderNumber);
            // $order = new Order($lastInsertId, $_SESSION['user']->user_id, $date, $total, $_POST['adress'], $orderNumber);

            $order->updateOrder($bdd);
            $cart->deleteCart($bdd);
            // header('Location: cartPage.php');
        } else {
            $ORDER_ERROR = 'Un article dans votre panier a son stock epuisée';
        }
    } else {
        $ORDER_ERROR = 'Panier vide';
    }
} else {
    $message['USER_ERROR'] = "Données manquantes";
}

header('Content-Type: application/json');
echo json_encode($message);
exit;