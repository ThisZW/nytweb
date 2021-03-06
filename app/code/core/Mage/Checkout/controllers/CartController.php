<?php
/**
 * Magento
 * 
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magento.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Magento to newer
 * versions in the future. If you wish to customize Magento for your
 * needs please refer to http://www.magento.com for more information.
 *
 * @category    Mage
 * @package     Mage_Checkout
 * @copyright  Copyright (c) 2006-2016 X.commerce, Inc. and affiliates (http://www.magento.com)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/**
 * Shopping cart controller
 */
 
 // 6-14-2016 EDITED AND MODIFIED BY Chris.
class Mage_Checkout_CartController extends Mage_Core_Controller_Front_Action
{
    /**
     * Action list where need check enabled cookie
     *
     * @var array
     */
    protected $_cookieCheckActions = array('add');
	
	/**
	 * Make some protected variable for membership/pot ids.
	 * 
	 * @var int (4)
	 */
	protected $plan_5 = 41;
	protected $plan_3 = 3;
	protected $membership_id = 43;
	protected $pot_id = 44;
	 
    /**
     * Retrieve shopping cart model object
     *
     * @return Mage_Checkout_Model_Cart
     */
    protected function _getCart()
    {
        return Mage::getSingleton('checkout/cart');
    }

    /**
     * Get checkout session model instance
     *
     * @return Mage_Checkout_Model_Session
     */
    protected function _getSession()
    {
        return Mage::getSingleton('checkout/session');
    }

    /**
     * Get current active quote instance
     *
     * @return Mage_Sales_Model_Quote
     */
    protected function _getQuote()
    {
        return $this->_getCart()->getQuote();
    }

    /**
     * Set back redirect url to response
     *
     * @return Mage_Checkout_CartController
     * @throws Mage_Exception
     */
    protected function _goBack()
    {
        $returnUrl = $this->getRequest()->getParam('return_url');
        if ($returnUrl) {

            if (!$this->_isUrlInternal($returnUrl)) {
                throw new Mage_Exception('External urls redirect to "' . $returnUrl . '" denied!');
            }

            $this->_getSession()->getMessages(true);
            $this->getResponse()->setRedirect($returnUrl);
        } elseif (!Mage::getStoreConfig('checkout/cart/redirect_to_cart')
            && !$this->getRequest()->getParam('in_cart')
            && $backUrl = $this->_getRefererUrl()
        ) {
            $this->getResponse()->setRedirect($backUrl);
        } else {
            if ((strtolower($this->getRequest()->getActionName()) == 'add') && !$this->getRequest()->getParam('in_cart')) {
                $this->_getSession()->setContinueShoppingUrl($this->_getRefererUrl());
            }
            $this->_redirect('checkout/cart');
        }
        return $this;
    }

    /**
     * Initialize product instance from request data
     *
     * @return Mage_Catalog_Model_Product || false
     */
    protected function _initProduct()
    {
        $productId = (int) $this->getRequest()->getParam('product');
        if ($productId) {
            $product = Mage::getModel('catalog/product')
                ->setStoreId(Mage::app()->getStore()->getId())
                ->load($productId);
            if ($product->getId()) {
                return $product;
            }
        }
        return false;
    }

    /**
     * Predispatch: remove isMultiShipping option from quote
     *
     * @return Mage_Checkout_CartController
     */
    public function preDispatch()
    {
        parent::preDispatch();

        $cart = $this->_getCart();
        if ($cart->getQuote()->getIsMultiShipping()) {
            $cart->getQuote()->setIsMultiShipping(false);
        }

        return $this;
    }

