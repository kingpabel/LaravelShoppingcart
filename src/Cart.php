<?php namespace Kingpabel\Shoppingcart;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

class Cart
{

    /**
     * Session class instance
     *
     * @var Illuminate\Session\SessionManager
     */
    protected $session;

    /**
     * Event class instance
     *
     * @var Illuminate\Events\Dispatcher
     */
    protected $event;

    /**
     * Current cart instance
     *
     * @var string
     */
    protected $instance;

    /**
     * The Eloquent model a cart is associated with
     *
     * @var string
     */
    protected $associatedModel;

    /**
     * An optional namespace for the associated model
     *
     * @var string
     */
    protected $associatedModelNamespace;

    /**
     * Constructor
     *
     * @param Illuminate\Session\SessionManager $session Session class instance
     * @param \Illuminate\Contracts\Events\Dispatcher $event Event class instance
     */
    public function __construct($session, Dispatcher $event)
    {
        $this->session = $session;
        $this->event = $event;

        $this->instance = 'main';
    }

    /**
     * Set the current cart instance
     *
     * @param  string $instance Cart instance name
     * @return Kingpabel\Shoppingcart\Cart
     */
    public function instance($instance = null)
    {
        if (empty($instance)) throw new Exceptions\ShoppingcartInstanceException;

        $this->instance = $instance;

        // Return self so the method is chainable
        return $this;
    }

    /**
     * Set the associated model
     *
     * @param  string $modelName The name of the model
     * @param  string $modelNamespace The namespace of the model
     * @return void
     */
    public function associate($modelName, $modelNamespace = null)
    {
        $this->associatedModel = $modelName;
        $this->associatedModelNamespace = $modelNamespace;

        if (!class_exists($modelNamespace . '\\' . $modelName)) throw new Exceptions\ShoppingcartUnknownModelException;

        // Return self so the method is chainable
        return $this;
    }

    /**
     * Add a row to the cart
     *
     * @param string|array $id Unique ID of the item|Item formated as array|Array of items
     * @param string $name Name of the item
     * @param int $qty Item qty to add to the cart
     * @param float $price Price of one item
     * @param array $options Array of additional options, such as 'size' or 'color'
     */
    public function add($id, $name = null, $qty = null, $price = null, $discount = null, array $options = [])
    {
        // If the first parameter is an array we need to call the add() function again
        if (is_array($id)) {
            // And if it's not only an array, but a multidimensional array, we need to
            // recursively call the add function
            if ($this->is_multi($id)) {
                // Fire the cart.batch event
                $this->event->fire('cart.batch', $id);

                foreach ($id as $item) {
                    $options = array_get($item, 'options', []);
                    $this->addRow($item['id'], $item['name'], $item['qty'], $item['price'], $this->discountResolve($item), $options);
                }

                // Fire the cart.batched event
                $this->event->fire('cart.batched', $id);

                return null;
            }

            $options = array_get($id, 'options', []);

            // Fire the cart.add event
            $this->event->fire('cart.add', array_merge($id, ['options' => $options]));

            $result = $this->addRow($id['id'], $id['name'], $id['qty'], $id['price'], $this->discountResolve($id), $options);

            // Fire the cart.added event
            $this->event->fire('cart.added', array_merge($id, ['options' => $options]));

            return $result;
        }

        // Fire the cart.add event
        $this->event->fire('cart.add', compact('id', 'name', 'qty', 'price', 'options'));

        $result = $this->addRow($id, $name, $qty, $price, $discount, $options);

        // Fire the cart.added event
        $this->event->fire('cart.added', compact('id', 'name', 'qty', 'price', 'options'));

        return $result;
    }

    /**
     * @param $data
     * @return null
     */
    public function discountResolve($data)
    {
        if (isset($data['discount']))
            return $data['discount'];
        else
            return null;
    }

    /**
     * Update the quantity of one row of the cart
     *
     * @param  string $rowId The rowid of the item you want to update
     * @param  integer|array $attribute New quantity of the item|Array of attributes to update
     * @return boolean
     */
    public function update($rowId, $attribute)
    {
        if (!$this->hasRowId($rowId)) throw new Exceptions\ShoppingcartInvalidRowIDException;

        if (is_array($attribute)) {
            // Fire the cart.update event
            $this->event->fire('cart.update', $rowId);

            $result = $this->updateAttribute($rowId, $attribute);

            // Fire the cart.updated event
            $this->event->fire('cart.updated', $rowId);

            return $result;
        }

        // Fire the cart.update event
        $this->event->fire('cart.update', $rowId);

        $result = $this->updateQty($rowId, $attribute);

        // Fire the cart.updated event
        $this->event->fire('cart.updated', $rowId);

        return $result;
    }

