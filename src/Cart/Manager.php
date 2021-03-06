<?php

namespace Cart;

use Cart\Exception\InvalidCartInstanceException;
use Cart\Exception\DuplicateCartInstanceException;
use Cart\Exception\InvalidStorageImplementationException;

class Manager
{
    /**
     * Available cart instances
     *
     * @var array
     */
    protected $carts = array();

    /**
     * ID of the cart in the current context
     *
     * @var string
     */
    protected $context = null;

    /**
     * Configuration options associated with the cart manager and the carts in the cart manager
     *
     * @var array
     */
    protected $config = array();

    /**
     * Initialises the cart manager. Loads in the config and instantiates any carts declared in the config file.
     *
     * @param array $config Configuration options associated with this cart manager
     */
    public function __construct($config)
    {
        // cache passed config options
        $this->config = $config;

        // if there are carts defined in the config
        if (count($config['carts']) > 0) {
            foreach ($config['carts'] as $cartID => $cartConfig) {
                $cartConfig = array_merge($config['defaults'], $cartConfig); //merge global config with cart specific config
                $this->config['carts'][$cartID] = $cartConfig; //update the config
                $this->newCart($cartID, $cartConfig, true, false);
            }

            // set context to first cart in array
            $this->context = key($config['carts']);
        }
    }

    /**
     * Sets the current context if a cart ID is supplied, or gets the current context if no cart ID is supplied
     *
     * @param  bool|string                                  $cartID If false then the current context is returned, otherwise the current context is set
     * @return string                                       The current context if this is being retrieved
     * @throws \Cart\Exception\InvalidCartInstanceException
     */
    public function context($cartID = false)
    {
        if ($cartID) {
            if (isset($this->carts[$cartID])) {
                $this->context = $cartID;
            } else {
                throw new InvalidCartInstanceException(sprintf('There is no cart instance with the id: %s', $cartID));
            }
        }

        return $this->context;
    }

    /**
     * Checks to see if there is an instance of a cart with a specific ID
     *
     * @param  string $cartID The ID of the cart to check for
     * @return bool   True if the cart instance exists, false otherwise
     */
    public function cartExists($cartID)
    {
        return array_key_exists($cartID, $this->carts);
    }

    /**
     * Gets a cart instance. If no cart id is passed then the cart in the current context
     * is returned, otherwise requested instance is returned
     *
     * @param  string|bool                                  $cartID The Id of the cart instance to return
     * @return object                                       The requested cart instance or the current cart instance in context if no $cartID provided
     * @throws \Cart\Exception\InvalidCartInstanceException
     */
    public function getCart($cartID = false)
    {
        $cartID or $cartID = $this->context;

        if ($this->cartExists($cartID)) {
            return $this->carts[$cartID];
        } else {
            throw new InvalidCartInstanceException(sprintf('There is no cart instance with the id: %s', $cartID));
        }
    }

    /**
     * Create a new cart instance
     *
     * @param  string                                         $cartID        The ID for the cart instance
     * @param  bool|array                                     $config        The configuration options associated with this cart
     * @param  bool                                           $overwrite     If the cart instance already exists should if be overwritten
     * @param  bool                                           $switchContext Should the context be switched to this cart instance
     * @return mixed                                          The newly created cart instance
     * @throws \Cart\Exception\DuplicateCartInstanceException
     */
    public function newCart($cartID, $config = false, $overwrite = true, $switchContext = true)
    {
        if (!$this->cartExists($cartID) or $overwrite) {

            $config or $config = $this->getCartConfig($cartID);
            $this->carts[$cartID] = new Cart($cartID, $config);

            /*
             * is there storage options associated with this instance of the cart?
             * if so we need to retrieve any saved data
             */
            if ($config['storage']['driver']) {
                $this->restoreCartState($cartID);
            }
            if ($config['storage']['autosave']) {
                // register shutdown function for auto save
                register_shutdown_function(array($this, 'saveCartState'), $cartID);
            }

            if ($switchContext) {
                $this->context = $cartID;
            }

            return $this->carts[$cartID];
        } else {
            throw new DuplicateCartInstanceException(sprintf('There is already a cart instance with the id: %s', $cartID));
        }
    }