    /**
     * Shopping cart display action
     */
    public function indexAction()
    {
        $cart = $this->_getCart();
        if ($cart->getQuote()->getItemsCount()) {
            $cart->init();
            $cart->save();

            if (!$this->_getQuote()->validateMinimumAmount()) {
                $minimumAmount = Mage::app()->getLocale()->currency(Mage::app()->getStore()->getCurrentCurrencyCode())
                    ->toCurrency(Mage::getStoreConfig('sales/minimum_order/amount'));

                $warning = Mage::getStoreConfig('sales/minimum_order/description')
                    ? Mage::getStoreConfig('sales/minimum_order/description')
                    : Mage::helper('checkout')->__('Minimum order amount is %s', $minimumAmount);

                $cart->getCheckoutSession()->addNotice($warning);
            }
        }

        // Compose array of messages to add
        $messages = array();
        foreach ($cart->getQuote()->getMessages() as $message) {
            if ($message) {
                // Escape HTML entities in quote message to prevent XSS
                $message->setCode(Mage::helper('core')->escapeHtml($message->getCode()));
                $messages[] = $message;
            }
        }
        $cart->getCheckoutSession()->addUniqueMessages($messages);

        /**
         * if customer enteres shopping cart we should mark quote
         * as modified bc he can has checkout page in another window.
         */
        $this->_getSession()->setCartWasUpdated(true);

        Varien_Profiler::start(__METHOD__ . 'cart_display');
        $this
            ->loadLayout()
            ->_initLayoutMessages('checkout/session')
            ->_initLayoutMessages('catalog/session')
            ->getLayout()->getBlock('head')->setTitle($this->__('Shopping Cart'));
        $this->renderLayout();
        Varien_Profiler::stop(__METHOD__ . 'cart_display');
    }

    /**
     * Add product to shopping cart action
     *
     * @return Mage_Core_Controller_Varien_Action
     * @throws Exception
     */
    public function addAction()
    {
        if (!$this->_validateFormKey()) {
            $this->_goBack();
            return;
        }
        $cart   = $this->_getCart();
        $params = $this->getRequest()->getParams();
        try {
            if (isset($params['qty'])) {
                $filter = new Zend_Filter_LocalizedToNormalized(
                    array('locale' => Mage::app()->getLocale()->getLocaleCode())
                );
                $params['qty'] = $filter->filter($params['qty']);
            }

            $product = $this->_initProduct();
            $related = $this->getRequest()->getParam('related_product');

            /**
             * Check product availability
             */
            if (!$product) {
                $this->_goBack();
                return;
            }

            $cart->addProduct($product, $params);
            if (!empty($related)) {
                $cart->addProductsByIds(explode(',', $related));
            }

            $cart->save();

            $this->_getSession()->setCartWasUpdated(true);

            /**
             * @todo remove wishlist observer processAddToCart
             */
            Mage::dispatchEvent('checkout_cart_add_product_complete',
                array('product' => $product, 'request' => $this->getRequest(), 'response' => $this->getResponse())
            );

            if (!$this->_getSession()->getNoCartRedirect(true)) {
                if (!$cart->getQuote()->getHasError()) {
                    $message = $this->__('%s was added to your shopping cart.', Mage::helper('core')->escapeHtml($product->getName()));
                    $this->_getSession()->addSuccess($message);
                }
                $this->_goBack();
            }
        } catch (Mage_Core_Exception $e) {
            if ($this->_getSession()->getUseNotice(true)) {
                $this->_getSession()->addNotice(Mage::helper('core')->escapeHtml($e->getMessage()));
            } else {
                $messages = array_unique(explode("\n", $e->getMessage()));
                foreach ($messages as $message) {
                    $this->_getSession()->addError(Mage::helper('core')->escapeHtml($message));
                }
            }

            $url = $this->_getSession()->getRedirectUrl(true);
            if ($url) {
                $this->getResponse()->setRedirect($url);
            } else {
                $this->_redirectReferer(Mage::helper('checkout/cart')->getCartUrl());
            }
        } catch (Exception $e) {
            $this->_getSession()->addException($e, $this->__('Cannot add the item to shopping cart.'));
            Mage::logException($e);
            $this->_goBack();
        }
    }

    /**
     * Add products in group to shopping cart action
     */
    public function addgroupAction()
    {
        $orderItemIds = $this->getRequest()->getParam('order_items', array());

        if (!is_array($orderItemIds) || !$this->_validateFormKey()) {
            $this->_goBack();
            return;
        }

        $itemsCollection = Mage::getModel('sales/order_item')
            ->getCollection()
            ->addIdFilter($orderItemIds)
            ->load();
        /* @var $itemsCollection Mage_Sales_Model_Mysql4_Order_Item_Collection */
        $cart = $this->_getCart();
        foreach ($itemsCollection as $item) {
            try {
                $cart->addOrderItem($item, 1);
            } catch (Mage_Core_Exception $e) {
                if ($this->_getSession()->getUseNotice(true)) {
                    $this->_getSession()->addNotice($e->getMessage());
                } else {
                    $this->_getSession()->addError($e->getMessage());
                }
            } catch (Exception $e) {
                $this->_getSession()->addException($e, $this->__('Cannot add the item to shopping cart.'));
                Mage::logException($e);
                $this->_goBack();
            }
        }
        $cart->save();
        $this->_getSession()->setCartWasUpdated(true);
        $this->_goBack();
    }