    /**
     * Remove a row from the cart
     *
     * @param  string $rowId The rowid of the item
     * @return boolean
     */
    public function remove($rowId)
    {
        if (!$this->hasRowId($rowId)) throw new Exceptions\ShoppingcartInvalidRowIDException;

        $cart = $this->getContent();

        // Fire the cart.remove event
        $this->event->fire('cart.remove', $rowId);

        $cart->forget($rowId);

        // Fire the cart.removed event
        $this->event->fire('cart.removed', $rowId);

        return $this->updateCart($cart);
    }

    /**
     * Get a row of the cart by its ID
     *
     * @param  string $rowId The ID of the row to fetch
     * @return Kingpabel\Shoppingcart\CartCollection
     */
    public function get($rowId)
    {
        $cart = $this->getContent();

        return ($cart->has($rowId)) ? $cart->get($rowId) : NULL;
    }

    /**
     * Get the cart content
     *
     * @return Kingpabel\Shoppingcart\CartRowCollection
     */
    public function content()
    {
        $cart = $this->getContent();

        return (empty($cart)) ? NULL : $cart;
    }

    /**
     * Empty the cart
     *
     * @return boolean
     */
    public function destroy()
    {
        // Fire the cart.destroy event
        $this->event->fire('cart.destroy');

        $result = $this->updateCart(NULL);

        // Fire the cart.destroyed event
        $this->event->fire('cart.destroyed');

        return $result;
    }


    /**
     * Get the number of items in the cart
     *
     * @param  boolean $totalItems Get all the items (when false, will return the number of rows)
     * @return int
     */
    public function count($totalItems = true)
    {
        $cart = $this->getContent();

        if (!$totalItems) {
            return $cart->count();
        }

        $count = 0;

        foreach ($cart AS $row) {
            $count += $row->qty;
        }

        return $count;
    }

    /**
     * Search if the cart has a item
     *
     * @param  array $search An array with the item ID and optional options
     * @return array|boolean
     */
    public function search(array $search)
    {
        if (empty($search)) return false;

        foreach ($this->getContent() as $item) {
            $found = $item->search($search);

            if ($found) {
                $rows[] = $item->rowid;
            }
        }

        return (empty($rows)) ? false : $rows;
    }

    /**
     * Add row to the cart
     *
     * @param string $id Unique ID of the item
     * @param string $name Name of the item
     * @param int $qty Item qty to add to the cart
     * @param float $price Price of one item
     * @param array $options Array of additional options, such as 'size' or 'color'
     */
    protected function addRow($id, $name, $qty, $price, $discount, array $options = [])
    {
        if (empty($id) || empty($name) || empty($qty) || !isset($price)) {
            throw new Exceptions\ShoppingcartInvalidItemException;
        }

        if (!is_numeric($qty)) {
            throw new Exceptions\ShoppingcartInvalidQtyException;
        }

        if (!is_numeric($price)) {
            throw new Exceptions\ShoppingcartInvalidPriceException;
        }

        if (!is_numeric($discount)) {
            throw new Exceptions\ShoppingcartInvalidPriceException;
        }

        $cart = $this->getContent();
        $rowId = $this->generateRowId($id, $options);

        if ($cart->has($rowId)) {
            $row = $cart->get($rowId);
            $cart = $this->updateRow($rowId, ['qty' => $row->qty + $qty]);
        } else {
            $cart = $this->createRow($rowId, $id, $name, $qty, $price, $discount, $options);
        }

        $this->updateCart($cart);
        $this->setTotal();
        $this->setSubTotal();
        $this->setDiscount();
        return null;
    }

    /**
     * Generate a unique id for the new row
     *
     * @param  string $id Unique ID of the item
     * @param  array $options Array of additional options, such as 'size' or 'color'
     * @return boolean
     */
    protected function generateRowId($id, $options)
    {
        ksort($options);

        return md5($id . serialize($options));
    }

    /**
     * Check if a rowid exists in the current cart instance
     *
     * @param  string $id Unique ID of the item
     * @return boolean
     */
    protected function hasRowId($rowId)
    {
        return $this->getContent()->has($rowId);
    }

    /**
     * Update the cart
     *
     * @param  Kingpabel\Shoppingcart\CartCollection $cart The new cart content
     * @return void
     */
    protected function updateCart($cart)
    {
        $this->session->put($this->getInstance(), $cart);
        $this->session->save();
        return null;
    }

    /**
     * Get the carts content, if there is no cart content set yet, return a new empty Collection
     *
     * @return Kingpabel\Shoppingcart\CartCollection
     */
    protected function getContent()
    {
        $content = ($this->session->has($this->getInstance())) ? $this->session->get($this->getInstance()) : new CartCollection;

        return $content;
    }

    /**
     * Get the current cart instance
     *
     * @return string
     */
    protected function getInstance()
    {
        return 'cart.' . $this->instance;
    }

