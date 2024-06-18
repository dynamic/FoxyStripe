<?php

namespace Dynamic\FoxyStripe\Controller;

use Dynamic\FoxyStripe\Model\FoxyCart;
use Dynamic\FoxyStripe\Model\FoxyStripeSetting;
use Dynamic\FoxyStripe\Model\OptionItem;
use Dynamic\FoxyStripe\Model\Order;
use Dynamic\FoxyStripe\Model\OrderDetail;
use Dynamic\FoxyStripe\Page\ProductPage;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

class FoxyStripeController extends \PageController
{
    /**
     *
     */
    const URLSEGMENT = 'foxystripe';
    /**
     * @var array
     */
    private static $allowed_actions = [
        'index',
        'sso',
    ];

    /**
     * @return string
     */
    public function getURLSegment()
    {
        return self::URLSEGMENT;
    }

    /**
     * @return string
     *
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function index()
    {
        // handle POST from FoxyCart API transaction
        if ((isset($_POST['FoxyData']) or isset($_POST['FoxySubscriptionData']))) {
            $FoxyData_encrypted = (isset($_POST['FoxyData'])) ?
                urldecode($_POST['FoxyData']) :
                urldecode($_POST['FoxySubscriptionData']);
            $FoxyData_decrypted = \rc4crypt::decrypt(FoxyCart::getStoreKey(), $FoxyData_encrypted);

            // parse the response and save the order
            self::handleDataFeed($FoxyData_encrypted, $FoxyData_decrypted);

            // extend to allow for additional integrations with Datafeed
            $this->extend('addIntegrations', $FoxyData_encrypted);

            return 'foxy';
        } else {
            return 'No FoxyData or FoxySubscriptionData received.';
        }
    }

    /**
     * @param $encrypted
     * @param $decrypted
     *
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function handleDataFeed($encrypted, $decrypted)
    {
        $orders = new \SimpleXMLElement($decrypted);

        // loop over each transaction to find FoxyCart Order ID
        foreach ($orders->transactions->transaction as $transaction) {
            // if FoxyCart order id, then parse order
            if (isset($transaction->id)) {
                $order = Order::get()->filter('Order_ID', (int)$transaction->id)->First();
                if (!$order) {
                    $order = Order::create();
                }

                // save base order info
                $order->Order_ID = (int)$transaction->id;
                $order->Response = urlencode($encrypted);
                // first write needed otherwise it creates a duplicates
                $order->write();
                $this->parseOrder($orders, $order);
                $order->write();
            }
        }
    }

    /**
     * @param \SimpleXMLElement $transactions
     * @param Order $order
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function parseOrder($transactions, $order)
    {
        $this->parseOrderInfo($transactions, $order);
        $this->parseOrderCustomer($transactions, $order);
        $this->parseOrderDetails($transactions, $order);
        $this->extend('updateParseOrder', $transactions, $order);
    }

    /**
     * @param \SimpleXMLElement $orders
     * @param Order $transaction
     */
    public function parseOrderInfo($orders, $transaction)
    {
        foreach ($orders->transactions->transaction as $order) {
            // Record transaction data from FoxyCart Datafeed:
            $transaction->Store_ID = (int)$order->store_id;
            $transaction->TransactionDate = (string)$order->transaction_date;
            $transaction->ProductTotal = (float)$order->product_total;
            $transaction->TaxTotal = (float)$order->tax_total;
            $transaction->ShippingTotal = (float)$order->shipping_total;
            $transaction->OrderTotal = (float)$order->order_total;
            $transaction->ReceiptURL = (string)$order->receipt_url;
            $transaction->OrderStatus = (string)$order->status;
        }
    }