    /**
     * Action to reconfigure cart item
     */
    public function configureAction()
    {
        // Extract item and product to configure
        $id = (int) $this->getRequest()->getParam('id');
        $quoteItem = null;
        $cart = $this->_getCart();
        if ($id) {
            $quoteItem = $cart->getQuote()->getItemById($id);
        }

        if (!$quoteItem) {
            $this->_getSession()->addError($this->__('Quote item is not found.'));
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            $params = new Varien_Object();
            $params->setCategoryId(false);
            $params->setConfigureMode(true);
            $params->setBuyRequest($quoteItem->getBuyRequest());

            Mage::helper('catalog/product_view')->prepareAndRender($quoteItem->getProduct()->getId(), $this, $params);
        } catch (Exception $e) {
            $this->_getSession()->addError($this->__('Cannot configure product.'));
            Mage::logException($e);
            $this->_goBack();
            return;
        }
    }

    /**
     * Update product configuration for a cart item
     */
    public function updateItemOptionsAction()
    {
        $cart   = $this->_getCart();
        $id = (int) $this->getRequest()->getParam('id');
        $params = $this->getRequest()->getParams();

        if (!isset($params['options'])) {
            $params['options'] = array();
        }
        try {
            if (isset($params['qty'])) {
                $filter = new Zend_Filter_LocalizedToNormalized(
                    array('locale' => Mage::app()->getLocale()->getLocaleCode())
                );
                $params['qty'] = $filter->filter($params['qty']);
            }

            $quoteItem = $cart->getQuote()->getItemById($id);
            if (!$quoteItem) {
                Mage::throwException($this->__('Quote item is not found.'));
            }

            $item = $cart->updateItem($id, new Varien_Object($params));
            if (is_string($item)) {
                Mage::throwException($item);
            }
            if ($item->getHasError()) {
                Mage::throwException($item->getMessage());
            }

            $related = $this->getRequest()->getParam('related_product');
            if (!empty($related)) {
                $cart->addProductsByIds(explode(',', $related));
            }

            $cart->save();

            $this->_getSession()->setCartWasUpdated(true);

            Mage::dispatchEvent('checkout_cart_update_item_complete',
                array('item' => $item, 'request' => $this->getRequest(), 'response' => $this->getResponse())
            );
            if (!$this->_getSession()->getNoCartRedirect(true)) {
                if (!$cart->getQuote()->getHasError()) {
                    $message = $this->__('%s was updated in your shopping cart.', Mage::helper('core')->escapeHtml($item->getProduct()->getName()));
                    $this->_getSession()->addSuccess($message);
                }
                $this->_goBack();
            }
        } catch (Mage_Core_Exception $e) {
            if ($this->_getSession()->getUseNotice(true)) {
                $this->_getSession()->addNotice($e->getMessage());
            } else {
                $messages = array_unique(explode("\n", $e->getMessage()));
                foreach ($messages as $message) {
                    $this->_getSession()->addError($message);
                }
            }

            $url = $this->_getSession()->getRedirectUrl(true);
            if ($url) {
                $this->getResponse()->setRedirect($url);
            } else {
                $this->_redirectReferer(Mage::helper('checkout/cart')->getCartUrl());
            }
        } catch (Exception $e) {
            $this->_getSession()->addException($e, $this->__('Cannot update the item.'));
            Mage::logException($e);
            $this->_goBack();
        }
        $this->_redirect('*/*');
    }

