<?php namespace Kingpabel\Shoppingcart;

use Illuminate\Support\Collection;

class CartCollection extends Collection {

    public $discount = 0.00;

    public $custom_discount = 0.00;

    public $total = 0.00;

    public $subtotal = 0.00;
}