    /**
     * @param \SimpleXMLElement $orders
     * @param Order $transaction
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function parseOrderCustomer(&$orders, &$transaction)
    {
        foreach ($orders->transactions->transaction as $order) {
            if (!isset($order->customer_email) || $order->is_anonymous != 0) {
                continue;
            }

            // if Customer is existing member, associate with current order
            if (Member::get()->filter('Email', $order->customer_email)->first()) {
                $customer = Member::get()->filter('Email', $order->customer_email)->First();
                /* todo: make sure local password is updated if changed on FoxyCart
                $this->updatePasswordFromData($customer, $order);
                */
            } else {
                // create new Member, set password info from FoxyCart
                $customer = Member::create();
                $customer->Customer_ID = (int)$order->customer_id;
                $customer->FirstName = (string)$order->customer_first_name;
                $customer->Surname = (string)$order->customer_last_name;
                $customer->Email = (string)$order->customer_email;
            }

            $this->updatePasswordFromData($customer, $order);

            $customer->write();
            // set Order MemberID
            $transaction->MemberID = $customer->ID;
        }
    }

    /**
     * Updates a customer's password. Sets password encryption to 'none' to avoid encryting it again.
     *
     * @param Member $customer
     * @param $order
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function updatePasswordFromData(&$customer, &$order)
    {
        $password_encryption_algorithm = Security::config()->get('password_encryption_algorithm');
        Security::config()->update('password_encryption_algorithm', 'none');

        $customer->PasswordEncryption = 'none';
        $customer->Password = (string)$order->customer_password;
        $customer->write();

        $customer->PasswordEncryption = 'sha1_v2.4';
        $customer->Salt = (string)$order->customer_password_salt;
        $customer->write();

        Security::config()->update('password_encryption_algorithm', $password_encryption_algorithm);
    }

    /**
     * @param string $hashType
     * @return string
     */
    private function getEncryption($hashType)
    {
        // TODO - update this with new/correct types
        switch (true) {
            case stristr($hashType, 'sha1'):
                return 'sha1_v2.4';
            case stristr($hashType, 'sha256'):
                return 'sha256';
            case stristr($hashType, 'md5'):
                return 'md5';
            case stristr($hashType, 'bcrypt'):
                return 'bcrypt';
            default:
                return 'none';
        }
    }

    /**
     * @param \SimpleXMLElement $orders
     * @param Order $transaction
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function parseOrderDetails($orders, $transaction)
    {
        // remove previous OrderDetails so we don't end up with duplicates
        foreach ($transaction->Details() as $detail) {
            $detail->delete();
        }

        foreach ($orders->transactions->transaction as $order) {
            // Associate ProductPages, Options, Quanity with Order
            foreach ($order->transaction_details->transaction_detail as $product) {
                $this->orderDetailFromProduct($product, $transaction);
            }
        }
    }

    /**
     * @param $product
     * @param $transaction
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function orderDetailFromProduct($product, $transaction)
    {
        $OrderDetail = OrderDetail::create();
        $OrderDetail->Quantity = (int)$product->product_quantity;
        $OrderDetail->Price = (float)$product->product_price;
        // Find product via product_id custom variable

        foreach ($this->getTransactionOptions($product) as $productID) {
            $productPage = $this->getProductPage($product);
            $this->modifyOrderDetailPrice($productPage, $OrderDetail, $product);
            // associate with this order
            $OrderDetail->OrderID = $transaction->ID;
            // extend OrderDetail parsing, allowing for recording custom fields from FoxyCart
            $this->extend('handleOrderItem', $decrypted, $product, $OrderDetail);
            // write
            $OrderDetail->write();
        }
    }

    /**
     * @param $product
     * @return \Generator
     */
    public function getTransactionOptions($product)
    {
        foreach ($product->transaction_detail_options->transaction_detail_option as $productOption) {
            yield $productOption;
        }
    }

    /**
     * @param $product
     * @return \SilverStripe\ORM\DataObject
     */
    public function getProductPage($product)
    {
        foreach ($this->getTransactionOptions($product) as $productOptions) {
            if ($productOptions->product_option_name != 'product_id') {
                continue;
            }

            return ProductPage::get()
                ->filter('ID', (int)$productOptions->product_option_value)
                ->first();
        }
    }

    /**
     * @param bool|ProductPage $OrderProduct
     * @param OrderDetail $OrderDetail
     */
    public function modifyOrderDetailPrice($OrderProduct, $OrderDetail, $product)
    {
        if (!$OrderProduct) {
            return;
        }

        $OrderDetail->ProductID = $OrderProduct->ID;

        foreach ($this->getTransactionOptions($product) as $option) {
            $OptionItem = OptionItem::get()->filter([
                'ProductID' => (string)$OrderProduct->ID,
                'Title' => (string)$option->product_option_value,
            ])->first();

            if (!$OptionItem) {
                continue;
            }

            $OrderDetail->OptionItems()->add($OptionItem);
            // modify product price
            if ($priceMod = $option->price_mod) {
                $OrderDetail->Price += $priceMod;
            }
        }
    }

    /**
     * Single Sign on integration with FoxyCart.
     */
    public function sso()
    {

        // GET variables from FoxyCart Request
        $fcsid = $this->request->getVar('fcsid');
        $timestampNew = strtotime('+30 days');

        // get current member if logged in. If not, create a 'fake' user with Customer_ID = 0
        // fake user will redirect to FC checkout, ask customer to log in
        // to do: consider a login/registration form here if not logged in
        if ($Member = Security::getCurrentUser()) {
            $Member = Security::getCurrentUser();
        } else {
            $Member = new Member();
            $Member->Customer_ID = 0;
        }

        $auth_token = sha1($Member->Customer_ID . '|' . $timestampNew . '|' . FoxyCart::getStoreKey());

        $config = FoxyStripeSetting::current_foxystripe_setting();
        if ($config->CustomSSL) {
            $link = FoxyCart::getFoxyCartStoreName();
        } else {
            $link = FoxyCart::getFoxyCartStoreName() . '.foxycart.com';
        }

        $redirect_complete = 'https://' . $link . '/checkout?fc_auth_token=' . $auth_token . '&fcsid=' . $fcsid .
            '&fc_customer_id=' . $Member->Customer_ID . '&timestamp=' . $timestampNew;

        $this->redirect($redirect_complete);
    }
}