    /**
     * Update shopping cart data action
     */
    public function updatePostAction()
    {
        if (!$this->_validateFormKey()) {
            $this->_redirect('*/*/');
            return;
        }

        $updateAction = (string)$this->getRequest()->getParam('update_cart_action');

        switch ($updateAction) {
            case 'empty_cart':
                $this->_emptyShoppingCart();
                break;
            case 'update_qty':
                $this->_updateShoppingCart();
                break;
            default:
                $this->_updateShoppingCart();
        }

        $this->_goBack();
    }

    /**
     * Update customer's shopping cart
     */
    protected function _updateShoppingCart()
    {
        try {
            $cartData = $this->getRequest()->getParam('cart');
            if (is_array($cartData)) {
                $filter = new Zend_Filter_LocalizedToNormalized(
                    array('locale' => Mage::app()->getLocale()->getLocaleCode())
                );
                foreach ($cartData as $index => $data) {
                    if (isset($data['qty'])) {
                        $cartData[$index]['qty'] = $filter->filter(trim($data['qty']));
                    }
                }
                $cart = $this->_getCart();
                if (! $cart->getCustomerSession()->getCustomer()->getId() && $cart->getQuote()->getCustomerId()) {
                    $cart->getQuote()->setCustomerId(null);
                }

                $cartData = $cart->suggestItemsQty($cartData);
                $cart->updateItems($cartData)
                    ->save();
            }
            $this->_getSession()->setCartWasUpdated(true);
        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError(Mage::helper('core')->escapeHtml($e->getMessage()));
        } catch (Exception $e) {
            $this->_getSession()->addException($e, $this->__('Cannot update shopping cart.'));
            Mage::logException($e);
        }
    }

    /**
     * Empty customer's shopping cart
     */
    protected function _emptyShoppingCart()
    {
        try {
            $this->_getCart()->truncate()->save();
            $this->_getSession()->setCartWasUpdated(true);
        } catch (Mage_Core_Exception $exception) {
            $this->_getSession()->addError($exception->getMessage());
        } catch (Exception $exception) {
            $this->_getSession()->addException($exception, $this->__('Cannot update shopping cart.'));
        }
    }

    /**
     * Delete shoping cart item action
     */
    public function deleteAction()
    {
        if ($this->_validateFormKey()) {
            $id = (int)$this->getRequest()->getParam('id');
            if ($id) {
                try {
                    $this->_getCart()->removeItem($id)
                        ->save();
                } catch (Exception $e) {
                    $this->_getSession()->addError($this->__('Cannot remove the item.'));
                    Mage::logException($e);
                }
            }
        } else {
            $this->_getSession()->addError($this->__('Cannot remove the item.'));
        }

        $this->_redirectReferer(Mage::getUrl('*/*'));
    }

    /**
     * Initialize shipping information
     */
    public function estimatePostAction()
    {
        $country    = (string) $this->getRequest()->getParam('country_id');
        $postcode   = (string) $this->getRequest()->getParam('estimate_postcode');
        $city       = (string) $this->getRequest()->getParam('estimate_city');
        $regionId   = (string) $this->getRequest()->getParam('region_id');
        $region     = (string) $this->getRequest()->getParam('region');

        $this->_getQuote()->getShippingAddress()
            ->setCountryId($country)
            ->setCity($city)
            ->setPostcode($postcode)
            ->setRegionId($regionId)
            ->setRegion($region)
            ->setCollectShippingRates(true);
        $this->_getQuote()->save();
        $this->_goBack();
    }

    /**
     * Estimate update action
     *
     * @return null
     */
    public function estimateUpdatePostAction()
    {
        $code = (string) $this->getRequest()->getParam('estimate_method');
        if (!empty($code)) {
            $this->_getQuote()->getShippingAddress()->setShippingMethod($code)/*->collectTotals()*/->save();
        }
        $this->_goBack();
    }