    /**
     * Update a row if the rowId already exists
     *
     * @param  string $rowId The ID of the row to update
     * @param  integer $qty The quantity to add to the row
     * @return Kingpabel\Shoppingcart\CartCollection
     */
    protected function updateRow($rowId, $attributes)
    {
        $cart = $this->getContent();

        $row = $cart->get($rowId);

        foreach ($attributes as $key => $value) {
            if ($key == 'options') {
                $options = $row->options->merge($value);
                $row->put($key, $options);
            } else {
                $row->put($key, $value);
            }
        }

        if (!is_null(array_keys($attributes, ['qty', 'price']))) {
            $row->put('total', $row->qty * $row->price);
            $row->put('total_discount', $row->qty * $row->discount);
            $row->put('subtotal', ($row->qty * $row->price) - ($row->qty * $row->discount));
        }

        $cart->put($rowId, $row);

        $this->setTotal();
        $this->setSubTotal();
        $this->setDiscount();

        return $cart;
    }

    /**
     * Create a new row Object
     *
     * @param  string $rowId The ID of the new row
     * @param  string $id Unique ID of the item
     * @param  string $name Name of the item
     * @param  int $qty Item qty to add to the cart
     * @param  float $price Price of one item
     * @param  array $options Array of additional options, such as 'size' or 'color'
     * @return Kingpabel\Shoppingcart\CartCollection
     */
    protected function createRow($rowId, $id, $name, $qty, $price, $discount, $options)
    {
        $cart = $this->getContent();

        $newRow = new CartRowCollection([
            'rowid' => $rowId,
            'id' => $id,
            'name' => $name,
            'qty' => $qty,
            'price' => $price,
            'discount' => $discount,
            'options' => new CartRowOptionsCollection($options),
            'total' => $qty * $price,
            'total_discount' => $qty * $discount,
            'subtotal' => ($qty * $price) - ($qty * $discount),
        ], $this->associatedModel, $this->associatedModelNamespace);

        $cart->put($rowId, $newRow);

        return $cart;
    }

    /**
     * Update the quantity of a row
     *
     * @param  string $rowId The ID of the row
     * @param  int $qty The qty to add
     * @return Kingpabel\Shoppingcart\CartCollection
     */
    protected function updateQty($rowId, $qty)
    {
        if ($qty <= 0) {
            return $this->remove($rowId);
        }

        return $this->updateRow($rowId, ['qty' => $qty]);
    }

    /**
     * Update an attribute of the row
     *
     * @param  string $rowId The ID of the row
     * @param  array $attributes An array of attributes to update
     * @return Kingpabel\Shoppingcart\CartCollection
     */
    protected function updateAttribute($rowId, $attributes)
    {
        return $this->updateRow($rowId, $attributes);
    }

    /**
     * Check if the array is a multidimensional array
     *
     * @param  array $array The array to check
     * @return boolean
     */
    protected function is_multi(array $array)
    {
        return is_array(head($array));
    }

    /**
     * @param $amount
     * @return bool
     */
    protected function setCustomDiscount($amount)
    {
        $cart = $this->getContent();

        if (!$cart->isEmpty() && is_numeric($amount)) {
            $cart->custom_discount = floatval($amount);
            $this->setSubTotal();
            $this->updateCart($cart);
            return true;
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function customDiscount()
    {
        return $this->getContent()->custom_discount;
    }

    /**
     * @return bool
     */
    public function setDiscount()
    {
        $cart = $this->getContent();

        if ($cart->isEmpty()) {
            return false;
        }

        $discount = 0;
        foreach ($cart AS $row) {
            $discount += $row->total_discount;
        }

        $cart->discount = floatval($discount);
        $this->updateCart($cart);

        return true;
    }

    /**
     * @return mixed
     */
    public function discount()
    {
        return $this->getContent()->discount;
    }

    /**
     * @return mixed
     */
    protected function setTotal()
    {
        $cart = $this->getContent();

        if ($cart->isEmpty()) {
            return false;
        }

        $total = 0;
        foreach ($cart AS $row) {
            $total += $row->total;
        }

        $cart->total = floatval($total);
        $this->updateCart($cart);

        return true;
    }

    /**
     * @return mixed
     */
    public function total()
    {
        return $this->getContent()->total;
    }

    /**
     * @return mixed
     */
    protected function setSubTotal()
    {
        $cart = $this->getContent();

        if ($cart->isEmpty()) {
            return false;
        }

        $subtotal = 0;
        foreach ($cart AS $row) {
            $subtotal += $row->subtotal;
        }

        $cart->subtotal = floatval($subtotal - $this->customDiscount());
        $this->updateCart($cart);

        return true;
    }

    /**
     * @return mixed
     */
    public function subtotal()
    {
        return $this->getContent()->subtotal;
    }

}
