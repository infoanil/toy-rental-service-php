<?php
use App\Core\{Router,Request,Response};
use App\Controllers\{AuthController,CatalogController,AvailabilityController,CartController,CheckoutController,OrderController,AdminController};
use App\Middleware\Auth;

$jwtAuth = Auth::class;

// Public
$router->add('POST','/api/auth/register', AuthController::class.'@register');
$router->add('POST','/api/auth/login',    AuthController::class.'@login');
$router->add('GET', '/api/categories',    CatalogController::class.'@categories');
$router->add('GET', '/api/products',      CatalogController::class.'@products');
$router->add('GET', '/api/products/{id}', CatalogController::class.'@product');
$router->add('GET', '/api/products/{id}/plans', CatalogController::class.'@plans');
$router->add('GET', '/api/products/{id}/availability', AvailabilityController::class.'@check');

// Authenticated
$router->add('GET', '/api/me',            AuthController::class.'@me',               [$jwtAuth]);
$router->add('GET', '/api/cart',          CartController::class.'@get',              [$jwtAuth]);
$router->add('POST','/api/cart/items',    CartController::class.'@addItem',          [$jwtAuth]);
$router->add('DELETE','/api/cart/items/{id}', CartController::class.'@removeItem',   [$jwtAuth]);

$router->add('POST','/api/checkout',      CheckoutController::class.'@checkout',     [$jwtAuth]);

$router->add('GET', '/api/orders',        OrderController::class.'@list',            [$jwtAuth]);
$router->add('GET', '/api/orders/{id}',   OrderController::class.'@show',            [$jwtAuth]);
$router->add('POST','/api/orders/{id}/cancel', OrderController::class.'@cancel',     [$jwtAuth]);

// Admin
$router->add('GET', '/api/admin/orders',                AdminController::class.'@orders',         [$jwtAuth]);
$router->add('POST','/api/admin/orders/{id}/confirm',   AdminController::class.'@confirm',        [$jwtAuth]);
$router->add('POST','/api/admin/orders/{id}/delivered', AdminController::class.'@markDelivered',  [$jwtAuth]);

$router->add('GET','/api/addresses', App\Controllers\AddressController::class.'@list', [$jwtAuth]);
$router->add('POST','/api/addresses', App\Controllers\AddressController::class.'@store', [$jwtAuth]);