    /**
     * Initialize coupon
     */
    public function couponPostAction()
    {
        /**
         * No reason continue with empty shopping cart
         */
        if (!$this->_getCart()->getQuote()->getItemsCount()) {
            $this->_goBack();
            return;
        }

        $couponCode = (string) $this->getRequest()->getParam('coupon_code');
        if ($this->getRequest()->getParam('remove') == 1) {
            $couponCode = '';
        }
        $oldCouponCode = $this->_getQuote()->getCouponCode();

        if (!strlen($couponCode) && !strlen($oldCouponCode)) {
            $this->_goBack();
            return;
        }

        try {
            $codeLength = strlen($couponCode);
            $isCodeLengthValid = $codeLength && $codeLength <= Mage_Checkout_Helper_Cart::COUPON_CODE_MAX_LENGTH;

            $this->_getQuote()->getShippingAddress()->setCollectShippingRates(true);
            $this->_getQuote()->setCouponCode($isCodeLengthValid ? $couponCode : '')
                ->collectTotals()
                ->save();

            if ($codeLength) {
                if ($isCodeLengthValid && $couponCode == $this->_getQuote()->getCouponCode()) {
                    $this->_getSession()->addSuccess(
                        $this->__('Coupon code "%s" was applied.', Mage::helper('core')->escapeHtml($couponCode))
                    );
                } else {
                    $this->_getSession()->addError(
                        $this->__('Coupon code "%s" is not valid.', Mage::helper('core')->escapeHtml($couponCode))
                    );
                }
            } else {
                $this->_getSession()->addSuccess($this->__('Coupon code was canceled.'));
            }

        } catch (Mage_Core_Exception $e) {
            $this->_getSession()->addError($e->getMessage());
        } catch (Exception $e) {
            $this->_getSession()->addError($this->__('Cannot apply the coupon code.'));
            Mage::logException($e);
        }

        $this->_goBack();
    }

    /**
     * Minicart delete action
     */
    public function ajaxDeleteAction()
    {
        if (!$this->_validateFormKey()) {
            Mage::throwException('Invalid form key');
        }
        $id = (int) $this->getRequest()->getParam('id');
        $result = array();
        if ($id) {
            try {
                $this->_getCart()->removeItem($id)->save();

                $result['qty'] = $this->_getCart()->getSummaryQty();

                $this->loadLayout();
                $result['content'] = $this->getLayout()->getBlock('minicart_content')->toHtml();

                $result['success'] = 1;
                $result['message'] = $this->__('Item was removed successfully.');
                Mage::dispatchEvent('ajax_cart_remove_item_success', array('id' => $id));
            } catch (Exception $e) {
                $result['success'] = 0;
                $result['error'] = $this->__('Can not remove the item.');
            }
        }

        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
    }

    /**
     * Minicart ajax update qty action
     */
    public function ajaxUpdateAction()
    {
        if (!$this->_validateFormKey()) {
            Mage::throwException('Invalid form key');
        }
        $id = (int)$this->getRequest()->getParam('id');
        $qty = $this->getRequest()->getParam('qty');
        $result = array();
        if ($id) {
            try {
                $cart = $this->_getCart();
                if (isset($qty)) {
                    $filter = new Zend_Filter_LocalizedToNormalized(
                        array('locale' => Mage::app()->getLocale()->getLocaleCode())
                    );
                    $qty = $filter->filter($qty);
                }

                $quoteItem = $cart->getQuote()->getItemById($id);
                if (!$quoteItem) {
                    Mage::throwException($this->__('Quote item is not found.'));
                }
                if ($qty == 0) {
                    $cart->removeItem($id);
                } else {
                    $quoteItem->setQty($qty)->save();
                }
                $this->_getCart()->save();

                $this->loadLayout();
                $result['content'] = $this->getLayout()->getBlock('minicart_content')->toHtml();

                $result['qty'] = $this->_getCart()->getSummaryQty();

                if (!$quoteItem->getHasError()) {
                    $result['message'] = $this->__('Item was updated successfully.');
                } else {
                    $result['notice'] = $quoteItem->getMessage();
                }
                $result['success'] = 1;
            } catch (Exception $e) {
                $result['success'] = 0;
                $result['error'] = $this->__('Can not save item.');
            }
        }

        $this->getResponse()->setHeader('Content-type', 'application/json');
        $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
    }
	