    /**
     * Destroy a cart instance. If the destroyed cart instance is in the current context, the
     * current context is set to null.
     *
     * @param bool $cartID       The ID of the cart to be destroyed
     * @param bool $clearStorage Should the storage associated with the cart instance be cleared
     */
    public function destroyCart($cartID = false, $clearStorage = true)
    {
        $cartID or $cartID = $this->context;

        if ($this->cartExists($cartID)) {
            unset($this->carts[$cartID]);

            if ($clearStorage) {
                $this->clearCartState($cartID);
            }

            if ($this->context == $cartID) {
                $this->context = null;
            }
        }
    }

    /**
     * Destroy all cart instances associated with the cart manager. Also clears any saved states unless
     * false is passed.
     *
     * @param bool $clearStorage Should the storage associated with a cart instance be cleared
     */
    public function destroyAllCarts($clearStorage = true)
    {
        foreach ($this->carts as $cartID => $cart) {
            $this->destroyCart($cartID, $clearStorage);
        }
    }

    /**
     * Get the configuration options specified for a specific cart instance. If not configuration exists
     * for the requested instance, the default cart configuration is returned
     *
     * @param  string $cartID The ID of the cart instance
     * @return array  The cart configuration options
     */
    public function getCartConfig($cartID = '')
    {
        if (array_key_exists($cartID, $this->config['carts'])) {
            return $this->config['carts'][$cartID];
        } else {
            return $this->config['defaults'];
        }
    }

    /**
     * Save data associated with a cart instance to the configured storage method
     *
     * @param string $cartID ID of the cart instance
     */
    public function saveCartState($cartID)
    {
        $data = serialize($this->carts[$cartID]->export());
        $driver = $this->getCartStorageDriver($this->getCartStorageKey($cartID));
        $driver::save($this->getCartStorageKey($cartID), $data);
    }

    /**
     * Restore data from storage associated with a cart instance
     *
     * @param string $cartID ID of the cart instance
     */
    public function restoreCartState($cartID)
    {
        $driver = $this->getCartStorageDriver($cartID);

        $data = unserialize($driver::restore($this->getCartStorageKey($cartID)));
        $this->carts[$cartID]->import($data);
    }

    /**
     * Clear any saved state associated with a cart instance
     *
     * @static
     * @param string $cartID ID of the cart instance
     */
    public function clearCartState($cartID)
    {
        $driver = $this->getCartStorageDriver($cartID);
        $driver::clear($this->getCartStorageKey($cartID));
    }

    /**
     * Gets the FQN of the storage implementation associated with a cart instance. Also checks the
     * storage driver is valid
     *
     * @param  string                                                $cartID ID of the cart instance
     * @return string                                                FQN of the storage implementation
     * @throws \Cart\Exception\InvalidStorageImplementationException
     */
    public function getCartStorageDriver($cartID)
    {
        $config = $this->getCartConfig($cartID);
        $driver = $config['storage']['driver'];

        // check driver actually exists
        if ( ! class_exists($driver)) {
            throw new InvalidStorageImplementationException(sprintf('The class: %s has not been loaded.', $driver));
        }

        // check driver implements StorageInterface
        $driverInstance = new \ReflectionClass($driver);
        if ( ! $driverInstance->implementsInterface('\Cart\Storage\StorageInterface')) {
            throw new InvalidStorageImplementationException(sprintf('The class: %s does not implement \Cart\Storage\StorageInterface.', $driver));
        }

        return $driver;
    }

    /**
     * Gets the storage key associated with a cart instances. Takes into account prefix
     * and suffix set in config
     *
     * @param  string $cartID The ID of the cart instance
     * @return string The storage key associated with the cart instance
     */
    public function getCartStorageKey($cartID)
    {
        $config = $this->getCartConfig($cartID);

        $storageKey = '';

        if (array_key_exists('storage_key_prefix', $config['storage'])) {
            $storageKey .= $config['storage']['storage_key_prefix'];
        }
        $storageKey .= $cartID;

        if (array_key_exists('storage_key_suffix', $config['storage'])) {
            $storageKey .= $config['storage']['storage_key_suffix'];
        }

        return $storageKey;
    }
}