	/*7-5-2016 by Chris
		
	*/
	public function startupPageSubmitAction(){
		//cart object
		$cart   = $this->_getCart()->truncate();
		//die;
		$customer = Mage::getSingleton('customer/session')->getCustomer();
		//get cart obj
		

		$subscription_plan = $_POST["subscription-plans"];
		//var_dump($subscription_plan);
		switch($subscription_plan){
			case '5-per-week' :
				$plan_id = $this->plan_5;
				$limit = 5;
				break;
			case '3-per-week' :
				$plan_id = $this->plan_3;
				$limit = 3;
				break;
		}
		//echo $plan_id;
		$membership_or_pot = $_POST["membership-or-pot"];
		switch($membership_or_pot){
			case 'membership' :
				$mem_id = $this->membership_id;
				break;
			case 'pot' :
				$mem_id = $this->pot_id;
				break;
		}
		
		$customer_preference = $_POST["customer-preference"];
		//print_r ($customer_preference);
		//die;
		$array_pref = array();
		foreach($customer_preference as $pref){
			$customer->setData($pref, 1)->save();
			array_push($array_pref, $pref);
		}
		$get_pref = 'none';
		if($customer_preference){
			$get_pref = implode(',',$array_pref);
		}
		$ids = array();
		
		array_push($ids, $mem_id, $plan_id);
		
		//$cart->addProductsByIds(array($plan_id,$mem_id))->save();
		/*$meals_id = $_POST["meals"];
		$counter = 0;
		foreach($meals_id as $meal_id){
			$meals_qty = $_POST["qty-selector-" . $meal_id];
			$counter += $meals_qty;
			print_r($meals_qty);
			for($x = 0; $x < $meals_qty; $x++ ){
				array_push($ids, $meal_id);
			}
		}*/
		//echo $get_pref;
		//die;
		if($mem_id && $plan_id){
			$cart->addProductsByIds(array_reverse($ids))->save();
			$this->_redirectUrl('/menu.html?pref='. $get_pref);
		} else {
			$this->_getSession()->addError('error');
			$this->_goBack();
		}
	
	}
	
	
	 public function addCustomOptionsActionDisabled(){ //won't be called from frontend
		

		$category = Mage::getModel('catalog/category')->load(3);
		$collection = $category->getProductCollection();
		//print_r($collection);
		foreach($collection as $c){ 
			$p_id = $c->getId();
			$p = Mage::getModel('catalog/product')->load($p_id);
			//print_r($p->getData());
			$customOption = array(
				'title' => 'Side Orders',
				'type' => 'checkbox',
				'is_require' => 0,
				'sort_order' => 0,
				'values' =>array(
					array(
						'title' => 'Grits',
						'price' => 1,
						'price_type' => 'fixed',
						'sort_order' => '1',
					),
					array(
						'title' => 'Spaghetti',
						'price' => 1,
						'price_type' => 'fixed',
						'sort_order' => '1',
					),
				),
			);
			
			//foreach($p->getOptions() as $o){
			//	$o->delete();
			//}
			//$p->setHasOptions(0)->save();
		 $p->setHasOptions(1);
			$instance = $p->getOptionInstance()->unsetOptions();
			$instance->addOption($customOption);
			$instance->setProduct($p);
			$p->save();  
			echo 'done ' . $p_id .' <br>'; 
		}
	} 
	
	public function testActionDisabled(){ //won't be called from frontend
		 //customer collection object
		
		$customer_collection = Mage::getModel('customer/customer')->getCollection()->addAttributeToSelect('*')->addFieldToFilter('group_id',4);

		/*foreach ($customer_collection as $customer){
			$email = $customer->getEmail();//generate a random token
			$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			$randstring = '';
			for ($i = 0; $i < 30; $i++) {
				$randstring = $randstring . $characters[rand(0, strlen($characters))];
			}
			
			$customer->setData('customer_login_token',$randstring)->save();
			
			//Email body generated by function below
			$body = $this->generateBodyHtml($customer, $randstring);
			print_r($body);
			//email object
			$email_model = Mage::getModel('core/email');
			
			
			$name = $customer->getName();
			
			$email_model->setSenderName('Abrameals')
						->setToName($name)
						->setToEmail($email)
						->setFromEmail('admin@abrameals.com')
						->setSubject('Subject here......');
			$email_model->setBody($body)
						->setType('html')
						->send();  

		}*/
		foreach ($customer_collection as $customer){
			$email = $customer->getEmail();//generate a random token
			$characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
			$randstring = '';
			for ($i = 0; $i < 30; $i++) {
				$randstring = $randstring . $characters[rand(0, strlen($characters))];
			}
			
			$customer->setData('customer_login_token',$randstring)->save();
			
			//Email body generated by function below
			$body = $this->generateBodyHtml($customer, $randstring);
			//print_r($body);
			//email object
			//$email_model = Mage::getModel('core/email');
			$email_queue_model = Mage::getModel('core/email_queue');//->getQueue();
			print_r ($email_queue_model);	
				//$email_queue_model = setMessageBody($body);
			/*$email_queue->setMessageParameters(array(
				'subject'			=> 'Hi, these are your next week\'s meals' , 
				'is_plain'			=> 
			
			
			))*/
			print_r($email_queue_model);
			/*$name = $customer->getName();
			
			$email_model->setSenderName('Abrameals')
						->setToName($name)
						->setToEmail($email)
						->setFromEmail('admin@abrameals.com')
						->setSubject('Subject here......');
			$email_model->setBody($body)
						->setType('html')
						->send();  
*/
		}
		 
		 return 0;
	}
	 
	public function generateBodyHtml($customer , $key){ 
		
		$email = $customer->getEmail();
		$token = $customer->getData('customer_login_token');

		$head = <<<HTML
		<table align="center">
		<tbody><tr><td>
		<div class="background">
			<table border="0" style="align:center" cellpadding="0" cellspacing="0" background="http://www.abrameals.com/media/email/bg.jpg" width="600"  id="bodyTable">
				<tr>
				<td align="center" valign="top">
				<table border="0" cellpadding="" cellspacing="0" width="" id="emailContainer">
                <tr>
					<td class="header-content" height="80px" colspan="3">
						<div style="text-align:center">
							<img src="http://abrameals.com/media/email/abra_logo.png"/>
						</div>
					</td>
				</tr>
				<tr>
					<td height="60px" colspan="3">
						<div style="text-align:center">
							<img src="http://abrameals.com/media/email/abrameal_0000_recommend_360.png" />					
						</div>
					</td>
				</tr>
				<td>
				<td>
                    
HTML;

		$foot = <<<HTML
				<tr>
				<td>&nbsp;<br>&nbsp;
				</td>
                </tr>
				</tr>
            </table>
        </td>
    </tr>
</table>
</table>
</div>
</div>
</td></tr></tbody></table>



HTML;

		$body_upper = <<<HTML
		
		<tr>
		
HTML;
		$body_lower = <<<HTML
		</tr>
		<tr>
		<td align="center" colspan="3" width="100%">
			<a style="text-decoration:none; text-align:center" href="http://www.abrameals.com/customer/account/loginFromEmail?username={$email}&token={$token}&redirect_method=order_history">
			<div style="Color: white;
						display: inline-block;
						margin-top: 20px;
						padding: 10px;
						border: 2px solid green;
						border-radius: 6px;
						background: #2b7927;"> 
				Review Your Meals
			</div>
			</a>
		</td>
		</tr>

HTML;
		
		$css  = <<<HTML
<style>
.background{
	background:url("http://www.abrameals.com/media/email/bg.jpg") no-repeat top center;
	width:600px;
	align:center;
}
.p-name{
	font-size:10px;
	height:30px;
	overflow:hidden;
}
a{
	text-decoration:none;
}
.p-name{
	color:black;
}






</style>
HTML;

		
		//echo $customer->getId();
		$orders = Mage::getModel("sales/order")->getCollection()
                       ->addAttributeToSelect('*')
                       ->addFieldToFilter('customer_id', $customer->getId());
		$count = 0;
		$item_html = '';
		
		foreach($orders as $order){
			$items = $order->getAllVisibleItems();
			foreach ($items as $item):
			//	print_r($item->getData());
				$id = $item->getProductId();
				$i  = Mage::getModel('catalog/product')->load($id);
				$data = array(
					'img' => $i->getImageUrl(),
					'name' => $i ->getName(),
					'category' => $i ->getCategoryIds(),
					'url' => $i->getProductUrl()
				);
				//var_dump($data['url']);
				//print_r($data['category']);
				if(in_array(3 ,$data['category'])){
					if($count < 3 ){
						$count++;
						$item_html = $item_html .
						"<td width=\"33%\" align=\"center\">
						<a style=\"text-decoration:none\" href=\"".$data['url']."\"><img style=\"width:155px\" align=\"center\" src=\" " .  $data['img'] . "\" />
						<p style=\"font-size:12px; color:black;; padding: 0 20px; \" class=\"p-name\"> " . $data['name'] . "</p></a>
						</td>";
					}	
					//echo $body;
				}
			endforeach;
			//die;
		}
		
		$item_html2 = '';
		$count2 = 0;
		$item_skus = Mage::getStoreConfig('suggestmealsforemail_options/section_one/custom_field_one', Mage::app()->getStore());
		$skus = explode(",",$item_skus);
		$suggested_ids = array();
		
		$preference_list = array(
			'preference_all_veggies',
			'preference_no_beef',
			'preference_no_fish',
			'preference_no_lamb',
			'preference_no_pork',
			'preference_no_poultry',
			'preference_no_shrimp'
		);
			
		$prof = Mage::getModel('sales/recurring_profile')->getCollection()
            ->addFieldToFilter('customer_id', $customer->getId())->addFieldToFilter('state','active')
            ->setOrder('profile_id', 'desc');
			foreach ($prof as $p){
				$p_limit = $p->getSubWeeklyLimit();
				break;
			}
			
		foreach ($skus as $sku){
			$suggested_product = Mage::getModel("catalog/product")->loadByAttribute('sku', $sku);

			$data = array(
				'img' => $suggested_product->getImageUrl(),
				'name' => $suggested_product ->getName(),
				'category' => $suggested_product ->getCategoryIds(),
				'url' => $suggested_product ->getProductUrl(),
				'id' => $suggested_product ->getId(),
			);
			$c_pref = array();
			
			$validate = true;
			
			foreach ($preference_list as $pref){
				if ($customer->getData($pref) == 1){
					if($suggested_product->getData($pref) == 1){
						$validate = false;
						echo '2';
					}
				}
			}
			
			if ($validate == true){
				if($count2 < $p_limit ){
					$count2++;
					$item_html2 = $item_html2 .
					"<td width=\"33%\" align=\"center\">
					<a style=\"text-decoration:none\" href=\"".$data['url']."\"> <img style=\"width:155px\" src=\" " .  $data['img'] . "\" />
					<p style=\"color:black; font-size:12px; padding: 0 20px; \" class=\"p-name\"> " . $data['name'] . "</p></a>
					</td>";
				}
				
				if( $count2 % 3 == 0 ){
					$item_html2 = $item_html2 . "</tr><tr>";
				}
				
			}
		}
		
		$suggested_ids_string = implode ("," ,$suggested_ids);
		//Filter by preference ..
		
		$content_between_items = <<<HTML
		</tr>		<tr>
		<td align="center" colspan="3" width="100%">
			<table>
				<tr>
					<td>
					<a style="text-decoration:none" href="http://www.abrameals.com/customer/account/loginFromEmail?username={$email}&token={$token}&redirect_method=menu_add_suggested_plans&suggested_plans={$suggested_ids_string}">
					<div style="Color: white;
						display: inline-block;
						margin-top: 20px;
						padding: 10px;
						border: 2px solid green;
						border-radius: 6px;
						background: #2b7927;" >Add to your plan:</div>
					</td>
					<td>
					<a style="text-decoration:none" href="http://www.abrameals.com/customer/account/loginFromEmail?username={$email}&token={$token}&redirect_method=freeze_subscription">
					<div style="Color: white;
						display: inline-block;
						margin-top: 20px;
						padding: 10px;
						border: 2px solid green;
						border-radius: 6px;
						background: #2b7927;" >Freeze for next week.</div>
					</td>
				</tr>
			</table>
		<td>
		</tr> 
		
		<tr><td colspan="3" style="text-align:center; height:80px" >
			<img src="http://www.abrameals.com/media/email/abrameal_0001_meals_360.png"/>
		</td></tr>
		<tr>
HTML;
		//echo 'test';
		//print_r($item_html2);
		//echo 'test end';
		
		return $head.$body_upper.$item_html2.$content_between_items.$item_html.$body_lower.$foot.$css;
	}

}
